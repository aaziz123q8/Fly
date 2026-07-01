/* FlyMasar service worker — installable PWA
   - HTML navigations: network-first (so new deploys show immediately),
     falling back to cache, then a minimal offline page.
   - Static assets (css/js/img/icons/fonts): cache-first (fast, offline-ready).
   - API / webhooks: network-only with a JSON offline fallback. */
const CACHE_NAME = 'flymasar-v2';
const STATIC_ASSETS = [
  '/index.html',
  '/manifest.json',
  '/css/app.css',
  '/icons/icon-192x192.png',
  '/icons/icon-512x512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS).catch(() => {}))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

function isStatic(url) {
  return /\.(css|js|png|jpg|jpeg|svg|gif|webp|woff2?|ttf|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Only handle same-origin requests; let the browser deal with the rest.
  if (url.origin !== self.location.origin) return;

  // API / webhooks — network only, JSON fallback when offline.
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/webhooks/')) {
    event.respondWith(
      fetch(req).catch(() =>
        new Response(JSON.stringify({ error: 'offline', message: 'لا يوجد اتصال بالإنترنت' }),
          { headers: { 'Content-Type': 'application/json' }, status: 503 })
      )
    );
    return;
  }

  // HTML navigations — network first, then cache, then offline shell.
  const isNav = req.mode === 'navigate' ||
    (req.headers.get('accept') || '').includes('text/html');
  if (isNav) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match(req).then((cached) =>
            cached || caches.match('/index.html') ||
            new Response('<h1 style="font-family:sans-serif;text-align:center;padding:40px">أنت غير متصل بالإنترنت</h1>',
              { headers: { 'Content-Type': 'text/html; charset=utf-8' } })
          )
        )
    );
    return;
  }

  // Static assets — cache first, then network (and cache it).
  if (isStatic(url)) {
    event.respondWith(
      caches.match(req).then((cached) =>
        cached ||
        fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        }).catch(() => cached)
      )
    );
    return;
  }

  // Everything else — network, fallback to cache.
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
