# Offline Access Pass — contract (resident PIN/QR caching & offline support)

**Status:** IMPLEMENTING (owner request 2026-11-12) · **Owner goal:** faster resident app loading and
the resident's **access QR (and PIN where saved) must be viewable without data**.
**Scope type:** client-side/serving-layer only — **no database migration, no new permission node,
no new PHP route required** (candidate optional BFF endpoint only if strictly needed; see §7).
**Related:** `docs/visitor-access-module-contract.md` (§7 pass screen), `docs/foundation-contract.md`
§1 ground rules, `docs/team-execution-contract.md`, `README.md` §7 security controls / §8 testing.

## 1. Problem

The resident app shell and static assets are precached, but every navigation is network-first and
ONLY `offline.html` is shown offline. The pass screen (`/app/passes/{uuid}`), the QR sheet and the
QR image endpoint (`/app/passes/{uuid}/qr.png`) are authenticated, `Cache-Control: no-store`
responses that are deliberately never HTTP-cached. Result: a resident with no data cannot load the
app or show their gate QR/PIN — exactly when they most need it (at the gate).

## 2. Non-negotiable security invariants (unchanged)

1. **No authenticated HTML page and no `/api/*` or credential response is ever stored in an HTTP
   cache or the service-worker cache.** `Cache-Control: no-store` responses stay no-store; the
   service worker continues to `return` (never intercept/cache) `/api/*`, `/health` and
   `/app/passes/*/qr.png`.
2. **PIN remains unrecoverable server-side.** It is stored only as `HMAC(access-pin:{credential_uuid})`;
   no endpoint reveals an existing PIN. Offline PIN availability is therefore **opt-in at the moment
   the PIN is displayed** (creation or rotation). Passes whose PIN was never saved show QR + an
   honest "PIN not saved on this device" state and a rotate-while-online action. (Owner may later
   request reversible PIN storage using the existing `access_credentials.encrypted_secret` column;
   that is an explicit security-model decision and is OUT of scope here.)
3. **The on-device credential cache is encrypted at rest** with WebCrypto AES-256-GCM using a
   **non-extractable** device key generated per origin/profile and stored in IndexedDB. No plaintext
   PIN, QR bytes or bearer token is written to `localStorage`, `sessionStorage`, cookies, or the
   HTTP cache.
4. **Minimum necessary data**: pass metadata (visitor name, household label, validity window, status
   label), QR PNG bytes, and — only if the resident explicitly saved it — the PIN. No public pass
   token, no host phone/email, no user id, no session id.
5. **TTL + purge**: each cached pass expires at its `valid_until` (hard purge on read and on app
   start). Revoked/checked-out/expired passes are purged the next time the app is online and the
   authoritative pass state is fetched. Sign-out (online or offline) clears the credential cache,
   deletes the wrapped key, and posts the existing `LOGOUT` message to the service worker.
6. **Scope of exposure**: the cache is per browser profile and origin; it is never synced server-side
   and never included in deploy bundles/logs. It is not a substitute for device biometrics — the
   threat model (shared short-term device) is documented, not eliminated.

## 3. Offline access UX

1. **Service worker `v8`** (`mtaapass-shell-v8` / `mtaapass-static-v8`): precache adds
   `/assets/js/offline-access.js`. Navigations stay **network-first** (fast when online); on network
   failure the SW serves `/offline.html`.
2. **`/offline.html` becomes the offline access page**: it loads `offline-access.js` (deferred) and
   renders, from the encrypted IndexedDB cache, the resident's **Access pass card**: visitor name,
   household label, validity ("Valid until …"), status, QR image (decrypted blob → object URL), and
   the saved PIN if present — clearly labelled **"Saved on this device · last updated <time>"** and
   **"Offline — gate staff can verify this QR/PIN when the gate is online"**. If nothing is cached
   or everything expired: the existing honest "You are offline" message + a "Retry" action.
   No dynamically-fetched data and no inline JS are allowed (CSP unchanged).
