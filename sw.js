// OpenCORE Stats — Service Worker
// Cachea el shell de la app para carga instantánea

const CACHE = 'opencore-v1';
const SHELL = [
  '/opencore-dashboard/',
  '/opencore-dashboard/index.html',
  '/opencore-dashboard/manifest.json',
  '/opencore-dashboard/icon-192.png',
  '/opencore-dashboard/icon-512.png'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  // API calls (comentarios, tareas) siempre van a red — no cachear
  if (e.request.url.includes('opencore.cl')) {
    return; // network only
  }
  // Para el resto: network first, fallback a cache
  e.respondWith(
    fetch(e.request).catch(() => caches.match(e.request))
  );
});
