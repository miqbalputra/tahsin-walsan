const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const entrypoint = path.join(root, 'docker', 'entrypoint.sh');
const bash = process.env.TAHSIN_TEST_BASH || (process.platform === 'win32'
  ? 'C:\\Program Files\\Git\\bin\\bash.exe'
  : 'bash');
const sessionDir = `.sessions/staging-entrypoint-${process.pid}`;

function run(database, writeEnabled = '0') {
  return spawnSync(bash, [entrypoint, 'true'], {
    cwd: root,
    encoding: 'utf8',
    env: {
      ...process.env,
      SESSION_SAVE_PATH: sessionDir,
      TAHSIN_STAGING_ONLY: '1',
      APP_ENV: 'staging',
      DB_DATABASE: database,
      TAHSIN_NEXT_WRITE_ENABLED: writeEnabled,
    },
  });
}

const compose = fs.readFileSync(path.join(root, 'compose.go-vue-staging.yml'), 'utf8');
const appService = compose.split(/^  go:\s*$/m, 1)[0].split(/^  app:\s*$/m).at(-1) || '';
const goService = compose.split(/^  go:\s*$/m).at(-1)?.split(/^volumes:\s*$/m, 1)[0] || '';
assert.match(appService, /deploy:\s*\r?\n\s+resources:\s*\r?\n\s+limits:\s*\r?\n\s+cpus:\s*"1\.00"\s*\r?\n\s+memory:\s*768M/,
  'staging PHP must retain its 1 CPU / 768 MiB limit');
assert.match(goService, /deploy:\s*\r?\n\s+resources:\s*\r?\n\s+limits:\s*\r?\n\s+cpus:\s*"0\.50"\s*\r?\n\s+memory:\s*256M/,
  'staging Go must retain its 0.5 CPU / 256 MiB limit');
assert.doesNotMatch(compose, /^\s+ports:/m, 'staging services must not publish host ports');

try {
  const syntax = spawnSync(bash, ['-n', entrypoint], { cwd: root, encoding: 'utf8' });
  assert.equal(syntax.status, 0, syntax.stderr || syntax.error?.message);
  fs.mkdirSync(path.join(root, sessionDir));
  const accepted = run('tahsin_test_autosave_compose_fixture');
  assert.equal(accepted.status, 0, accepted.stderr || accepted.error?.message);

  const productionDatabase = run('db_tahsinbpk');
  assert.equal(productionDatabase.status, 64, 'production database must fail closed');

  const writerEnabled = run('tahsin_test_autosave_compose_fixture', '1');
  assert.equal(writerEnabled.status, 64, 'staging-only image must reject Go writer');

  console.log('PASS staging entrypoint guards, internal-only ports, and candidate resource limits');
} finally {
  const sessionPath = path.join(root, sessionDir);
  if (fs.existsSync(sessionPath)) fs.rmdirSync(sessionPath);
}
