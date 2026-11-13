/* ==========================================================================
   MtaaPass service worker
   Requirements: Blueprint §46, §47, contract §10, offline-access contract §5.
   - versioned caches with safe activate cleanup (v8)
   - precached offline shell, CSS, JS (incl. offline-access.js) and icons
   - navigations: network-first, offline.html fallback (the offline access card)
   - static assets: stale-while-revalidate
   - /api/*, /health and authenticated QR images are never cached/intercepted
   - LOGOUT message clears every cache; SKIP_WAITING applies an update
   Authentication secrets and sensitive API payloads are never stored.
   ========================================================================== */

'use strict';

var VERSION = 'v9';
var SHELL_CACHE = 'mtaapass-shell-v9';
var STATIC_CACHE = 'mtaapass-static-v9';
var OFFLINE_URL = '/offline.html';

// Authenticated credential responses are never intercepted. The resident QR
// image is a no-store credential response; the page script fetches it directly
// with credentials and stores the encrypted bytes in IndexedDB instead.
var QR_IMAGE_PATTERN = /^\/app\/passes\/[^/]+\/qr\.png$/;

// The offline shell and static assets are precached. HTML pages are never
// cached: a navigation response may be an authenticated, personal page and must
// not become readable from a shared device after the fact.
var PRECACHE = [
  OFFLINE_URL,
  '/manifest.json',
  '/assets/css/app.css',
  '/assets/js/theme.js',
  '/assets/js/offline-access.js',
  '/assets/js/app.js',
  '/assets/img/estate-hero.png',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-512.png',
  '/assets/icons/apple-touch-icon.png',
  '/assets/icons/favicon.png'
];

function isNeverCached(pathname) {
  return pathname.indexOf('/api/') === 0
    || pathname === '/health'
    || pathname === '/api/v1/health'
    || QR_IMAGE_PATTERN.test(pathname);
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      return Promise.all(
        PRECACHE.map(function (url) {
          // Best-effort precache: each URL is added independently and a missing
          // or failing asset only skips that URL. The install therefore always
          // completes, a broken precache entry can never trap clients on an old
          // cache version, and the next activate pass cleans the previous caches.
          return cache
            .add(new Request(url, { cache: 'reload' }))
            .catch(function () {
              // One missing asset must never fail the whole install.
            });
        })
      );
    }).then(function () {
      // Activate immediately. Without this an updated worker stays "waiting"
      // while the old worker keeps serving stale CSS/JS from the previous
      // cache version (the deploy then shows new markup with old styling).
      return self.skipWaiting();
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
    return; // never cached, never intercepted: API, health and QR credentials
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
    var requestUrl = null;

    try {
      requestUrl = new URL(request.url);
    } catch (error) {
      requestUrl = null;
    }

    // Exact-match first (including the ?v=mtime-size asset version): a deploy
    // produces a new version query, so it must go to the network instead of
    // replaying the previous app.css / app.js from this cache.
    return cache.match(request).then(function (cached) {
      var network = fetch(request)
        .then(function (response) {
          if (response && response.ok && response.status === 200) {
            cache.put(request, response.clone());

            // Drop older version-query copies of the same path so the cache does
            // not accumulate one stale entry per deploy.
            if (requestUrl) {
              return cache.keys().then(function (keys) {
                return Promise.all(
                  keys.map(function (key) {
                    if (key.url === request.url) {
                      return null;
                    }

                    try {
                      if (new URL(key.url).pathname === requestUrl.pathname) {
                        return cache.delete(key);
                      }
                    } catch (error) {
                      // Ignore malformed cached keys.
                    }

                    return null;
                  })
                );
              }).then(function () {
                return response;
              });
            }

            return response;
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

        // Offline: fall back to any copy of the same path (for example the
        // unversioned precache entry) so the shell still renders.
        return cache.match(request, { ignoreSearch: true }).then(function (fallback) {
          if (fallback) {
            return fallback;
          }

          return new Response('', { status: 504, statusText: 'Gateway Timeout' });
        });
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