3. **Faster/robust online path**: while online, the resident app caches/refreshes the **next pass**
   (from the QR sheet data already rendered in the shell) and the **current pass** being viewed
   (pass screen), including the QR image, so the card is available immediately offline. The cached
   card is refreshed on each online pass view; the QR image is fetched by the page script with
   credentials and stored encrypted (never via HTTP cache).
4. **QR sheet while offline** (app already open): if the QR image request fails or
   `navigator.onLine === false`, the sheet falls back to the cached QR/PIN for the same pass and
   shows the saved-on-this-device note. Opening the sheet online refreshes the cache.
5. **Save PIN for offline**: on the pass screen where the PIN is displayed (creation/rotation), add an
   explicit "Save PIN on this device for offline access" control. Only then is the PIN cached.
   Elsewhere the pass card keeps a non-alarming hint and the existing "Rotate PIN" action.
6. **Clear on sign-out** via the existing logout flow (`data-logout` handler): clear credential
   cache + key before/with the SW `LOGOUT` message; a request to `/logout` while offline still clears
   local credentials first.

## 4. Data model (IndexedDB, client-side)

Database `mtaapass-access` v1, object stores:
- `keys`: `{id:'device', key: CryptoKey}` — AES-GCM 256, `extractable: false`.
- `passes`: keyPath `uuid`; value `{uuid, name, household, validUntil, statusLabel, savedAt,
  pinEnc: {iv, data}|null, qrEnc: {iv, data}|null}` (ciphertext ArrayBuffers; the pin/qr plaintext
  exists only transiently in memory/object URL).

Purge rules: read-time check `validUntil <= now` → delete; on app start `purgeExpired()`; on pass
screen online render with terminal status (`revoked`, `checked_out`, `expired`, `denied`) → delete.

## 5. Service-worker changes (v8)

- Version bump + precache `offline-access.js`.
- `/api/*`, `/health`, `/app/passes/*/qr.png` remain **never intercepted** (unchanged).
- `LOGOUT` message also clears only the SW caches as today; credential cache clearing is owned by
  the page script (it deletes IndexedDB before posting LOGOUT; the SW handler also replies ok).
- Navigation fallback: `/offline.html` (unchanged mechanism) which is now the access card.
- No authenticated page or API response is ever put in any cache (static test asserts this).

## 6. Acceptance evidence (required)

- `php -l` clean for changed PHP; updated/added static tests: SW v8 cache names, precache includes
  `offline-access.js`, `/api/*` + `/health` never cached, no authenticated-page caching, offline.html
  loads `offline-access.js` and contains no inline script, offline-access.js uses AES-GCM +
  non-extractable key + IndexedDB + no localStorage/sessionStorage/cookie writes of PIN/QR.
- Browser E2E (Chrome/Edge headless via CDP, evidence under `storage/temporary/qa-evidence/`):
  (a) online load caches next pass + QR; (b) network offline → navigation shows offline access card
  with decrypted QR (and PIN after explicit save); (c) QR sheet opens offline from cache;
  (d) sign-out clears IndexedDB + SW caches; (e) expired pass is purged; (f) IndexedDB contains no
  plaintext PIN/QR (check raw values) and `keys.device` is non-extractable.
- Existing suites re-run (`Resident*`, `PwaInstallStaticTest`, `VisitorSecurityTest`, `HeadersTest`)
  and the exclusive full suite after the change; no regression in route/permission/DB gates.

## 7. Out of scope / owner questions

- Offline **gate verification/check-in**, offline visitor invites, offline PIN rotation — server
  authority remains required for gate operations.
- PIN revocability model: HMAC + show-once is preserved; reversible PIN storage
  (`encrypted_secret`) is an owner decision, not implemented here.
- Background sync/outbox for offline writes — not in scope.
- Optional future: per-device "remember pass" opt-out UI, WebAuthn-wrapped key.
