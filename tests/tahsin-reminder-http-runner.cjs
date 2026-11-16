/** Start the weekly reminder HTTP suite against an explicitly named local fixture. */
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {createServer} = require('node:net');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = process.env.TEST_PHP_BINARY || 'php';
const database = process.env.DB_DATABASE || '';
const host = process.env.DB_HOST || '';
const port = process.env.DB_PORT || '';

assert.equal(host, '127.0.0.1', 'Weekly reminder HTTP checks only allow a loopback database');
assert.equal(port, '13317', 'Weekly reminder HTTP checks only allow the isolated test database port');
assert.match(database, /^tahsin_test_[a-z0-9_]+$/, 'Weekly reminder HTTP checks require a tahsin_test_* database');

const modules = spawnSync(php, ['-m'], {cwd: root, encoding: 'utf8', windowsHide: true});
if (modules.status !== 0) throw new Error(modules.stderr || 'Unable to inspect the configured PHP runtime.');
const dbArgs = /pdo_mysql/i.test(modules.stdout) ? [] : ['-d', 'extension=pdo_mysql'];

function reservePort() {
  return new Promise((resolve, reject) => {
    const listener = createServer();
    listener.once('error', reject);
    listener.listen(0, '127.0.0.1', () => {
      const address = listener.address();
      listener.close(error => error ? reject(error) : resolve(address.port));
    });
  });
}

function waitForExit(child, timeoutMs = 3000) {
  if (child.exitCode !== null || child.signalCode !== null) return Promise.resolve();
  return Promise.race([
    new Promise(resolve => child.once('exit', resolve)),
    new Promise(resolve => setTimeout(resolve, timeoutMs)),
  ]);
}

(async () => {
  const httpPort = await reservePort();
  const base = `http://127.0.0.1:${httpPort}`;
  const env = {...process.env, SESSION_SAVE_PATH: path.join(root, '.sessions', `weekly-reminder-${process.pid}`)};
  const server = spawn(php, [...dbArgs, '-S', `127.0.0.1:${httpPort}`, 'tests/fixtures/tahsin-reminder-router.php'], {
    cwd: root,
    env,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let serverOutput = '';
  server.stdout.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-8000); });
  server.stderr.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-8000); });

  try {
    let ready = false;
    for (let attempt = 0; attempt < 40; attempt++) {
      if (server.exitCode !== null) throw new Error(`Fixture server exited early. ${serverOutput}`);
      try {
        const response = await fetch(`${base}/health.php`, {signal: AbortSignal.timeout(1000)});
        if (response.status === 200) { ready = true; break; }
      } catch (_) {}
      await new Promise(resolve => setTimeout(resolve, 250));
    }
    assert(ready, `Fixture server did not become ready. ${serverOutput}`);

    const result = spawnSync(process.execPath, [path.join(__dirname, 'tahsin-reminder-http-checks.cjs')], {
      cwd: root,
      env: {...env, TAHSIN_TEST_URL: base},
      encoding: 'utf8',
      windowsHide: true,
      maxBuffer: 4 * 1024 * 1024,
    });
    if (result.stdout) process.stdout.write(result.stdout);
    if (result.stderr) process.stderr.write(result.stderr);
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`Weekly reminder HTTP checks failed with exit status ${result.status}.`);
  } finally {
    if (server.exitCode === null && server.signalCode === null) server.kill();
    await waitForExit(server);
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
