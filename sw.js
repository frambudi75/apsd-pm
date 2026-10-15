/**
 * Service Worker - APSD PM Progressive Web App
 * PT Angkasa Pura Sarana Digital
 */

const CACHE_NAME = 'apsd-pm-v8';
const STATIC_ASSETS = [
  './',
  'index.php',
  'reports.php',
  'form_indoor.php',
  'form_outdoor.php',
  'sites.php',
  'manifest.json',
  'assets/css/app.css',
  'assets/css/print.css',
  'assets/js/qrcode.min.js',
  'assets/js/draft_manager.js',
  'assets/js/qr_scanner.js',
  'assets/js/app.js',
  'assets/img/logo_ias.png',
  'assets/img/logo_telkomsel.png',
  'assets/img/logo_indosat.svg',
  'assets/img/logo_xl.svg',
  'assets/img/icon-192.png',
  'assets/img/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Network-first strategy with dynamic asset caching and offline fallback
self.addEventListener('fetch', event => {
  // Only handle GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Handle cross-origin or non-http requests safely
  if (!event.request.url.startsWith(self.location.origin)) {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Cache successful responses on the fly
        if (response && response.status === 200 && response.type === 'basic') {
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseToCache);
          });
        }
        return response;
      })
      .catch(() => {
        // Fallback to cache when offline
        return caches.match(event.request).then(cachedResponse => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // If HTML navigation fails offline, show cached dashboard
          if (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) {
            return caches.match('index.php');
          }
        });
      })
  );
});
