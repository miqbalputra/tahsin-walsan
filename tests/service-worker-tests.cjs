const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const handlers = {}, deleted = [], puts = [];
let offline = false, quotaFull = false, fetches = 0;
const cache = { addAll: async () => {}, match: async () => undefined,
  put: async request => { if (quotaFull) throw Error('quota'); puts.push(request.url); } };
vm.runInNewContext(fs.readFileSync(require.resolve('../sw.js'), 'utf8'), {
  URL, Response, Set,
  self: { registration: { scope: 'https://example.test/tahsin/' }, location: { origin: 'https://example.test' },
    addEventListener: (name, callback) => { handlers[name] = callback; } },
  caches: { open: async () => cache, keys: async () => ['another-app', 'tahsin-presensi-v6', 'tahsin-presensi-https%3A%2F%2Fexample.test%2Ftahsin%2F-v6', 'tahsin-presensi-https%3A%2F%2Fexample.test%2Fother%2F-v6'],
    delete: async key => { deleted.push(key); return true; } },
  fetch: async () => { fetches++; if (offline) throw Error('offline'); return new Response('ok'); }
});
async function request(path, extra = {}) {
  let response;
  const event = { request: { url: new URL(path, 'https://example.test').href, method: 'GET', mode: 'cors', ...extra },
    respondWith: value => { response = value; } };
  handlers.fetch(event);
  return response ? await response : undefined;
}
(async () => {
  check(await request('/tahsin/login.php') === undefined, 'Account requests are not intercepted or cached');
  check(await request('/tahsin/api/private-data') === undefined, 'Future extensionless API responses stay private');
  check(await request('/tahsin/ustadz/api-autosave.php', {method:'POST'}) === undefined, 'Writes are never cached');
  check(await request('https://other.example/assets/css/app.css') === undefined, 'Other origins are never cached');
  check(await request('/other-app/assets/css/app.css') === undefined, 'Other applications are outside the asset allowlist');
  check((await request('/tahsin/assets/css/app.css')).status === 200, 'Application assets load normally');
  check(puts.length === 1, 'Only an allowlisted public asset is cached');
  quotaFull = true;
  check((await request('/tahsin/assets/js/alpine.min.js')).status === 200, 'A full cache does not break asset loading');
  offline = true;
  const fallback = await request('/tahsin/users.php', {mode:'navigate'});
  check(fallback.status === 503 && /no-store/.test(fallback.headers.get('Cache-Control')), 'Offline navigation is explicit and private');
  check((await fallback.text()).includes('Draft presensi'), 'Offline message explains draft preservation');
  let activated;
  handlers.activate({waitUntil: value => { activated = value; }});
  await activated;
  check(!deleted.includes('another-app') && deleted.includes('tahsin-presensi-https%3A%2F%2Fexample.test%2Ftahsin%2F-v6'), 'Activation removes only this application scope cache');
  check(!deleted.includes('tahsin-presensi-https%3A%2F%2Fexample.test%2Fother%2F-v6'), 'Another Tahsin installation keeps its own cache');
  check(puts.length === 1 && fetches === 3, 'Private requests do not accidentally reach cache operations');
  console.log(`PASS ${checks} service worker assertions`);
})().catch(error => { console.error(error); process.exitCode = 1; });
