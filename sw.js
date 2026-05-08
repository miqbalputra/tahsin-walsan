const CACHE_NAME = 'tahsin-presensi-v2';

// Hanya cache aset STATIS — JANGAN cache file PHP
const STATIC_ASSETS = [
    'manifest.json',
    'icon-512.png',
    'https://cdn.tailwindcss.com',
    'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
    'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap'
];

// Install: cache aset statis saja
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate: hapus cache lama
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// Fetch: Network-first untuk halaman PHP, cache-first untuk aset statis
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Halaman PHP → SELALU ambil dari network (agar session diperiksa)
    if (url.pathname.endsWith('.php') || url.pathname === '/' || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(event.request)
                .catch(() => {
                    // Offline fallback: jika network gagal, coba tampilkan cache
                    return caches.match(event.request);
                })
        );
        return;
    }

    // Aset statis → cache-first (lebih cepat)
    event.respondWith(
        caches.match(event.request)
            .then(cached => cached || fetch(event.request).then(response => {
                // Cache response baru untuk request berikutnya
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            }))
            .catch(() => fetch(event.request))
    );
});

