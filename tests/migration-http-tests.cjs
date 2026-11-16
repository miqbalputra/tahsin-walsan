/** Migration scripts must refuse direct HTTP access, independent of .htaccess/router. */
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = process.env.TEST_PHP_BINARY || 'php';
const migrations = fs.readdirSync(root).filter(name => /^migrate.*\.php$/i.test(name)).sort();
assert.ok(migrations.length > 0, 'Migration scripts must be present');

async function freePort() {
  const listener = net.createServer();
  await new Promise((resolve, reject) => listener.once('error', reject).listen(0, '127.0.0.1', resolve));
  const {port} = listener.address();
  await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));
  return port;
}

(async () => {
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-d', 'display_errors=0', '-S', `127.0.0.1:${port}`, '-t', root], {
    cwd: root,
    windowsHide: true,
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let stderr = '';
  server.stderr.on('data', bytes => { stderr += bytes.toString(); });
  let checks = 0;
  const check = (condition, message) => { assert.ok(condition, message); checks++; };

  try {
    let ready = false;
    for (let attempt = 0; attempt < 40; attempt++) {
      if (server.exitCode !== null) throw new Error(`PHP server exited early: ${stderr}`);
      try {
        await fetch(`${base}/migrate.php?apply=1`);
        ready = true;
        break;
      } catch (_) {
        await new Promise(resolve => setTimeout(resolve, 100));
      }
    }
    check(ready, 'Direct PHP server started');

    for (const migration of migrations) {
      for (const method of ['GET', 'POST']) {
        const response = await fetch(`${base}/${migration}?apply=1`, {
          method,
          ...(method === 'POST' ? {
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'apply=1',
          } : {}),
        });
        const body = await response.text();
        check(response.status === 404, `${migration} refuses direct ${method} HTTP`);
        check(!/SQLSTATE|database|ALTER TABLE|CREATE TABLE/i.test(body), `${migration} does not expose migration/database output over HTTP`);
      }
    }
    check(!/SQLSTATE|Fatal error|Uncaught|Warning:/i.test(stderr), 'HTTP probes did not reach database or emit PHP errors');
    console.log(`PASS ${checks} migration HTTP assertions (${migrations.length} scripts, GET and POST)`);
  } finally {
    server.kill();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
