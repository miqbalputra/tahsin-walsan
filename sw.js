const CACHE_PREFIX = `tahsin-presensi-${encodeURIComponent(self.registration.scope)}-`;
const CACHE_NAME = `${CACHE_PREFIX}v7`;

// Public assets only. Draft recovery uses IndexedDB, independently of this cache.
const STATIC_ASSETS = [
    'manifest.json',
    'icon-512.png',
    'assets/css/app.css',
    'assets/js/alpine.min.js',
    'assets/js/chart.umd.min.js',
    'assets/js/tahsin-autosave.js?v=1',
    'assets/css/tahsin-autosave.css?v=1'
];
const STATIC_PATHS = new Set(STATIC_ASSETS.map(path => new URL(path, self.registration.scope).pathname));

self.addEventListener('install', event => {
    // An open editor keeps its worker until the user closes its tabs.
    event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.filter(key => key !== CACHE_NAME && (key.startsWith(CACHE_PREFIX)
            || (new URL(self.registration.scope).pathname === '/' && /^tahsin-presensi-v\d+$/.test(key))))
            .map(key => caches.delete(key))
    )));
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (STATIC_PATHS.has(url.pathname)) {
        event.respondWith((async () => {
            const cache = await caches.open(CACHE_NAME);
            const cached = await cache.match(request);
            if (cached) return cached;
            const response = await fetch(request);
            if (response.ok && response.type !== 'opaque') {
                try { await cache.put(request, response.clone()); }
                catch (_) { /* Storage quota must not prevent an asset loading. */ }
            }
            return response;
        })());
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => new Response(
            '<!doctype html><html lang="id"><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width, initial-scale=1">'
            + '<title>Koneksi terputus — Presensi Tahsin</title>'
            + '<body><main style="max-width:36rem;margin:3rem auto;padding:1.5rem;font:1rem/1.6 system-ui">'
            + '<h1>Koneksi sedang terputus</h1><p>Halaman ini perlu koneksi internet. '
            + 'Draft presensi yang sudah tersimpan di perangkat tetap tersedia.</p>'
            + '<p>Periksa koneksi, lalu <a href="">buka kembali halaman ini</a>.</p></main></body></html>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
        )));
    }
});

