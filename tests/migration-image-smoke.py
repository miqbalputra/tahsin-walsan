#!/usr/bin/env python3
"""Smoke-test built images against an owned recovery container; never deploy production."""
import argparse
import importlib.util
import json
from pathlib import Path
import secrets
import time

parser = argparse.ArgumentParser(description=__doc__)
for name in ('tool', 'backup', 'restore', 'out'):
    parser.add_argument('--' + name, type=Path, required=True)
parser.add_argument('--php-image', required=True)
parser.add_argument('--go-image', required=True)
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('safety', args.tool)
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)
manifest = tool.read_package(args.backup)
restored = json.loads((args.restore / 'result.json').read_text())
if not restored.get('ok') or restored['source_manifest_sha256'] != tool.digest(args.backup / 'manifest.json'):
    raise RuntimeError('A verified matching restore is required')
target = restored['target']
if not target.startswith('tahsin-recovery-'):
    raise RuntimeError('Owned recovery container required')
raw = json.loads(tool.run(['docker', 'inspect', target]))[0]
originals = {v['Source'] for key in ('source_app', 'source_db') for v in manifest[key]['mounts']}
if raw['HostConfig']['NetworkMode'] != 'none' or raw['HostConfig'].get('PortBindings') or any(v['Source'] in originals for v in raw['Mounts']):
    raise RuntimeError('Recovery isolation is invalid')
out = tool.new_output(args.out)
secret = secrets.token_hex(32)
env = out / 'php.env'
env.write_text('DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=' + manifest['baseline']['database'] +
    '\nDB_USERNAME=root\nDB_PASSWORD=' + (args.restore / 'recovery-password').read_text() +
    '\nTAHSIN_NEXT_ENABLED=0\nTAHSIN_VUE_TEACHER_ENABLED=0\nTAHSIN_NEXT_API_URL=http://127.0.0.1:8080\nTAHSIN_NEXT_SHARED_SECRET=' + secret + '\n')
go_env = out / 'go.env'
go_env.write_text('TAHSIN_NEXT_ENABLED=1\nTAHSIN_NEXT_DATA_SOURCE=demo\nTAHSIN_NEXT_SHARED_SECRET=' + secret + '\n')
created, config, checks = [], None, []
token = secrets.token_hex(8)
php = 'tahsin-image-php-' + token
go = 'tahsin-image-go-' + token


def php_exec(code):
    return tool.run(['docker', 'exec', php, 'php', '-r', code]).decode().strip()


try:
    tool.run(['docker', 'start', target])
    config = tool.native_config(target, (args.restore / 'recovery-password').read_text())
    for attempt in range(60):
        try:
            tool.query(target, config, 'SELECT 1')
            break
        except RuntimeError:
            if attempt == 59:
                raise
            time.sleep(1)
    for name, image, environment, memory in ((go, args.go_image, go_env, '96m'), (php, args.php_image, env, '192m')):
        tool.run(['docker', 'run', '-d', '--name', name, '--network', 'container:' + target,
            '--memory', memory, '--cpus', '0.5', '--env-file', str(environment), image])
        created.append(name)
    for attempt in range(30):
        try:
            health = json.loads(tool.run(['docker', 'exec', php, 'curl', '-fsS', 'http://127.0.0.1/health.php']))
            if health.get('ok'):
                break
        except (RuntimeError, json.JSONDecodeError):
            pass
        if attempt == 29:
            raise RuntimeError('Built PHP image is not healthy')
        time.sleep(1)
    checks.append('Built PHP/Apache connects to isolated MySQL clone')
    assets = json.loads(php_exec('require "/var/www/html/includes/go_vue_helper.php";'
        '$a=goVueAssets();$b=goVueAssets("src/teacher.ts");'
        'if(!$a||!$b||goVueEnabled()||goVueTeacherEnabled(1)||is_dir("/var/www/html/next")||is_file("/var/www/html/.env"))exit(1);'
        'echo json_encode([$a,$b]);'))
    for asset in assets:
        for path in [asset['file'], *asset.get('css', [])]:
            tool.run(['docker', 'exec', php, 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1/assets/go-vue/' + path])
    checks.append('Both Vue entrypoints and imported assets exist; flags stay off and source/secrets are excluded')
    for path in ('/next/backend/go.mod', '/.env', '/.sessions/anything'):
        status = tool.run(['docker', 'exec', php, 'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1' + path]).decode()
        if status not in ('403', '404'):
            raise RuntimeError('Private path is publicly served in candidate')
    checks.append('Apache denies private source, environment and session paths')
    health = json.loads(tool.run(['docker', 'exec', php, 'curl', '-fsS', 'http://127.0.0.1:8080/healthz']))
    if health.get('read_only') is not True:
        raise RuntimeError('Go health contract differs')
    preview = json.loads(php_exec('require "/var/www/html/includes/go_vue_helper.php";'
        'echo json_encode(goVueReadFamilies(goVueQuery([]),1,"admin"));'))
    if preview['meta']['data_source'] != 'demo' or preview['meta']['read_only'] is not True:
        raise RuntimeError('Signed PHP/Go preview contract differs')
    checks.append('Built nonroot Go image accepts signed PHP gateway request in explicit demo mode')
    for method, expected in (('GET', '401'), ('POST', '405')):
        status = tool.run(['docker', 'exec', php, 'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '-X', method, 'http://127.0.0.1:8080/api/v1/families']).decode()
        if status != expected:
            raise RuntimeError('Go authorization/write guard differs')
    checks.append('Go refuses unsigned reads and all writes')
    result = {'ok': True, 'scope': 'isolated-recovery-only', 'checks': checks,
        'images': {name: json.loads(tool.run(['docker', 'image', 'inspect', image]))[0]['Id']
                   for name, image in [('php', args.php_image), ('go', args.go_image)]},
        'production_deployed': False}
    tool.write_json(out / 'result.json', result)
    print(json.dumps({'ok': True, 'checks': len(checks), 'evidence': str(out / 'result.json')}))
finally:
    for container in reversed(created):
        tool.run(['docker', 'stop', '--time', '15', container], timeout=25)
    if config:
        tool.run(['docker', 'exec', target, 'rm', '--', config])
    tool.run(['docker', 'stop', '--time', '15', target], timeout=25)
