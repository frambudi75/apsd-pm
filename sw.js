/**
 * Service Worker - IASS SBU ICT KNO Progressive Web App
 * IASS SBU ICT KNO
 */

const CACHE_NAME = 'iass-pm-v2.6.0';
const STATIC_ASSETS = [
  './',
  'reports',
  'form_indoor',
  'form_outdoor',
  'sites',
  'operators',
  'manifest.json',
  'assets/css/app.css',
  'assets/css/print.css',
  'assets/js/qrious.min.js',
  'assets/js/html2pdf.bundle.min.js',
  'assets/js/draft_manager.js',
  'assets/js/qr_scanner.js',
  'assets/js/app.js',
  'assets/img/logo_ias.png',
  'assets/img/logo_telkomsel.png',
  'assets/img/logo_indosat.svg',
  'assets/img/logo_xl.svg',
  'assets/img/logo_smartfren.svg',
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
            return caches.match('./');
          }
        });
      })
  );
});
