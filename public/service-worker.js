/* ==========================================================================
   MtaaPass service worker
   Requirements: Blueprint §46–§47, contract §10.
   - versioned caches with safe activate cleanup
   - precached offline shell, CSS, JS and icons
   - navigations: network-first, offline.html fallback
   - static assets: stale-while-revalidate
   - /api/* and /health are never cached
   - LOGOUT message clears every cache; SKIP_WAITING applies an update
   Authentication secrets and sensitive API payloads are never stored.
   ========================================================================== */

'use strict';

var VERSION = 'v3';
var SHELL_CACHE = 'mtaapass-shell-v3';
var STATIC_CACHE = 'mtaapass-static-v3';
var OFFLINE_URL = '/offline.html';

// The offline shell and static assets are precached. HTML pages are never
// cached: a navigation response may be an authenticated, personal page and must
// not become readable from a shared device after the fact.
var PRECACHE = [
  OFFLINE_URL,
  '/manifest.json',
  '/assets/css/app.css',
  '/assets/js/theme.js',
  '/assets/js/app.js',
  '/assets/img/estate-hero.png',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-512.png',
  '/assets/icons/apple-touch-icon.png',
  '/assets/icons/favicon.png'
];

function isNeverCached(pathname) {
  return pathname.indexOf('/api/') === 0 || pathname === '/health' || pathname === '/api/v1/health';
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      return Promise.all(
        PRECACHE.map(function (url) {
          return cache
            .add(new Request(url, { cache: 'reload' }))
            .catch(function () {
              // One missing asset must never fail the whole install.
            });
        })
      );
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    (function () {
      return caches.keys().then(function (keys) {
        return Promise.all(
          keys
            .filter(function (key) {
              return key !== SHELL_CACHE && key !== STATIC_CACHE;
            })
            .map(function (key) {
              return caches.delete(key);
            })
        );
      }).then(function () {
        return self.clients.claim();
      });
    })()
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;

  // Only GET requests are cached; everything else goes straight to the server.
  if (request.method !== 'GET') {
    return;
  }

  var url;
  try {
    url = new URL(request.url);
  } catch (error) {
    return;
  }

  if (url.origin !== self.location.origin) {
    return;
  }

  if (isNeverCached(url.pathname)) {
    return; // never cached, never intercepted: sensitive API responses
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }

  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
  }
});

function networkFirst(request) {
  return fetch(request).catch(function () {
    // Offline: never replay a cached authenticated page. Show the offline
    // shell so the device cannot expose another user's page after logout.
    return caches.match(OFFLINE_URL).then(function (offline) {
      if (offline) {
        return offline;
      }

      return new Response('You are offline.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' }
      });
    });
  });
}

function staleWhileRevalidate(request, cacheName) {
  return caches.open(cacheName).then(function (cache) {
    return cache.match(request, { ignoreSearch: true }).then(function (cached) {
      var network = fetch(request)
        .then(function (response) {
          if (response && response.ok && response.status === 200) {
            cache.put(request, response.clone());
          }
          return response;
        })
        .catch(function () {
          return null;
        });

      if (cached) {
        return cached;
      }

      return network.then(function (response) {
        if (response) {
          return response;
        }
        return new Response('', { status: 504, statusText: 'Gateway Timeout' });
      });
    });
  });
}

self.addEventListener('message', function (event) {
  var data = event.data || {};

  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }

  if (data.type === 'LOGOUT') {
    event.waitUntil(
      (function () {
        return caches.keys().then(function (keys) {
          return Promise.all(
            keys.map(function (key) {
              return caches.delete(key);
            })
          );
        }).then(function () {
          if (event.ports && event.ports[0]) {
            event.ports[0].postMessage({ ok: true });
          }
        });
      })()
    );
  }
});
