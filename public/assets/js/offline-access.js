/* ==========================================================================
   MtaaPass offline access pass
   --------------------------------------------------------------------------
   Keeps the resident's gate QR (and, only when explicitly saved, the access
   PIN) viewable without a network connection.

   Security model (docs/offline-access-contract.md §2):
   - The cache lives in IndexedDB (`mtaapass-access` v1) and is encrypted at
     rest with WebCrypto AES-256-GCM using a per-origin device key generated
     once with `extractable: false` (the raw key bytes can never be exported).
   - Only the minimum necessary data is stored: visitor name, household label,
     validity window, status label, the QR PNG bytes and, only after the
     resident presses "Save PIN on this device", the PIN.
   - No bearer pass token, host phone/email, user id or session id is stored.
   - Nothing credential-shaped is written to browser key-value storage or
     cookies; plaintext exists only transiently in memory and in object URLs,
     which are revoked when they are no longer displayed.
   - Cached passes expire at `valid_until` (checked on read and purged on app
     start) and are wiped on sign-out, online or offline.
   - QR images are fetched with same-origin credentials and never stored in an
     HTTP cache; the service worker never intercepts `/app/passes/{uuid}/qr.png`.

   Public API (window.OfflineAccess): savePass, getPass, loadForOffline,
   purgeExpired, clearAll, removePass, renderOfflineCard, attachToQrSheet,
   attachToPassPage, isSupported.
   ========================================================================== */

'use strict';

