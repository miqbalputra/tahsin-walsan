#!/usr/bin/env python3
"""Host-side Docker recovery drill. Uses existing PHP PDO + the matching native DB client.

No production restore, port publication, Docker socket mount, new DB grants or automatic deploy.
Run on the Linux Docker host; output and helper must be private local paths.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import subprocess
import sys
import tempfile
import threading
import time

FORMAT = 'tahsin-docker-recovery-v1'
os.umask(0o077)


def run(args, *, data=None, output=None, timeout=45):
    result = subprocess.run(args, input=data, stdout=output or subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    if result.returncode:
        if output:
            Path(str(output.name) + '.stderr').write_bytes(result.stderr)
        raise RuntimeError('Native command failed (exit ' + str(result.returncode) + '); private output retained when available. No cutover performed.')
    return result.stdout


def inspect(name):
    if not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}', name):
        raise RuntimeError('Invalid container name')
    item = json.loads(run(['docker', 'inspect', name]))[0]
    if not item['State']['Running']:
        raise RuntimeError('Container must already be running')
    return item


def identity(item):
    return {k: item[k] for k in ('Id', 'Name', 'Image')} | {'mounts': [
        {k: m.get(k) for k in ('Type', 'Name', 'Source', 'Destination', 'RW')} for m in item.get('Mounts', [])]}


def write_json(path, value):
    with path.open('x', encoding='utf-8') as handle:
        json.dump(value, handle, ensure_ascii=False, indent=2)
        handle.write('\n')


def digest(path):
    with path.open('rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()


def new_output(path):
    path = path.expanduser().absolute()
    if not path.parent.is_dir() or path.exists() or path.is_symlink():
        raise RuntimeError('Output must be a new directory under an existing private parent')
    path.mkdir(mode=0o700)
    return path.resolve()


def db_environment(item):
    return dict(e.split('=', 1) for e in item['Config'].get('Env', []) if '=' in e)


def native_config(container, password):
    path = '/tmp/tahsin-client-' + secrets.token_hex(12) + '.cnf'
    escaped = password.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r')
    config = ('[client]\nuser=root\npassword="' + escaped + '"\n').encode()
    run(['docker', 'exec', '-i', container, 'sh', '-c', 'umask 077; cat > "$1"', 'sh', path], data=config)
    return path


def native(container, config, program, *args, **kwargs):
    if program == 'mysqlbinlog' and os.environ.get('TAHSIN_MYSQL_CLIENT_IMAGE'):
        # The official minimal server image omits mysqlbinlog. A matching official
        # client image shares only the target's network, never its data volume.
        credentials = run(['docker', 'exec', container, 'cat', config])
        with tempfile.NamedTemporaryFile(prefix='tahsin-client-', suffix='.cnf') as handle:
            handle.write(credentials)
            handle.flush()
            return run(['docker', 'run', '--rm', '--network', 'container:' + container, '--memory', '128m', '--cpus', '0.25',
                '--mount', 'type=bind,src=' + handle.name + ',dst=/work/client.cnf,readonly', '--entrypoint', 'mysqlbinlog',
                os.environ['TAHSIN_MYSQL_CLIENT_IMAGE'], '--defaults-file=/work/client.cnf', '--host=127.0.0.1', '--protocol=TCP', *args], **kwargs)
    return run(['docker', 'exec', '-i', container, program, '--defaults-file=' + config, *args], **kwargs)


def query(container, config, sql):
    return native(container, config, 'mysql', '--batch', '--raw', '--skip-column-names', '--execute=' + sql).decode().strip()


def position(container, config):
    columns = query(container, config, 'SHOW BINARY LOG STATUS').split('\t')
    if len(columns) < 2 or not columns[1].isdigit():
        raise RuntimeError('MySQL 8.4 binlog position unavailable')
    return {'file': columns[0], 'position': int(columns[1])}


def locker_line(process, seconds=12):
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    try:
        if not selector.select(seconds):
            raise RuntimeError('Timed out waiting for explicit backup read lock')
        return process.stdout.readline().decode().strip()
    finally:
        selector.close()


def app_snapshot(app, helper, out):
    token = secrets.token_hex(10)
    temporary = '/tmp/tahsin-evidence-' + token
    run(['docker', 'exec', app, 'mkdir', '-m', '700', temporary])
    run(['docker', 'cp', str(helper), app + ':' + temporary + '/helper.php'])
    code = '''require $argv[1]; require "/var/www/html/config/database.php";
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
$baseline=safetyBaseline($pdo,$argv[2]);
safetyJson($argv[2]."/snapshot.json",["source"=>safetyIdentity($pdo),"baseline"=>$baseline]);'''
    # Only hashes/metadata are copied out. No password is printed or placed in command arguments.
    run(['docker', 'exec', app, 'php', '-r', code, temporary + '/helper.php', temporary], timeout=45)
    run(['docker', 'cp', app + ':' + temporary + '/.', str(out)])
    # Leave the tiny evidence copy in /tmp for audit; never recursively delete anything on production.
    return json.loads((out / 'snapshot.json').read_text())


def backup(args, out):
    if not args.lock_writes:
        raise RuntimeError('A measured maintenance window and --lock-writes are required')
    app, database = inspect(args.app_container), inspect(args.db_container)
    environment = db_environment(database)
    password = environment.get('MYSQL_ROOT_PASSWORD')
    if password is None:
        path = environment.get('MYSQL_ROOT_PASSWORD_FILE')
        if not path:
            raise RuntimeError('Existing native root credentials unavailable; no access grants will be created')
        password = run(['docker', 'exec', args.db_container, 'cat', path]).decode().rstrip('\n')
    config = native_config(args.db_container, password)
    process, connection, timer = None, None, None
    started = time.monotonic()
    try:
        meta = query(args.db_container, config, 'SELECT VERSION(),@@log_bin,@@binlog_format,@@sync_binlog,@@innodb_flush_log_at_trx_commit').split('\t')
        if not re.match(r'^8\.4\.', meta[0]) or meta[1:3] != ['1', 'ROW']:
            raise RuntimeError('This Docker profile requires MySQL 8.4 with ROW binlog')
        if query(args.db_container, config, 'SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_rows_modified>0') != '0':
            raise RuntimeError('Active write transactions detected; postpone the backup window')
        log = (out / 'lock.stderr').open('xb')
        process = subprocess.Popen(['docker', 'exec', '-i', args.db_container, 'mysql', '--defaults-file=' + config,
            '--batch', '--raw', '--skip-column-names', '--unbuffered'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=log, bufsize=0)
        process.stdin.write(b'SELECT CONNECTION_ID();\n')
        connection = locker_line(process)
        if not connection.isdigit():
            raise RuntimeError('Cannot identify owned backup connection')
        # Independent connection watchdog guarantees an abandoned terminal cannot retain the global lock.
        def release_owned_lock():
            try:
                query(args.db_container, config, 'KILL CONNECTION ' + connection)
            except Exception:
                pass
        timer = threading.Timer(30, release_owned_lock)
        timer.daemon = True
        timer.start()
        process.stdin.write(b'SET SESSION lock_wait_timeout=10; FLUSH TABLES WITH READ LOCK; SELECT "TAHSIN_LOCKED";\n')
        if locker_line(process) != 'TAHSIN_LOCKED':
            raise RuntimeError('Backup lock not acquired')
        before = position(args.db_container, config)
        snapshot = app_snapshot(args.app_container, args.helper, out)
        name = snapshot['baseline']['database']
        if not re.fullmatch(r'[a-zA-Z_][a-zA-Z0-9_]{0,63}', name):
            raise RuntimeError('Unsupported database name')
        server_id = query(args.db_container, config, 'SELECT @@hostname,@@port').split('\t')
        if server_id != [snapshot['source']['hostname'], snapshot['source']['port']]:
            raise RuntimeError('App and native client point to different database servers')
        common = ['--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks', '--hex-blob',
            '--default-character-set=utf8mb4', '--no-tablespaces', '--column-statistics=0', '--set-gtid-purged=OFF']
        stages = {'schema.sql': ['--no-data', '--skip-triggers', '--routines', '--skip-events'],
            'data.sql': ['--no-create-info', '--skip-triggers', '--skip-routines', '--skip-events'],
            'objects.sql': ['--no-data', '--no-create-info', '--triggers', '--skip-routines', '--events']}
        for filename, options in stages.items():
            if time.monotonic() - started > 25:
                raise RuntimeError('Backup exceeds measured lock budget; no ready manifest created')
            with (out / filename).open('xb') as handle:
                native(args.db_container, config, 'mysqldump', *common, *options, name, output=handle, timeout=12)
        if position(args.db_container, config) != before or time.monotonic() - started >= 29:
            raise RuntimeError('Backup boundary changed or watchdog budget expired')
    finally:
        if connection and connection.isdigit():
            try:
                query(args.db_container, config, 'KILL CONNECTION ' + connection)
            except Exception:
                pass
        if timer:
            timer.cancel()
        if process:
            if process.stdin:
                process.stdin.close()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
            log.close()
        run(['docker', 'exec', args.db_container, 'rm', '--', config])
    files = {p.name: {'bytes': p.stat().st_size, 'sha256': digest(p)} for p in sorted(out.iterdir()) if p.is_file()}
    manifest = {'format': FORMAT, 'kind': 'backup', 'source_app': identity(app), 'source_db': identity(database),
        'source': snapshot['source'], 'baseline': snapshot['baseline'], 'position': before, 'durability': meta[3:],
        'restore_order': list(stages), 'files': files, 'lock_budget_seconds': 30,
        'elapsed_seconds': round(time.monotonic() - started, 3), 'production_cutover_ready': False}
    write_json(out / 'manifest.json', manifest)
    return {'ok': True, 'directory': str(out), 'manifest_sha256': digest(out / 'manifest.json'),
        'tables': len(manifest['baseline']['tables']), 'elapsed_seconds': manifest['elapsed_seconds']}


def read_package(directory):
    directory = directory.resolve(strict=True)
    manifest = json.loads((directory / 'manifest.json').read_text())
    if manifest.get('format') != FORMAT or manifest.get('kind') != 'backup' or manifest.get('restore_order') != ['schema.sql', 'data.sql', 'objects.sql']:
        raise RuntimeError('Unsupported backup package')
    for name, expected in manifest['files'].items():
        path = directory / name
        if not re.fullmatch(r'[a-zA-Z0-9_.-]+', name) or path.is_symlink() or not path.is_file() or path.stat().st_size != expected['bytes'] or digest(path) != expected['sha256']:
            raise RuntimeError('Backup package checksum mismatch')
    for name in manifest['restore_order']:
        if name not in manifest['files']:
            raise RuntimeError('Missing restore stage')
    return manifest


def restore(args, out):
    manifest = read_package(args.backup)
    password = secrets.token_hex(24)
    secret_file = out / 'recovery-password'
    with secret_file.open('x') as handle:
        handle.write(password)
    data = out / 'mysql-data'
    data.mkdir(mode=0o700)
    target = 'tahsin-recovery-' + secrets.token_hex(8)
    config, created = None, False
    started = time.monotonic()
    try:
        run(['docker', 'run', '-d', '--name', target, '--network', 'none', '--memory', '512m', '--cpus', '0.5',
            '--mount', 'type=bind,src=' + str(data) + ',dst=/var/lib/mysql',
            '--mount', 'type=bind,src=' + str(secret_file) + ',dst=/run/secrets/recovery-password,readonly',
            '-e', 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets/recovery-password', manifest['source_db']['Image'],
            '--event-scheduler=OFF', '--skip-log-bin', '--innodb-buffer-pool-size=96M'], timeout=30)
        created = True
        actual = inspect(target)
        originals = {m['Source'] for m in manifest['source_db']['mounts']} | {m['Source'] for m in manifest['source_app']['mounts']}
        if any(m['Source'] in originals for m in actual['Mounts']) or actual['HostConfig']['NetworkMode'] != 'none' or actual['HostConfig'].get('PortBindings'):
            raise RuntimeError('Recovery isolation check failed')
        config = native_config(target, password)
        for attempt in range(90):
            try:
                version = query(target, config, 'SELECT VERSION()')
                break
            except (RuntimeError, subprocess.TimeoutExpired):
                if attempt == 89:
                    raise RuntimeError('Recovery instance did not become ready')
                time.sleep(1)
        if version != manifest['source']['version']:
            raise RuntimeError('Recovery server version differs from source')
        name = manifest['baseline']['database']
        encoding = manifest['baseline']['encoding']
        for value in [name, encoding['charset'], encoding['collation']]:
            if not re.fullmatch(r'[a-zA-Z_][a-zA-Z0-9_]{0,63}', value):
                raise RuntimeError('Invalid schema identity')
        if query(target, config, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys')") != '0':
            raise RuntimeError('Recovery instance is not empty')
        query(target, config, 'CREATE DATABASE `' + name + '` CHARACTER SET ' + encoding['charset'] + ' COLLATE ' + encoding['collation'])
        for stage in manifest['restore_order']:
            with (args.backup / stage).open('rb') as handle:
                with (out / (stage + '.stdout')).open('xb') as stdout:
                    result = subprocess.run(['docker', 'exec', '-i', target, 'mysql', '--defaults-file=' + config,
                        '--binary-mode', '--default-character-set=utf8mb4', name], stdin=handle, stdout=stdout, stderr=subprocess.PIPE, timeout=60)
                    if result.returncode:
                        (out / (stage + '.stderr')).write_bytes(result.stderr)
                        raise RuntimeError('Native restore failed; private evidence preserved')
        expected_file = out / 'expected.json'
        write_json(expected_file, manifest['baseline'])
        code = '''require "/work/helper.php";
$config=["host"=>"127.0.0.1","port"=>"3306","username"=>"root","password"=>file_get_contents("/work/password"),"database"=>$argv[1]];
$pdo=safetyConnect($config); $actual=safetyBaseline($pdo,"/evidence");
$expected=json_decode(file_get_contents("/work/expected.json"),true,512,JSON_THROW_ON_ERROR);
$differences=safetyDifferences($expected,$actual);
safetyJson("/evidence/verification.json",["ok"=>!$differences,"differences"=>$differences,"baseline"=>$actual,"source"=>safetyIdentity($pdo)]);
if($differences)exit(1);'''
        evidence = out / 'verified'
        evidence.mkdir(mode=0o700)
        run(['docker', 'run', '--rm', '--network', 'container:' + target, '--memory', '128m', '--cpus', '0.25',
            '--mount', 'type=bind,src=' + str(args.helper) + ',dst=/work/helper.php,readonly',
            '--mount', 'type=bind,src=' + str(expected_file) + ',dst=/work/expected.json,readonly',
            '--mount', 'type=bind,src=' + str(secret_file) + ',dst=/work/password,readonly',
            '--mount', 'type=bind,src=' + str(evidence) + ',dst=/evidence',
            '--entrypoint', 'php', manifest['source_app']['Image'], '-r', code, name], timeout=60)
        verification = json.loads((evidence / 'verification.json').read_text())
        if not verification['ok']:
            raise RuntimeError('Restored database differs from frozen source')
        write_json(out / 'result.json', {'ok': True, 'target': target, 'source_manifest_sha256': digest(args.backup / 'manifest.json'),
            'tables': len(verification['baseline']['tables']), 'objects': len(verification['baseline']['objects']),
            'elapsed_seconds': round(time.monotonic() - started, 3), 'production_cutover_ready': False})
        return {'ok': True, 'evidence': str(out / 'result.json'), 'tables': len(verification['baseline']['tables'])}
    finally:
        if config:
            try:
                run(['docker', 'exec', target, 'rm', '--', config])
            except Exception:
                pass
        if created:
            run(['docker', 'stop', '--time', '15', target], timeout=25)
        # Keep the stopped recovery container and its own data for audit. Never overwrite/delete source data.


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['backup', 'restore-drill', 'verify'])
    parser.add_argument('--helper', type=Path)
    parser.add_argument('--out', type=Path)
    parser.add_argument('--backup', type=Path)
    parser.add_argument('--app-container')
    parser.add_argument('--db-container')
    parser.add_argument('--lock-writes', action='store_true')
    args = parser.parse_args()
    if args.action == 'verify':
        manifest = read_package(args.backup)
        return {'ok': True, 'files': len(manifest['files'])}
    if sys.platform != 'linux' or not args.helper or not args.helper.is_file() or not args.out:
        raise RuntimeError('Use the Linux Docker host, an existing reviewed helper and a new private output directory')
    args.helper = args.helper.resolve()
    out = new_output(args.out)
    return backup(args, out) if args.action == 'backup' else restore(args, out)


if __name__ == '__main__':
    try:
        print(json.dumps(main(), ensure_ascii=False))
    except Exception as error:
        print(json.dumps({'ok': False, 'message': str(error)}), file=sys.stderr)
        sys.exit(1)
