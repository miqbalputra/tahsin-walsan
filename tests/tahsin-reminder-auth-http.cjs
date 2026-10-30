/** Exercise reminder endpoint authentication without a database or production config. */
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const {createServer} = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = process.env.TEST_PHP_BINARY || 'php';

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

function stopServer(server) {
  return new Promise(resolve => {
    if (server.exitCode !== null || server.signalCode !== null) return resolve();
    server.once('exit', resolve);
    server.kill();
    setTimeout(resolve, 3000).unref();
  });
}

(async () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'tahsin-reminder-auth-http-'));
  const envFile = path.join(tempRoot, 'empty.env');
  const sessionDir = path.join(tempRoot, 'sessions');
  fs.writeFileSync(envFile, '');
  fs.mkdirSync(sessionDir, {mode: 0o700});
  const port = await reservePort();
  const base = `http://127.0.0.1:${port}`;
  const env = {
    ...process.env,
    APP_ENV: 'testing',
    APP_ENV_FILE: envFile,
    DB_HOST: '127.0.0.1',
    DB_PORT: '1',
    DB_DATABASE: 'tahsin_test_auth_unavailable',
    DB_USERNAME: 'tahsin_test_unavailable',
    DB_PASSWORD: 'invalid-fixture-only',
    N8N_API_KEY: 'fixture-reminder-auth-key',
    SESSION_SAVE_PATH: sessionDir,
  };
  delete env.HERMES_API_KEY;
  const server = spawn(php, ['-S', `127.0.0.1:${port}`, 'tests/fixtures/tahsin-reminder-auth-router.php'], {
    cwd: root,
    env,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let serverOutput = '';
  server.stdout.on('data', bytes => { serverOutput = (serverOutput + bytes.toString()).slice(-5000); });
  server.stderr.on('data', bytes => { serverOutput = (serverOutput + bytes.toString()).slice(-5000); });

  try {
    let ready = false;
    for (let attempt = 0; attempt < 40; attempt++) {
      if (server.exitCode !== null) throw new Error(`Auth fixture server exited early. ${serverOutput}`);
      try {
        const response = await fetch(`${base}/__ready`, {signal: AbortSignal.timeout(1000)});
        if (response.status === 204) { ready = true; break; }
      } catch (_) {}
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert(ready, `Auth fixture server did not become ready. ${serverOutput}`);

    const request = (route, key) => fetch(`${base}${route}`, {
      headers: key === null ? {} : {'X-API-Key': key},
      signal: AbortSignal.timeout(5000),
    });
    let checks = 0;
    const check = (condition, message) => { assert(condition, message); checks++; };

    check((await request('/api/daily-reminder.php', null)).status === 401, 'Missing key gets 401 without opening the database.');
    for (let attempt = 1; attempt < 10; attempt++) {
      check((await request('/api/daily-reminder.php', 'wrong-key')).status === 401,
        `Failed key ${attempt} of 10 gets 401 without opening the database.`);
    }
    check((await request('/api/notifications-pending.php', 'wrong-key')).status === 429,
      'The shared eleventh failure gets 429 on the other reminder endpoint.');
    check((await request('/api/daily-reminder.php', 'fixture-reminder-auth-key')).status === 503,
      'A valid key reaches the deliberately unavailable loopback database and is not blocked by failed-key throttling.');

    console.log(`PASS ${checks} isolated reminder-auth HTTP assertions; database port 1 only`);
  } finally {
    await stopServer(server);
    const realRoot = fs.realpathSync(tempRoot);
    const realTemp = fs.realpathSync(os.tmpdir());
    assert.equal(path.dirname(realRoot), realTemp);
    assert.ok(path.basename(realRoot).startsWith('tahsin-reminder-auth-http-'));
    fs.rmSync(realRoot, {recursive: true, force: true});
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
