#!/usr/bin/env python3
"""Synthetic MySQL 8.4 backup, native restore and post-backup binlog recovery.

Runs on the owned Linux Docker host. Source images are reused, source volumes are never mounted.
The app/database arguments identify images only; all DML goes to newly created isolated containers.
"""
import argparse
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import time
from types import SimpleNamespace

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--tool', type=Path, required=True)
parser.add_argument('--helper', type=Path, required=True)
parser.add_argument('--app-container', required=True)
parser.add_argument('--db-container', required=True)
parser.add_argument('--out', type=Path, required=True)
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('tahsin_safety', args.tool)
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)
root = tool.new_output(args.out)
app_image = tool.inspect(args.app_container)
db_image = tool.inspect(args.db_container)
token = secrets.token_hex(8)
source, app = 'tahsin-test-db-' + token, 'tahsin-test-php-' + token
password = secrets.token_hex(24)
secret = root / 'root-password'
secret.write_text(password)
data = root / 'source-data'
data.mkdir(mode=0o700)
env = root / 'app.env'
env.write_text('DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=tahsin_test_mysql_safety\nDB_USERNAME=root\nDB_PASSWORD=' + password + '\n')
checks = []
created, config, recovery, recovery_config = [], None, None, None


def check(value, name):
    if not value:
        raise RuntimeError(name)
    checks.append(name)


def ready(container, client_config):
    for attempt in range(90):
        try:
            return tool.query(container, client_config, 'SELECT VERSION()')
        except Exception:
            if attempt == 89:
                raise
            time.sleep(1)


