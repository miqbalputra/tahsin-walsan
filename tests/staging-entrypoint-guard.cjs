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

  console.log('PASS entrypoint accepts synthetic staging and rejects production DB / Go writer');
} finally {
  const sessionPath = path.join(root, sessionDir);
  if (fs.existsSync(sessionPath)) fs.rmdirSync(sessionPath);
}
