const CACHE_NAME = 'tahsin-presensi-go-vue-v1';

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    const response = await fetch('/index.html', { cache: 'no-store' });
    if (!response.ok) throw new Error('app shell unavailable');
    await cache.put('/index.html', response.clone());
    const html = await response.text();
    const assets = [...html.matchAll(/(?:src|href)="(\/assets\/[^"?#]+\.(?:js|css))"/g)].map(match => match[1]);
    await cache.addAll(['/app.webmanifest', '/icon-512.png', ...assets]);
  })());
  // Keep an open editor alive until its tab closes; its draft queue is in IndexedDB.
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(key => key.startsWith('tahsin-presensi-go-vue-') && key !== CACHE_NAME).map(key => caches.delete(key)),
  )).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || request.method !== 'GET') return;

  // Never cache login, session, attendance, autosave, or integration responses.
  if (url.pathname.startsWith('/api/') || url.pathname === '/ustadz/api-autosave.php') {
    event.respondWith(fetch(new Request(request, { cache: 'no-store' })));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(new Request(request, { cache: 'no-store' })).catch(() => caches.match('/index.html')));
    return;
  }

  if (url.pathname === '/assets/js/tahsin-autosave.js' || url.pathname === '/assets/css/tahsin-autosave.css') {
    event.respondWith(fetch(new Request(request, { cache: 'no-store' })).then(response => {
      if (response.ok) caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone()));
      return response;
    }).catch(() => caches.match(request)));
    return;
  }

  if (!url.pathname.startsWith('/assets/')) return;
  event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => {
    if (response.ok && !/no-store|private/i.test(response.headers.get('Cache-Control') || '')) {
      caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone()));
    }
    return response;
  })));
});