(function (window, document) {
  var DB_NAME = 'mtaapass-access';
  var DB_VERSION = 1;
  var KEY_STORE = 'keys';
  var PASS_STORE = 'passes';
  var DEVICE_KEY_ID = 'device';
  // AES-GCM 256-bit device key. It is generated as non-extractable, so the raw
  // key material can never leave WebCrypto; only encrypt/decrypt are possible.
  var KEY_ALGORITHM = { name: 'AES-GCM', length: 256 };
  var IV_BYTES = 12;
  var TERMINAL_STATUSES = ['revoked', 'checked_out', 'expired', 'denied'];
  var SAVED_NOTE = 'Saved on this device · last updated ';
  var trackedObjectUrls = [];
  var dbPromise = null;
  var qrSheetAttached = false;

  /* ------------------------------------------------------------------ */
  /* Small helpers                                                       */
  /* ------------------------------------------------------------------ */

  function isSupported() {
    return !!(
      window.indexedDB &&
      window.crypto &&
      window.crypto.subtle &&
      typeof window.crypto.subtle.generateKey === 'function' &&
      typeof window.TextEncoder === 'function' &&
      typeof window.TextDecoder === 'function'
    );
  }

  function trim(value) {
    return value === null || value === undefined ? '' : String(value).trim();
  }

  function statusLabelFallback(status) {
    var labels = {
      pending: 'Pending',
      approved: 'Approved',
      checked_in: 'Inside',
      checked_out: 'Checked out',
      revoked: 'Revoked',
      expired: 'Expired',
      denied: 'Denied'
    };

    return labels[status] || 'Saved pass';
  }

  function statusClass(status) {
    if (status === 'approved' || status === 'checked_in') {
      return 'status--active';
    }
    if (status === 'pending') {
      return 'status--pending';
    }
    if (status === 'revoked' || status === 'denied') {
      return 'status--danger';
    }

    return 'status--inactive';
  }

  function parseMoment(value) {
    if (value === null || value === undefined || value === '') {
      return 0;
    }
    var time = Date.parse(String(value));

    return isNaN(time) ? 0 : time;
  }

  function isExpired(record) {
    var limit = parseMoment(record && record.validUntil);

    return limit <= 0 || limit <= Date.now();
  }

  function formatMoment(value) {
    var time = typeof value === 'number' ? value : parseMoment(value);
    if (!time) {
      return '-';
    }
    try {
      return new Date(time).toLocaleString();
    } catch (error) {
      return '-';
    }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (typeof text === 'string') {
      node.textContent = text;
    }

    return node;
  }

  function trackObjectUrl(url) {
    trackedObjectUrls.push(url);

    return url;
  }

  function revokeObjectUrl(url) {
    if (!url) {
      return;
    }
    var index = trackedObjectUrls.indexOf(url);
    if (index !== -1) {
      trackedObjectUrls.splice(index, 1);
    }
    try {
      window.URL.revokeObjectURL(url);
    } catch (error) {
      /* best effort */
    }
  }

  /* ------------------------------------------------------------------ */
  /* IndexedDB plumbing                                                  */
  /* ------------------------------------------------------------------ */

  function openDb() {
    if (!isSupported()) {
      return Promise.reject(new Error('Offline access storage is unavailable.'));
    }
    if (dbPromise) {
      return dbPromise;
    }

    dbPromise = new Promise(function (resolve, reject) {
      var request;

      try {
        request = window.indexedDB.open(DB_NAME, DB_VERSION);
      } catch (error) {
        reject(error);

        return;
      }

      request.onupgradeneeded = function () {
        var db = request.result;
        if (!db.objectStoreNames.contains(KEY_STORE)) {
          db.createObjectStore(KEY_STORE, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(PASS_STORE)) {
          db.createObjectStore(PASS_STORE, { keyPath: 'uuid' });
        }
      };
      request.onsuccess = function () {
        resolve(request.result);
      };
      request.onerror = function () {
        reject(request.error || new Error('Offline access storage could not be opened.'));
      };
    });

    return dbPromise;
  }

  function readStore(storeName, action) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx;

        try {
          tx = db.transaction(storeName, 'readonly');
          var request = action(tx.objectStore(storeName));
          request.onsuccess = function () {
            resolve(request.result);
          };
          request.onerror = function () {
            reject(request.error || new Error('Offline access read failed.'));
          };
        } catch (error) {
          reject(error);
        }
      });
    });
  }

  function writeStore(storeName, action) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx;
        var result;

        try {
          tx = db.transaction(storeName, 'readwrite');
          var request = action(tx.objectStore(storeName));
          request.onsuccess = function () {
            result = request.result;
          };
        } catch (error) {
          reject(error);

          return;
        }

        tx.oncomplete = function () {
          resolve(result);
        };
        tx.onerror = function () {
          reject(tx.error || new Error('Offline access write failed.'));
        };
        tx.onabort = function () {
          reject(tx.error || new Error('Offline access write aborted.'));
        };
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Crypto: non-extractable device key + AES-GCM envelopes              */
  /* ------------------------------------------------------------------ */

  function getDeviceKey() {
    return readStore(KEY_STORE, function (store) {
      return store.get(DEVICE_KEY_ID);
    }).then(function (record) {
      if (record && record.key) {
        return record.key;
      }

      return window.crypto.subtle.generateKey(KEY_ALGORITHM, false, ['encrypt', 'decrypt']).then(function (key) {
        return writeStore(KEY_STORE, function (store) {
          return store.put({ id: DEVICE_KEY_ID, key: key });
        }).then(function () {
          return key;
        });
      });
    });
  }

  function encryptBytes(key, bytes) {
    var iv = window.crypto.getRandomValues(new Uint8Array(IV_BYTES));

    return window.crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, bytes).then(function (ciphertext) {
      return { iv: iv, data: ciphertext };
    });
  }

  function decryptBytes(key, envelope) {
    if (!envelope || !envelope.iv || !envelope.data) {
      return Promise.resolve(null);
    }

    return window.crypto.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(envelope.iv) }, key, envelope.data).catch(function () {
      return null;
    });
  }

  function toArrayBuffer(value) {
    if (!value) {
      return Promise.resolve(null);
    }
    if (value instanceof ArrayBuffer) {
      return Promise.resolve(value);
    }
    if (typeof value.arrayBuffer === 'function') {
      return value.arrayBuffer().catch(function () {
        return null;
      });
    }
    if (value.buffer instanceof ArrayBuffer) {
      return Promise.resolve(value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength));
    }

    return Promise.resolve(null);
  }

  function encodeText(text) {
    return new window.TextEncoder().encode(String(text));
  }

  function decodeText(bytes) {
    if (!bytes) {
      return '';
    }

    return new window.TextDecoder().decode(new Uint8Array(bytes));
  }

  /* ------------------------------------------------------------------ */
  /* Pass cache operations                                               */
  /* ------------------------------------------------------------------ */

  /**
   * Cache one pass. Only `uuid`, `validUntil` and metadata are required; PIN and
   * QR bytes are optional and only ever stored encrypted.
   *
   * @param {Object} options {uuid, name, household, validUntil, statusLabel,
   *                          status, qrBytes, pin}
   * @return {Promise<boolean>} true when a record was written.
   */
  function savePass(options) {
    options = options || {};
    var uuid = trim(options.uuid);

    if (!uuid || !isSupported()) {
      return Promise.resolve(false);
    }

    var validUntil = trim(options.validUntil);
    if (parseMoment(validUntil) <= Date.now()) {
      return Promise.resolve(false);
    }

    var pinValue = typeof options.pin === 'string' ? trim(options.pin) : '';

    return Promise.all([
      getDeviceKey(),
      readStore(PASS_STORE, function (store) {
        return store.get(uuid);
      }),
      toArrayBuffer(options.qrBytes)
    ]).then(function (results) {
      var key = results[0];
      var existing = results[1];
      var qrBytes = results[2];
      var qrEnc = existing && existing.qrEnc ? existing.qrEnc : null;
      var pinEnc = existing && existing.pinEnc ? existing.pinEnc : null;
      var encrypt = [];

      if (qrBytes) {
        encrypt.push(
          encryptBytes(key, qrBytes).then(function (envelope) {
            qrEnc = envelope;
          })
        );
      }
      if (pinValue !== '') {
        encrypt.push(
          encryptBytes(key, encodeText(pinValue)).then(function (envelope) {
            pinEnc = envelope;
          })
        );
      }

      return Promise.all(encrypt).then(function () {
        var record = {
          uuid: uuid,
          name: trim(options.name) || 'Visitor',
          household: trim(options.household),
          validUntil: validUntil,
          status: trim(options.status),
          statusLabel: trim(options.statusLabel) || statusLabelFallback(trim(options.status)),
          savedAt: Date.now(),
          qrEnc: qrEnc,
          pinEnc: pinEnc
        };

        return writeStore(PASS_STORE, function (store) {
          return store.put(record);
        }).then(function () {
          return true;
        });
      });
    }).catch(function () {
      return false;
    });
  }

  /**
   * Read and decrypt one cached pass. Expired passes are deleted and resolve to
   * null. Resolves with {uuid, name, household, validUntil, status,
   * statusLabel, savedAt, pin, qrBlob}.
   */
  function getPass(uuid) {
    var id = trim(uuid);

    if (!id || !isSupported()) {
      return Promise.resolve(null);
    }

    return readStore(PASS_STORE, function (store) {
      return store.get(id);
    }).then(function (record) {
      if (!record) {
        return null;
      }
      if (isExpired(record)) {
        return removePass(id).then(function () {
          return null;
        });
      }

      return getDeviceKey().then(function (key) {
        return Promise.all([
          decryptBytes(key, record.qrEnc),
          decryptBytes(key, record.pinEnc)
        ]).then(function (parts) {
          return {
            uuid: record.uuid,
            name: record.name,
            household: record.household,
            validUntil: record.validUntil,
            status: record.status || '',
            statusLabel: record.statusLabel || statusLabelFallback(record.status || ''),
            savedAt: record.savedAt,
            qrBlob: parts[0] ? new window.Blob([parts[0]], { type: 'image/png' }) : null,
            pin: parts[1] ? decodeText(parts[1]) : null
          };
        });
      });
    }).catch(function () {
      return null;
    });
  }

  /**
   * The most recently saved unexpired pass, for /offline.html.
   */
  function loadForOffline() {
    if (!isSupported()) {
      return Promise.resolve(null);
    }

    return purgeExpired().then(function () {
      return readStore(PASS_STORE, function (store) {
        return store.getAll();
      });
    }).then(function (records) {
      if (!records || !records.length) {
        return null;
      }

      var candidates = records.filter(function (record) {
        return !isExpired(record);
      });
      candidates.sort(function (a, b) {
        return (b.savedAt || 0) - (a.savedAt || 0);
      });

      return candidates.length ? getPass(candidates[0].uuid) : null;
    }).catch(function () {
      return null;
    });
  }

  function removePass(uuid) {
    var id = trim(uuid);

    if (!id || !isSupported()) {
      return Promise.resolve(false);
    }

    return writeStore(PASS_STORE, function (store) {
      return store.delete(id);
    }).then(function () {
      return true;
    }).catch(function () {
      return false;
    });
  }

  /**
   * Delete every pass whose validity window has ended.
   */
  function purgeExpired() {
    if (!isSupported()) {
      return Promise.resolve(0);
    }

    return readStore(PASS_STORE, function (store) {
      return store.getAll();
    }).then(function (records) {
      var expired = (records || []).filter(isExpired).map(function (record) {
        return record.uuid;
      });

      return expired.reduce(function (chain, uuid) {
        return chain.then(function (count) {
          return removePass(uuid).then(function () {
            return count + 1;
          });
        });
      }, Promise.resolve(0));
    }).catch(function () {
      return 0;
    });
  }

  /**
   * Sign-out / panic wipe: delete the encrypted passes and the wrapped device
   * key, and revoke any live object URLs. Never rejects.
   */
  function clearAll() {
    trackedObjectUrls.slice().forEach(revokeObjectUrl);

    if (!isSupported()) {
      return Promise.resolve(false);
    }

    return openDb().then(function (db) {
      return new Promise(function (resolve) {
        var tx;

        try {
          tx = db.transaction([PASS_STORE, KEY_STORE], 'readwrite');
          tx.objectStore(PASS_STORE).clear();
          tx.objectStore(KEY_STORE).clear();
        } catch (error) {
          resolve(false);

          return;
        }

        tx.oncomplete = function () {
          resolve(true);
        };
        tx.onerror = function () {
          resolve(false);
        };
        tx.onabort = function () {
          resolve(false);
        };
      });
    }).catch(function () {
      return false;
    });
  }

  /* ------------------------------------------------------------------ */
  /* /offline.html access pass card                                      */
  /* ------------------------------------------------------------------ */

  function renderSavedStatus(container, pass) {
    var meta = el('p', 'offline-access__meta');
    if (pass.household) {
      meta.appendChild(el('span', null, pass.household));
    }
    meta.appendChild(el('span', 'status ' + statusClass(pass.status), pass.statusLabel));
    container.appendChild(meta);

    var validity = el('p', 'offline-access__validity');
    validity.textContent = 'Valid until ' + formatMoment(pass.validUntil);
    container.appendChild(validity);

    if (pass.qrBlob) {
      var wrap = el('div', 'offline-access__qr-wrap');
      var image = el('img', 'offline-access__qr');
      image.alt = 'Access QR code';
      image.width = 220;
      image.height = 220;
      image.decoding = 'async';
      image.src = trackObjectUrl(window.URL.createObjectURL(pass.qrBlob));
      wrap.appendChild(image);
      container.appendChild(wrap);
    } else {
      container.appendChild(el('p', 'offline-card__text offline-card__text--small', 'The QR image is not saved on this device. Reconnect and reopen the pass to save it.'));
    }

    if (pass.pin) {
      container.appendChild(el('p', 'offline-access__label', 'Access PIN (saved on this device)'));
      container.appendChild(el('p', 'offline-access__pin', pass.pin));
    } else {
      container.appendChild(el('p', 'offline-card__text offline-card__text--small', 'The access PIN is not saved on this device. Rotate it while online to view and save a new one.'));
    }

    container.appendChild(el('p', 'offline-access__saved', SAVED_NOTE + formatMoment(pass.savedAt)));
    container.appendChild(el('p', 'offline-access__note', 'Offline, gate staff can verify this QR/PIN when the gate is online.'));
  }

  /**
   * Render the cached access pass into `container`. Returns true when a pass was
   * rendered (the caller can then hide its "You are offline" fallback); false
   * when nothing usable is cached.
   */
  function renderOfflineCard(container) {
    if (!container || typeof window.URL === 'undefined' || typeof window.URL.createObjectURL !== 'function') {
      return Promise.resolve(false);
    }

    return loadForOffline().then(function (pass) {
      if (!pass) {
        return false;
      }

      while (container.firstChild) {
        container.removeChild(container.firstChild);
      }

      container.appendChild(el('p', 'offline-access__eyebrow', 'Your saved pass'));
      container.appendChild(el('h2', 'offline-access__name', pass.name || 'Visitor'));
      renderSavedStatus(container, pass);
      container.appendChild(el('p', 'offline-access__note', 'This pass is stored encrypted on this device only. Signing out removes it.'));
      container.removeAttribute('hidden');

      return true;
    }).catch(function () {
      return false;
    });
  }

  /* ------------------------------------------------------------------ */
  /* QR sheet hook (app shell, also works while offline)                 */
  /* ------------------------------------------------------------------ */

  function sheetMeta(sheet) {
    return {
      uuid: trim(sheet.getAttribute('data-pass-uuid')),
      name: trim(sheet.getAttribute('data-visitor-name')),
      household: trim(sheet.getAttribute('data-household')),
      validUntil: trim(sheet.getAttribute('data-valid-until-iso')),
      status: trim(sheet.getAttribute('data-status')),
      statusLabel: trim(sheet.getAttribute('data-status-label'))
    };
  }

  function qrSheetNote(sheet) {
    var pass = sheet.querySelector('.qr-sheet__pass');
    if (!pass) {
      return null;
    }
    var note = pass.querySelector('[data-qr-offline-note]');
    if (!note) {
      note = el('p', 'qr-sheet__offline-note');
      note.setAttribute('data-qr-offline-note', '1');
      note.hidden = true;
      pass.appendChild(note);
    }

    return note;
  }

  function qrSheetPin(sheet) {
    var pass = sheet.querySelector('.qr-sheet__pass');
    if (!pass) {
      return null;
    }
    var pin = pass.querySelector('[data-qr-offline-pin]');
    if (!pin) {
      pin = el('p', 'qr-sheet__offline-pin');
      pin.setAttribute('data-qr-offline-pin', '1');
      pin.hidden = true;
      pass.appendChild(pin);
    }

    return pin;
  }

  function clearSheetCache(sheet) {
    if (sheet._offlineObjectUrl) {
      revokeObjectUrl(sheet._offlineObjectUrl);
      sheet._offlineObjectUrl = null;
    }
    var image = sheet.querySelector('[data-qr-image]');
    if (image && image.getAttribute('data-qr-cached') === '1') {
      image.removeAttribute('data-qr-cached');
      var qrUrl = sheet.getAttribute('data-qr-url') || '';
      if (qrUrl) {
        image.src = qrUrl;
      }
    }
    var note = qrSheetNote(sheet);
    if (note) {
      note.hidden = true;
    }
    var pin = qrSheetPin(sheet);
    if (pin) {
      pin.hidden = true;
      pin.textContent = '';
    }
  }

  function applyCachedPassToSheet(sheet, pass) {
    var image = sheet.querySelector('[data-qr-image]');
    if (image && pass.qrBlob) {
      if (sheet._offlineObjectUrl) {
        revokeObjectUrl(sheet._offlineObjectUrl);
      }
      sheet._offlineObjectUrl = trackObjectUrl(window.URL.createObjectURL(pass.qrBlob));
      image.src = sheet._offlineObjectUrl;
      image.setAttribute('data-qr-cached', '1');
    }

    var note = qrSheetNote(sheet);
    if (note) {
      note.textContent = SAVED_NOTE + formatMoment(pass.savedAt);
      note.hidden = false;
    }

    var pin = qrSheetPin(sheet);
    if (pin && pass.pin) {
      pin.textContent = 'Access PIN: ' + pass.pin;
      pin.hidden = false;
    }
  }

  function fallbackQrSheet(sheet, meta) {
    if (!meta.uuid) {
      return Promise.resolve(false);
    }

    return getPass(meta.uuid).then(function (pass) {
      if (!pass) {
        var note = qrSheetNote(sheet);
        if (note) {
          note.textContent = 'Offline, this pass is not saved on this device yet.';
          note.hidden = false;
        }

        return false;
      }
      applyCachedPassToSheet(sheet, pass);

      return true;
    });
  }

  function refreshQrSheet(sheet) {
    var hasPass = sheet.getAttribute('data-has-pass') === '1';
    if (!hasPass) {
      return;
    }

    var meta = sheetMeta(sheet);
    if (!meta.uuid) {
      return;
    }

    var online = typeof navigator.onLine === 'boolean' ? navigator.onLine : true;
    if (!online) {
      fallbackQrSheet(sheet, meta);

      return;
    }

    var qrUrl = sheet.getAttribute('data-qr-url') || '';
    if (!qrUrl) {
      return;
    }

    // Authenticated, no-store QR image: fetch with same-origin credentials and
    // encrypt the bytes into the offline cache. The service worker deliberately
    // never intercepts this URL, so it can never enter an HTTP cache.
    window.fetch(qrUrl, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (!response || !response.ok) {
          throw new Error('QR image request failed.');
        }

        return response.blob();
      })
      .then(function (blob) {
        return savePass({
          uuid: meta.uuid,
          name: meta.name,
          household: meta.household,
          validUntil: meta.validUntil,
          status: meta.status,
          statusLabel: meta.statusLabel,
          qrBytes: blob
        });
      })
      .then(function (saved) {
        if (saved) {
          clearSheetCache(sheet);
        }
      })
      .catch(function () {
        fallbackQrSheet(sheet, meta);
      });
  }

  /**
   * Wire the app-shell QR sheet: refresh the encrypted cache when it opens
   * online, and fall back to the cached QR/PIN when the network fails.
   */
  function attachToQrSheet() {
    if (qrSheetAttached) {
      return;
    }

    var sheet = document.getElementById('qr-sheet');
    if (!sheet) {
      return;
    }
    qrSheetAttached = true;

    if (!isSupported()) {
      return;
    }

    var openers = document.querySelectorAll('[data-qr-open]');
    for (var i = 0; i < openers.length; i++) {
      openers[i].addEventListener('click', function () {
        refreshQrSheet(sheet);
      });
    }

    var image = sheet.querySelector('[data-qr-image]');
    if (image) {
      image.addEventListener('error', function () {
        var meta = sheetMeta(sheet);
        if (meta.uuid) {
          fallbackQrSheet(sheet, meta);
        }
      });
    }

    sheet.addEventListener('close', function () {
      clearSheetCache(sheet);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Pass screen hook (online cache + offline PIN save + terminal purge) */
  /* ------------------------------------------------------------------ */

  /**
   * Wire the full pass screen. Terminal-status passes are purged; a live pass
   * has its QR refreshed into the encrypted cache; the displayed PIN is cached
   * only when the resident presses the explicit "Save PIN" control.
   */
  function attachToPassPage() {
    var root = document.querySelector('[data-offline-pass]');
    if (!root) {
      return;
    }

    var meta = {
      uuid: trim(root.getAttribute('data-offline-uuid')),
      name: trim(root.getAttribute('data-offline-visitor')),
      household: trim(root.getAttribute('data-offline-household')),
      validUntil: trim(root.getAttribute('data-offline-valid-until')),
      status: trim(root.getAttribute('data-offline-status')),
      statusLabel: trim(root.getAttribute('data-offline-status-label')),
      qrUrl: trim(root.getAttribute('data-offline-qr-url'))
    };

    if (!meta.uuid || !isSupported()) {
      return;
    }

    if (TERMINAL_STATUSES.indexOf(meta.status) !== -1) {
      removePass(meta.uuid);

      return;
    }

    var online = typeof navigator.onLine === 'boolean' ? navigator.onLine : true;

    if (online && meta.qrUrl) {
      window.fetch(meta.qrUrl, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) {
          if (!response || !response.ok) {
            throw new Error('QR image request failed.');
          }

          return response.blob();
        })
        .then(function (blob) {
          return savePass({
            uuid: meta.uuid,
            name: meta.name,
            household: meta.household,
            validUntil: meta.validUntil,
            status: meta.status,
            statusLabel: meta.statusLabel,
            qrBytes: blob
          });
        })
        .catch(function () {
          /* caching is best effort; the server-rendered page still works */
        });
    }

    var saveButton = root.querySelector('[data-offline-save-pin]');
    if (!saveButton) {
      return;
    }

    var pinElement = document.querySelector('[data-pass-pin]');
    var noteElement = root.querySelector('[data-offline-save-note]');

    saveButton.addEventListener('click', function () {
      var pin = pinElement ? trim(pinElement.textContent) : '';
      if (pin === '') {
        if (noteElement) {
          noteElement.textContent = 'No PIN is displayed on this page to save.';
        }

        return;
      }

      saveButton.disabled = true;

      savePass({
        uuid: meta.uuid,
        name: meta.name,
        household: meta.household,
        validUntil: meta.validUntil,
        status: meta.status,
        statusLabel: meta.statusLabel,
        pin: pin
      }).then(function (saved) {
        saveButton.disabled = false;
        if (noteElement) {
          noteElement.hidden = false;
          noteElement.textContent = saved
            ? 'Saved for offline viewing on this device. Signing out removes it.'
            : 'This pass could not be saved for offline use on this device.';
        }
      }).catch(function () {
        saveButton.disabled = false;
        if (noteElement) {
          noteElement.hidden = false;
          noteElement.textContent = 'This pass could not be saved for offline use on this device.';
        }
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Boot                                                                */
  /* ------------------------------------------------------------------ */

  window.OfflineAccess = {
    isSupported: isSupported,
    savePass: savePass,
    getPass: getPass,
    loadForOffline: loadForOffline,
    purgeExpired: purgeExpired,
    removePass: removePass,
    clearAll: clearAll,
    renderOfflineCard: renderOfflineCard,
    attachToQrSheet: attachToQrSheet,
    attachToPassPage: attachToPassPage
  };

  // /offline.html renders the access card automatically when the markup asks
  // for it. The resident app wires the hooks explicitly from app.js.
  function autoInit() {
    var container = document.querySelector('[data-offline-access]');
    if (!container) {
      return;
    }

    renderOfflineCard(container).then(function (rendered) {
      if (!rendered) {
        return;
      }
      var fallback = document.querySelector('[data-offline-fallback]');
      if (fallback) {
        fallback.setAttribute('hidden', '');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }
})(window, document);
