// ============================================================
// Service Worker: sw.js
// Offline PWA Caching & Network Fallback for Kofee POS & Requisitions
// ============================================================

const CACHE_NAME = 'kofee-pos-cache-v2';

const STATIC_ASSETS = [
  './css/style.css',
  './css/sidebar.css',
  './css/menu.css',
  './css/order-panel.css',
  './css/receipt_modal.css',
  './assets/vendor/sweetalert2/sweetalert2.all.min.js',
  './js/validator.js',
  './js/menu.js',
  './assets/milktea.png',
  './php/menu.php',
  './php/requisitions.php'
];

// Install: pre-cache essential assets
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.warn('Pre-cache of some assets skipped or failed:', err);
      });
    })
  );
});

// Activate: clean up older caches
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
    }).then(() => self.clients.claim())
  );
});

// Fetch: Strategy depending on request type
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Skip non-GET requests (handled by clientside offline queue in JS)
  if (req.method !== 'GET') {
    return;
  }

  // Skip external analytics or dynamic endpoints
  if (url.origin !== location.origin) {
    return;
  }

  // For API endpoints: Network-first
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(req).catch(() => {
        return caches.match(req);
      })
    );
    return;
  }

  // For HTML / PHP Pages: Network-first, fallback to cache
  if (req.headers.get('accept')?.includes('text/html') || url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(req)
        .then(response => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(req, clone));
          }
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(req);
          if (cached) return cached;
          // Fallback to cached pages if requested
          if (url.pathname.includes('requisitions.php')) {
            const reqCached = await caches.match('./php/requisitions.php');
            if (reqCached) return reqCached;
          }
          if (url.pathname.includes('menu.php')) {
            const menuCached = await caches.match('./php/menu.php');
            if (menuCached) return menuCached;
          }
          return new Response(
            `<!DOCTYPE html>
            <html lang="en">
            <head><meta charset="UTF-8"><title>Offline — Kofee POS</title>
            <style>body{font-family:sans-serif;padding:40px;text-align:center;background:#FAF5EE;color:#241A2E}
            .card{background:#fff;padding:30px;border-radius:18px;max-width:440px;margin:auto;border:1px solid #DFCBB5;box-shadow:0 8px 24px rgba(0,0,0,0.06)}
            h2{color:#C97B3D;margin-top:0}
            button{background:#C97B3D;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer}
            </style></head>
            <body>
            <div class="card">
              <h2>You are Offline</h2>
              <p>No active WiFi connection was found, but your offline data is safely stored on this device.</p>
              <button onclick="location.reload()">Retry Connection</button>
            </div>
            </body></html>`,
            { headers: { 'Content-Type': 'text/html' } }
          );
        })
    );
    return;
  }

  // For static assets (CSS, JS, images, fonts): Cache-first, then network
  event.respondWith(
    caches.match(req).then(cached => {
      if (cached) return cached;
      return fetch(req).then(response => {
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, clone));
        }
        return response;
      });
    }).catch(() => {
      // offline fallback
      return new Response('', { status: 408 });
    })
  );
});
