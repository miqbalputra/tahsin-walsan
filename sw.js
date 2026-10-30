const CACHE_PREFIX = `tahsin-presensi-${encodeURIComponent(self.registration.scope)}-`;
// BEGIN GENERATED PUBLIC ASSETS
const PUBLIC_ASSET_VERSION = "40f44e04018fccc1";
const STATIC_ASSETS = [
    "assets/css/app.css?v=3e393035c093a6d4",
    "assets/css/tahsin-activity.css?v=3fd65226e8215002",
    "assets/css/tahsin-autosave.css?v=0228532e6972dab7",
    "assets/css/tahsin-report.css?v=d6db93cf914955ca",
    "assets/js/alpine.min.js?v=57b37d7cae9a27d9",
    "assets/js/chart.umd.min.js?v=48444a82d4edcb5b",
    "assets/js/print-report.js?v=573723755a6f2ad8",
    "assets/js/tahsin-activity.js?v=0425f406c973069f",
    "assets/js/tahsin-autosave.js?v=81493e1e7c32d728",
    "assets/js/tahsin-legacy.js?v=a0ba9848d36106ff",
    "assets/js/tahsin-report.js?v=264955016236a6e1",
    "icon-512.png?v=8aaefc5f757ae42d",
    "manifest.json?v=fce5fe820db3349c"
];
// END GENERATED PUBLIC ASSETS
const CACHE_NAME = `${CACHE_PREFIX}v8-${PUBLIC_ASSET_VERSION}`;
const STATIC_PATHS = new Set(STATIC_ASSETS.map(path => new URL(path, self.registration.scope).pathname));
const STATIC_URLS = new Set(STATIC_ASSETS.map(path => new URL(path, self.registration.scope).href));

function publicAssetResponse(response, url) {
    if (!response.ok || response.redirected || !['basic', 'default'].includes(response.type)) return false;
    const type=(response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
    const path=url.pathname;
    if (path.endsWith('.css')) return type==='text/css';
    if (path.endsWith('.js')) return ['application/javascript','text/javascript'].includes(type);
    if (path.endsWith('.json')) return ['application/json','application/manifest+json'].includes(type);
    if (path.endsWith('.png')) return type==='image/png';
    return false;
}

self.addEventListener('install', event => {
    // An open editor keeps its worker until the user closes its tabs.
    event.waitUntil((async () => {
        const entries=await Promise.all(STATIC_ASSETS.map(async path => {
            const url=new URL(path,self.registration.scope);
            const response=await fetch(url.href,{cache:'reload',credentials:'same-origin'});
            if (!publicAssetResponse(response,url)) throw new Error('Public asset unavailable; keep the existing worker.');
            return [url.href,response];
        }));
        const cache=await caches.open(CACHE_NAME);
        await Promise.all(entries.map(([url,response])=>cache.put(url,response)));
    })());
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
            let cache;
            try {
                cache = await caches.open(CACHE_NAME);
                const cached = await cache.match(request);
                if (cached && publicAssetResponse(cached,url)) return cached;
                if (cached) await cache.delete(request);
            } catch (_) { cache=null; }
            const response = await fetch(request);
            if (cache && STATIC_URLS.has(url.href) && publicAssetResponse(response,url)) {
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

