// IPG Service Worker
const VERSION = 'ipg-v1';
const STATIC_CACHE  = VERSION + '-static';
const RUNTIME_CACHE = VERSION + '-runtime';

const PRECACHE = [
  '/offline.html',
  '/app.css',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-512.png',
  '/icons/apple-touch-icon.png'
];

// Установка: precache статики
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(STATIC_CACHE)
      .then((c) => c.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

// Активация: чистим старые версии
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // PHP и /api/ — только сеть. Офлайн → заглушка.
  if (url.pathname.endsWith('.php') || url.pathname.startsWith('/api/')) {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Статика — cache-first с докэшированием
  if (/\.(css|js|png|jpg|jpeg|svg|webp|woff2?|ico)$/i.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
          return res;
        });
      })
    );
    return;
  }

  // HTML и прочее — network-first, офлайн → кэш → offline.html
  e.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
        return res;
      })
      .catch(() =>
        caches.match(req).then((r) => r || caches.match('/offline.html'))
      )
  );
});