try:
    tool.run(['docker', 'run', '-d', '--name', source, '--network', 'none', '--memory', '512m', '--cpus', '0.5',
        '--mount', 'type=bind,src=' + str(data) + ',dst=/var/lib/mysql',
        '--mount', 'type=bind,src=' + str(secret) + ',dst=/run/secrets/password,readonly',
        '-e', 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets/password', db_image['Image'],
        '--event-scheduler=OFF', '--innodb-buffer-pool-size=96M'])
    created.append(source)
    config = tool.native_config(source, password)
    version = ready(source, config)
    check(version.startswith('8.4.'), 'Matching MySQL 8.4 native server starts in isolated source')
    source_info = tool.inspect(source)
    original_mounts = {m['Source'] for m in db_image['Mounts']} | {m['Source'] for m in app_image['Mounts']}
    check(source_info['HostConfig']['NetworkMode'] == 'none' and not source_info['HostConfig'].get('PortBindings') and not any(m['Source'] in original_mounts for m in source_info['Mounts']), 'Synthetic writes cannot reach production volumes or public ports')
    tool.run(['docker', 'run', '-d', '--name', app, '--network', 'container:' + source, '--memory', '128m', '--cpus', '0.25',
        '--env-file', str(env), '--entrypoint', 'sleep', app_image['Image'], 'infinity'])
    created.append(app)
    schema = '''CREATE DATABASE tahsin_test_mysql_safety CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE tahsin_test_mysql_safety;
CREATE TABLE entries(id INT AUTO_INCREMENT PRIMARY KEY,note TEXT NULL,code VARCHAR(20),raw_bytes BLOB,precise DATETIME(6)) ENGINE=InnoDB;
CREATE TABLE history(id INT AUTO_INCREMENT PRIMARY KEY,entry_id INT,verb VARCHAR(10)) ENGINE=InnoDB;
CREATE TABLE drafts(user_id INT,operation_id VARCHAR(80),payload TEXT,PRIMARY KEY(user_id,operation_id)) ENGINE=InnoDB;
CREATE TRIGGER entries_ai AFTER INSERT ON entries FOR EACH ROW INSERT INTO history(entry_id,verb) VALUES(NEW.id,'insert');
CREATE TRIGGER entries_au AFTER UPDATE ON entries FOR EACH ROW INSERT INTO history(entry_id,verb) VALUES(NEW.id,'update');
CREATE FUNCTION test_label(v INT) RETURNS VARCHAR(40) DETERMINISTIC NO SQL RETURN CONCAT('test-',v);
CREATE PROCEDURE test_read() READS SQL DATA SELECT COUNT(*) FROM entries;
CREATE VIEW test_view AS SELECT id,test_label(id) AS label FROM entries;
CREATE EVENT disabled_test ON SCHEDULE EVERY 1 DAY DISABLE DO UPDATE entries SET note='must not run';
INSERT INTO entries VALUES(1,NULL,'001234',X'0001ff80','2026-10-18 08:42:00.123456'),(2,'','001235',X'ff00','2026-10-18 08:42:00.000001');
INSERT INTO drafts VALUES(2,'immutable-operation-001','{"status":"H","complete":false}');'''
    tool.native(source, config, 'mysql', '--default-character-set=utf8mb4', data=schema.encode())
    backup_dir = tool.new_output(root / 'backup')
    result = tool.backup(SimpleNamespace(lock_writes=True, app_container=app, db_container=source, helper=args.helper.resolve()), backup_dir)
    manifest = tool.read_package(backup_dir)
    check(result['ok'] and len(manifest['baseline']['tables']) == 3 and len(manifest['baseline']['objects']) == 6, 'Frozen snapshot includes all tables, compound keys, views, triggers, routines and event')
    check(tool.query(source, config, 'SELECT COUNT(*) FROM information_schema.processlist WHERE STATE LIKE "%global read lock%"') == '0', 'Global backup lock is released')
    tool.native(source, config, 'mysql', '--default-character-set=utf8mb4', data=b"USE tahsin_test_mysql_safety; UPDATE entries SET note='acknowledged after backup' WHERE id=1; INSERT INTO entries VALUES(3,'new','000003',X'00ff','2026-10-18 09:01:00.999999'); DELETE FROM entries WHERE id=2; INSERT INTO drafts VALUES(2,'immutable-operation-002','server committed response lost');")
    # Force rotation to verify a transaction chain spanning multiple native log files.
    tool.query(source, config, 'FLUSH BINARY LOGS')
    tool.native(source, config, 'mysql', data=b"USE tahsin_test_mysql_safety; UPDATE entries SET note='latest after rotation' WHERE id=3;")
    end = tool.position(source, config)
    logs = [line.split('\t')[0] for line in tool.query(source, config, 'SHOW BINARY LOGS').splitlines()]
    first, last = logs.index(manifest['position']['file']), logs.index(end['file'])
    delta = root / 'delta.sql'
    with delta.open('xb') as output:
        tool.native(source, config, 'mysqlbinlog', '--read-from-remote-server', '--database=tahsin_test_mysql_safety',
            '--start-position=' + str(manifest['position']['position']), '--stop-position=' + str(end['position']),
            *logs[first:last+1], output=output)
    check(first < last and delta.stat().st_size > 0, 'Native ROW binlog exported across a rotated log boundary')
    expected_dir = tool.new_output(root / 'expected-after')
    expected = tool.app_snapshot(app, args.helper.resolve(), expected_dir)['baseline']
    restore_dir = tool.new_output(root / 'restore')
    restore_result = tool.restore(SimpleNamespace(backup=backup_dir, helper=args.helper.resolve()), restore_dir)
    check(restore_result['ok'], 'Native full restore matches every original row hash and object definition')
    restored = json.loads((restore_dir / 'result.json').read_text())
    recovery = restored['target']
    tool.run(['docker', 'start', recovery])
    recovery_password = (restore_dir / 'recovery-password').read_text()
    recovery_config = tool.native_config(recovery, recovery_password)
    ready(recovery, recovery_config)
    check(tool.query(recovery, recovery_config, 'SELECT COUNT(*) FROM tahsin_test_mysql_safety.history') == '2', 'Restoring table data does not fire history triggers again')
    with delta.open('rb') as handle:
        result = subprocess.run(['docker','exec','-i',recovery,'mysql','--defaults-file='+recovery_config,'--binary-mode','tahsin_test_mysql_safety'],stdin=handle,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45)
        if result.returncode:
            (root / 'replay.stderr').write_bytes(result.stderr)
            raise RuntimeError('Native replay failed; private evidence retained')
    check(tool.query(recovery, recovery_config, 'SELECT COUNT(*) FROM tahsin_test_mysql_safety.drafts') == '2', 'Both pre-backup and post-backup immutable receipts recovered')
    check(tool.query(recovery, recovery_config, 'SELECT COUNT(*) FROM tahsin_test_mysql_safety.history') == '5', 'ROW replay does not fire triggers twice')
    check(tool.query(recovery, recovery_config, 'SELECT note FROM tahsin_test_mysql_safety.entries WHERE id=3') == 'latest after rotation', 'Last acknowledged update after log rotation recovered')
    # Compare the complete final snapshot using the same PDO canonicalization.
    verify_env = root / 'verify.env'
    verify_env.write_text(env.read_text().replace(password, recovery_password))
    verify_app = 'tahsin-test-verify-' + token
    tool.run(['docker','run','-d','--name',verify_app,'--network','container:'+recovery,'--env-file',str(verify_env),'--memory','128m','--entrypoint','sleep',app_image['Image'],'infinity'])
    created.append(verify_app)
    actual_dir = tool.new_output(root / 'actual-after')
    actual = tool.app_snapshot(verify_app, args.helper.resolve(), actual_dir)['baseline']
    check(expected == actual, 'Every final row, revision-like receipt, next ID and object matches after replay')
    tool.write_json(root / 'result.json', {'ok': True, 'checks': checks, 'version': version, 'backup_manifest_sha256': tool.digest(backup_dir/'manifest.json')})
    print(json.dumps({'ok': True, 'checks': len(checks), 'evidence': str(root/'result.json')}))
finally:
    if recovery_config:
        tool.run(['docker','exec',recovery,'rm','--',recovery_config])
    if config:
        tool.run(['docker','exec',source,'rm','--',config])
    if recovery:
        tool.run(['docker','stop','--time','15',recovery],timeout=25)
    for container in reversed(created):
        tool.run(['docker','stop','--time','15',container],timeout=25)
