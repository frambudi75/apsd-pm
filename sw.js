/**
 * Service Worker - IASS SBU ICT KNO Progressive Web App
 * IASS SBU ICT KNO
 */

const CACHE_NAME = 'iass-pm-v2.8.0';
const STATIC_ASSETS = [
  './',
  'manifest.json',
  'assets/css/app.css',
  'assets/css/print.css',
  'assets/js/qrious.min.js',
  'assets/js/html2pdf.bundle.min.js',
  'assets/js/draft_manager.js',
  'assets/js/qr_scanner.js',
  'assets/js/app.js',
  'assets/img/logo_ias.png',
  'assets/img/pwa-icon-192.png',
  'assets/img/pwa-icon-512.png',
  'assets/img/icon-192.png',
  'assets/img/icon-512.png',
  'uploads/branding/pwa-icon-192.png',
  'uploads/branding/pwa-icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      for (const asset of STATIC_ASSETS) {
        try {
          await cache.add(asset);
        } catch (e) {
          // Individual asset failure should never block service worker installation
        }
      }
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
