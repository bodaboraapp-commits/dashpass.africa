# Firebase phone auth (SMS OTP) — setup and operations guide

**Status: APPROVED DEFAULT OTP GATEWAY — implemented in dev (T127/T128; login UI on Firebase
**modular SDK 13.2.0**, updated web config per T131/T133); live activation pending tests + owner
console setup.** Firebase phone authentication is the default login provider:
`AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true`, project **`komiuta`**. The existing
server-generated OTP path (`AUTH_OTP_PROVIDER=server`) remains the fallback/rollback (§10).

**Key property:** the server **never sends SMS** and needs **no service-account JSON**. The
browser Firebase SDK sends and verifies the SMS code; the server receives a Firebase **ID token**
in `POST /login/verify` and verifies it against **Google's public x509 certificates**. The
Firebase web config is a set of public client identifiers (safe to render into the login page,
still kept out of git).

---

## 1. How it works (flow)

```
 Resident/guard/admin browser                     DashPASS server
 ─────────────────────────                       ───────────────
 1. GET /login  ◄────────────────────────────────  renders: Firebase web config in data-* attrs,
                                                   hidden firebase_token field, firebase-auth.js
 2. user taps "Send verification code" (JS intercepted; NO server POST)
 3. firebase-auth.js loads Firebase **modular SDK 13.2.0** via static ESM
    imports from https://www.gstatic.com/firebasejs/13.2.0/
    (external type="module" script; no compat globals, no inline config;
    analytics intentionally omitted)
 4. invisible reCAPTCHA (RecaptchaVerifier) runs in browser
 5. signInWithPhoneNumber(phone, verifier) ─────► Firebase sends the SMS code
 6. user enters the 6-digit code
 7. confirmationResult.confirm(code) → user.getIdToken(true)
 8. native form POST /login/verify               §  server: firebase.rate_limits.ip (20/900s)
    { firebase_token: <ID token> }  ────────────►    failure → 302 /login flash (audited)
                                                   FirebaseTokenVerifier::verify():
                                                   • Google public x509 certs (cached, max-age)
                                                   • alg=RS256 + known kid; iss/aud=komiuta
                                                   • exp/iat/auth_time (±60s leeway); non-empty sub
                                                   • phone_number normalized (Phone::normalize)
                                                   • 401 INVALID_FIREBASE_TOKEN / 422 FIREBASE_PHONE_INVALID
                                                   • 503 SERVICE_UNAVAILABLE if certs unreachable (fail-closed)
                                                   §  firebase.rate_limits.phone (10/900s, sha256 key)
                                                   §  active user lookup by verified phone
 9. session + CSRF rotated ◄─────────────────────  audit auth.firebase_login {provider, method:phone}
10. 302 /app (safe internal redirect) ◄──────────  failures audit auth.login_failed {provider, reason}
```

- **Identity phone = the token's `phone_number` claim**, not any submitted form value (the JS also
  posts the phone in the form, but the server uses the verified claim). There is **no new route**:
  the token rides the existing `POST /login/verify` route as a hidden `firebase_token` field.
- **In dev/test (`APP_ENV!==production`)** the legacy server OTP still works when a `code` is
  submitted and no `firebase_token` is present (keeps web-OTP tests/dev tooling usable).
  **Production always requires the Firebase token** — no code-only login path.
- With JS disabled under Firebase mode, the page shows a `noscript` message (phone verification
  needs JavaScript); the server OTP fallback applies only when `AUTH_OTP_PROVIDER=server`.
- The public web config is rendered only when `FIREBASE_SMS_ENABLED=true`, `provider=firebase`,
  and apiKey/authDomain/projectId/appId are non-empty; otherwise the legacy UI renders.
  **Server-side verification is bound to `provider`, never to the UI flag.**

## 1b. Activation state on live (2026-11-11)

**Status: HALF-ACTIVATED — T138 provider enforcement is live, but the Firebase web config is not.**
`/login` still renders the **legacy server-OTP UI**, so **no one can sign in live** (the form offers a
server code that the server refuses; the owner sees our guard message **“Code sign-in is not
available. Verify your phone number to continue.”**). Verified by the read-only T141 live audit
(`storage/temporary/live-build-parity.md`):

- **`AUTH_OTP_PROVIDER=firebase` IS active server-side:** `POST /api/v1/auth/request-otp` and
  `/verify-otp` with an empty `{}` payload (valid CSRF) return **403 `AUTH_PROVIDER_MISMATCH`** —
  the T138 guard (pre-T138 code returns 422 validation instead).
- **`FIREBASE_SMS_ENABLED=true` + the public `FIREBASE_*` web values are NOT active in the server
  `.env`**, so the login view falls back to the legacy form: **0** `data-firebase-auth`, **0**
  appId/measurement attrs, **0** module script. This web-config flag is the only missing piece.
- **Deployed fingerprint = `c6ecee9`** (2026-11-11 22:04): live PHP/auth code is current — Inc-9
  routes + T138 enforcement + platform routes. `/platform` and `/platform/estates` → `302 /login`
  when unauthenticated is **expected**; `/platform/billing` → **404** until the Inc-10 route gate is
  applied (also expected, not a deploy failure). The later HEAD `ebe607e` adds **only docs + a newer
  `public/assets/js/firebase-auth.js`** (no PHP change): live `firebase-auth.js` = 18,269 B sha256
  `c846702c…` (`c6ecee9`); HEAD = 19,350 B sha256 `ed622a03…`.
- CSP is already Firebase-ready; `/health` = **`0.2.1-dashpass`**, production, **applied=8 /
  pending=0**. **No migration and no `deploy-sync install` are needed for Firebase.**

**Owner-error → cause:** “Code sign-in is not available. Verify your phone number to continue.” on
`/login` = **provider=firebase is active but the browser/page is still the legacy UI** — either the
server `.env` web config is missing (`FIREBASE_SMS_ENABLED`/public values), the PHP env was not
reloaded, or the browser served a stale cached legacy page (SW/CF cache). Fix with the order below.

### Exact owner order (choose A now; B is the immediate unblock)

**A. Activate Firebase (recommended)** — live sign-in is currently broken:

1. **Deploy the latest pin `ebe607e` (or later)** — cPanel Git™ Version Control → pull `main` (or on
   the server: `git fetch --all && git reset --hard origin/main`; confirm `git rev-parse --short HEAD`
   = `ebe607e`+). This serves the newest `firebase-auth.js`. **No migration and no DB seed** — do
   **not** run any Inc-10 migration (still gated); `/health` must stay **applied=8 / pending=0**.
2. **Server `.env`** — keep **`AUTH_OTP_PROVIDER=firebase`** (already active) and add/verify
   **`FIREBASE_SMS_ENABLED=true`** (currently missing/false — this is why `/login` is legacy) plus
   all public `FIREBASE_*` (copy the public values from the local gitignored
   `.env.production.local`, project `komiuta`; never commit them):
   `FIREBASE_API_KEY`, `FIREBASE_AUTH_DOMAIN=komiuta.firebaseapp.com`,
   `FIREBASE_PROJECT_ID=komiuta`, `FIREBASE_STORAGE_BUCKET=komiuta.firebasestorage.app`,
   `FIREBASE_MESSAGING_SENDER_ID=99186909972`,
   `FIREBASE_APP_ID=1:99186909972:web:ad3900fddee22fe6b91801`,
   `FIREBASE_MEASUREMENT_ID=G-D9JVCQJG9V`. Keep `APP_ENV=production` (production always requires the
   Firebase token). Firebase console: Phone enabled, billing/quota ready, authorized domains include
   `dashpass.africa` + `www.dashpass.africa`.
3. **Reload PHP** — cPanel PHP-FPM pool restart + opcache reset (safest way to guarantee the new
   `.env`/code are used).
4. **Purge caches** — Cloudflare → **Purge Everything**, then hard-refresh `/login` and unregister the
   old service worker if needed (`mtaapass-shell-v4` / `mtaapass-static-v4`). **Asset cache caveat:**
   asset URLs are versioned only `?v=0.2.1-dashpass` while `firebase-auth.js` is served with
   `Cache-Control: max-age=14400` (4 h) — CF purge + SW hard refresh are required for the new JS to
   load; alternatively bump `app.version` for a permanent cache-bust.
5. **Re-probe** — `node storage/temporary/firebase-live-probe.js` → expect **`activationDetected=true`**
   (`login.firebaseMode=true`, `data-app-id=1:99186909972:web:ad3900fddee22fe6b91801`,
   `data-measurement-id=G-D9JVCQJG9V`, `data-provider=firebase`, module script, recaptcha container,
   `firebaseJs` 200 with sha256 = deployed pin (`ed622a03…` for `ebe607e`), `csp.originsMissing=[]`);
   the two API probes must still return **403 `AUTH_PROVIDER_MISMATCH`**.
6. **Acceptance** — one test-number/real phone OTP on `/login` → `302 /app`; `/platform` as **Daniel
   `+254700000020`** → 200; `/platform/billing` stays 404 until Inc-10; audit
   `auth.firebase_login {provider: firebase, method: phone}` (PII-free).

**B. Immediate unblock while preparing A:** set the server `.env` **`AUTH_OTP_PROVIDER=server`** →
reload PHP → legacy OTP sign-in works again (code path unchanged, no migration). Then continue A
(switch back to `firebase`) when ready. **Do not leave provider=firebase with the legacy UI live.**

## 2. Owner console setup (“Firebase approval”)

Firebase console for project **`komiuta`**
(<https://console.firebase.google.com/project/komiuta/authentication/providers>):

### 2.1 Enable Phone sign-in

- **Authentication** → **Sign-in method** → **Phone** → **Enable** → **Save**.
- Attach **Google Cloud billing** (Blaze) if the project is on Spark — real SMS needs quota
  beyond the free tier (§2.4).

### 2.2 Authorized domains

**Authentication** → **Settings** → **Authorized domains** → add these exact hostnames (no scheme,
path or port):

- `dashpass.africa`
- `www.dashpass.africa`
- `brim-liberty-liqueur.ngrok-free.dev` (dev tunnel; update if the ngrok hostname changes)
- `localhost`
- `127.0.0.1`

An unlisted origin fails client-side with `auth/unauthorized-domain` (§7/§9).

### 2.3 reCAPTCHA

Firebase web phone auth requires reCAPTCHA. The login page uses the SDK's **invisible
`RecaptchaVerifier`** (no app-side site key/env needed — the SDK creates and manages it):

- On projects where Identity Platform offers **reCAPTCHA Enterprise**, configure it under
  **Authentication → Settings → reCAPTCHA** (or Google Cloud → reCAPTCHA); otherwise the SDK uses
  reCAPTCHA v3 automatically. It must be allowed on the authorized domains above.
- The app's CSP explicitly allows the reCAPTCHA statics/frames and the badge's inline styles (§5).
- reCAPTCHA Enterprise has its own assessment quota/monitoring; a rejected challenge surfaces as
  `auth/captcha-check-failed` (§7/§9).

### 2.4 Quotas and billing notes

- Firebase phone auth has a small free daily SMS quota; production needs **Blaze** +
  quota monitoring (**Authentication → Usage**, Google Cloud quotas).
- reCAPTCHA Enterprise has a free monthly assessment quota and its own alerts.
- Test phone numbers (§2.6) consume **no** SMS quota; their ID tokens are real — test numbers are
  for staging/demo only.
- The app enforces its own server-side IP/phone limits regardless of Firebase quotas (§6).

### 2.5 API-key hardening (recommended; the key is not a secret)

Google Cloud console → **APIs & Services → Credentials** → the browser API key:

- **Application restrictions:** HTTP referrers → `https://dashpass.africa/*`,
  `https://www.dashpass.africa/*`, the ngrok dev origin, `http://localhost/*`,
  `http://127.0.0.1/*`;
- **API restrictions:** allow the Identity Toolkit API + the Firebase APIs the SDK uses only.

### 2.6 Optional test phone numbers for demo accounts

**Authentication → Sign-in method → Phone → Phone numbers for testing** (pair a number with a
fixed code; no SMS is sent). Suggested for staging/demo:

| Phone | Code | Demo account |
|---|---|---|
| `+254700000001` | `123456` | Grace Wanjiku (`estate_admin`) |
| `+254700000010` | `123456` | Jane Smith (`resident`) |
| `+254700000014` | `123456` | David Njoroge (Riverside-only admin) |
| `+254700000004` | `123456` | John Mutiso (`security_guard`) |
| `+254700000020` | `123456` | Daniel Mwangi (`platform_super_admin`) |

Remove test numbers (or keep them only on a staging project) before real residents; production
keeps `SMS_DEV_EXPOSE_OTP=false`.

## 3. Server environment (`.env` only — never committed)

Required for the Firebase path:

| Key | Value / notes |
|---|---|
| `AUTH_OTP_PROVIDER` | `firebase` (default for activation; `server` = fallback/rollback) |
| `FIREBASE_SMS_ENABLED` | `true` (public UI switch; when false the legacy UI renders) |
| `FIREBASE_API_KEY` | public browser API key (restrict by referrer, §2.5) |
| `FIREBASE_AUTH_DOMAIN` | `komiuta.firebaseapp.com` |
| `FIREBASE_PROJECT_ID` | `komiuta` |
| `FIREBASE_STORAGE_BUCKET` | `komiuta.firebasestorage.app` |
| `FIREBASE_MESSAGING_SENDER_ID` | `99186909972` |
| `FIREBASE_APP_ID` | `1:99186909972:web:ad3900fddee22fe6b91801` |
| `FIREBASE_MEASUREMENT_ID` | `G-D9JVCQJG9V` |

Optional tuning (defaults shown; only set if needed):

| Key | Default | Purpose |
|---|---|---|
| `AUTH_FIREBASE_IP_LIMIT` / `AUTH_FIREBASE_IP_WINDOW_SECONDS` | `20` / `900` | verify attempts per IP |
| `AUTH_FIREBASE_PHONE_LIMIT` / `AUTH_FIREBASE_PHONE_WINDOW_SECONDS` | `10` / `900` | verify attempts per phone (sha256-keyed) |
| `FIREBASE_CERT_URL` | Google securetoken x509 endpoint | ID-token cert source |
| `FIREBASE_CERT_CACHE_PATH` | `storage/temporary/firebase-certs.json` | cert cache (writable runtime dir) |
| `FIREBASE_CERT_CACHE_TTL_SECONDS` | `3600` | fallback TTL when the endpoint sends no `max-age` |
| `FIREBASE_CLOCK_LEEWAY_SECONDS` | `60` | token clock-skew leeway |
| `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` | `false` | **dev/tooling only**: when `true` **and** `APP_ENV!==production`, the legacy server-OTP endpoints stay usable while provider=firebase; **forced off in production** — never enable on live |

- Real values live only in the server `.env` and the local gitignored `.env.production.local`;
  `.env.example` keeps **empty placeholders**, `FIREBASE_SMS_ENABLED=false`,
  `AUTH_OTP_PROVIDER=server` (safe defaults for clones/tests).
- **No service-account JSON/private key is needed** — verification uses Google's public certs.
  Never put a service-account file in the repo, bundle or web root.
- Only **public** web config is rendered into `/login`; it is not a secret, but do not log it.
  Tokens/phones are never logged or audited.
- **Web SDK/config version (T131/T133):** the login page uses **Firebase modular SDK 13.2.0**
  (static ESM imports from gstatic via an external `type="module"` script; no compat globals, no
  inline config — values come from the `data-*` attributes; **analytics/firebase-analytics is
  intentionally omitted**). `FIREBASE_MEASUREMENT_ID` is kept for console reference only.
  `FIREBASE_APP_ID`/`FIREBASE_MEASUREMENT_ID` were refreshed to the owner's latest web app; the
  apiKey/authDomain/projectId/storageBucket/messagingSenderId are unchanged. **Server
  verification is unaffected** — the verifier depends only on the project id (`komiuta`, unchanged)
  and Google's public certs, never on the appId/SDK version.
- If the owner later changes the **project id**, the server verifier's expected `iss`/`aud`
  changes with it (deploy config update + restart); appId/measurement/SDK-only changes need no
  server change.
- After changing `.env`, restart the site's PHP pool if the host caches environment at process
  start. `storage/temporary` must be writable for the cert cache (already required by the app).

## 4. Configuration rationale (provider switch vs UI flag)

- `AUTH_OTP_PROVIDER` (server-side) decides how `POST /login/verify` authenticates: `firebase`
  verifies `firebase_token`; `server` runs the legacy OTP check.
- `FIREBASE_SMS_ENABLED` only controls whether the login page renders/loads the Firebase JS.
- A misconfigured/disabled UI flag can therefore never weaken or bypass server token checks, and a
  stale browser page cannot log in with a server OTP in production.
- Unknown provider values fall back to `server` (safe default).

## 4b. Auth-provider enforcement (T138)

With **`AUTH_OTP_PROVIDER=firebase`** the legacy server-generated OTP endpoints are closed so a
stale browser tab/client cannot degrade login to server OTP. Guards run **before** validation and
rate-limit work, responses are generic (no account enumeration), and audit is reason-only.

| Endpoint (provider=firebase) | Behaviour |
|---|---|
| `POST /api/v1/auth/request-otp` | **`403 AUTH_PROVIDER_MISMATCH`** (“Code sign-in is not available. Verify your phone number to continue.”); audited `auth.login_failed` `{provider, reason: PROVIDER_MISMATCH, endpoint: api_request_otp}` |
| `POST /api/v1/auth/verify-otp` | **`403 AUTH_PROVIDER_MISMATCH`**; audited `{reason: PROVIDER_MISMATCH, endpoint: api_verify_otp}`; no OTP lookup/rate-limit work |
| `POST /login/otp` (web) | generic error flash + `302 /login` (same “Code sign-in is not available…” message); audited `endpoint: web_request_otp` |
| `POST /login/verify` (web) | requires the hidden **`firebase_token`**; the server OTP code path is refused (production always strict) |
| Provider **`server`** | unchanged: both endpoints behave as before (the Firebase UI is not loaded) |

- **`FIREBASE_ALLOW_SERVER_OTP_FALLBACK` (default `false`)** — a **development/tooling-only** escape
  hatch: when `true` **and** `APP_ENV!==production`, the legacy server-OTP endpoints remain usable
  alongside Firebase (e.g. local web-OTP tests/demos without a Firebase console). It is
  **force-disabled in production** in code, regardless of the env value; **never set it on live**.
  `.env.example` ships `FIREBASE_ALLOW_SERVER_OTP_FALLBACK=false`.
- `provider=server` is the supported production fallback/rollback (§10) — it needs a real SMS
  gateway adapter; the fallback flag is **not** a substitute for that.

## 5. CSP and page requirements (as implemented)

`app/Middleware/SecurityHeaders.php` applies a strict CSP on every response. The Firebase/reCAPTCHA
additions are:

| Directive | Value (relevant part) |
|---|---|
| `script-src` | `'self' https://www.gstatic.com https://www.google.com/recaptcha/ https://apis.google.com` — **no** `'unsafe-inline'`/eval |
| `style-src` | `'self' 'unsafe-inline' https://www.gstatic.com https://www.google.com/recaptcha/` |
| `connect-src` | `'self' https://identitytoolkit.googleapis.com https://securetoken.googleapis.com https://www.googleapis.com https://komiuta.firebaseapp.com https://www.google.com/recaptcha/` |
| `frame-src` | `https://komiuta.firebaseapp.com https://www.google.com https://recaptcha.google.com` |
| `img-src` | `'self' data: https:` (reCAPTCHA badge/assets) |
| `font-src` | `'self' https://www.gstatic.com` |

**`style-src 'unsafe-inline'` is required and deliberately scoped to styles only** — the Google
reCAPTCHA badge injects inline styles into the page. The **script policy stays strict** (no inline
script, no eval), the app authors no inline styles, and the exception should be removed if the badge
is ever replaced. The login page loads only `/assets/js/firebase-auth.js` (external `type="module"`
script, CSP-safe, no inline handlers/config) and that module statically imports the Firebase
**modular SDK 13.2.0** from `https://www.gstatic.com/firebasejs/13.2.0/` — no other page loads it,
and no compat globals/analytics are used.

## 6. Rate limits, CSRF, audit

- **Route throttle:** `POST /login/verify` → `web-verify,30,60` (unchanged route). The Firebase
  handler adds its own limits keyed to the request:
  - IP: `AUTH_FIREBASE_IP_LIMIT`/`AUTH_FIREBASE_IP_WINDOW_SECONDS` (default **20 per 900 s**);
  - phone: `AUTH_FIREBASE_PHONE_LIMIT`/`AUTH_FIREBASE_PHONE_WINDOW_SECONDS` (default **10 per
    900 s**, keyed by `sha256(phone)`).
  Exceeding either → 302 `/login` with “Too many sign-in attempts…” and an audited
  `auth.login_failed` reason `RATE_LIMITED`.
- **CSRF:** the `POST /login/verify` form carries the standard `_token`; invalid/missing CSRF is
  rejected by the existing middleware (403 `CSRF_TOKEN_INVALID` for JSON/API-style calls).
- **Audit:** success → `auth.firebase_login` with `{provider: firebase, method: phone}` (no token,
  no phone); failure → `auth.login_failed` with `{provider: firebase, reason: RATE_LIMITED |
  INVALID_FIREBASE_TOKEN | FIREBASE_PHONE_INVALID | SERVICE_UNAVAILABLE | USER_NOT_FOUND |
  PROVIDER_MISMATCH}` (provider-mismatch entries also record the endpoint only).
  Tokens and phone numbers are **never** written to logs/audit.
- **Replay/session:** ID tokens are short-lived and claim-checked; the session is created once per
  verified token and the session id/CSRF are rotated on login.

## 7. Error matrix (as implemented)

| Situation | Where | Result | User message | Action |
|---|---|---|---|---|
| Provider `server` (fallback) | server | legacy OTP flow | — | expected fallback |
| Firebase UI disabled (`FIREBASE_SMS_ENABLED=false`) / incomplete public config | login page | legacy UI renders | — | set env + restart |
| Missing/expired/corrupt ID token | server | 401 `INVALID_FIREBASE_TOKEN` (+ audited failure) | “We could not verify that sign-in. Please try again.” | retry from phone step |
| Token without a usable `phone_number` | server | 422 `FIREBASE_PHONE_INVALID` | “We could not verify that phone number. Please try again.” | re-request the code |
| Cert endpoint unreachable / empty cert set | server | 503 `SERVICE_UNAVAILABLE` (fail-closed) | “Sign-in is temporarily unavailable. Please try again shortly.” | check outbound HTTPS/DNS; retry |
| IP or phone rate limit | server | 302 `/login` + audited `RATE_LIMITED` | “Too many sign-in attempts. Please wait and try again.” | wait the window |
| Unknown/suspended user (valid token) | server | 302 `/login` + `USER_NOT_FOUND` | “We could not sign you in. Check that this number is registered…” | admin resolves account |
| Missing/invalid CSRF (`_token`) | server | 403 `CSRF_TOKEN_INVALID` (JSON/API) | reload page | reload form |
| Legacy server-OTP endpoint while provider=firebase | server (API) | **403 `AUTH_PROVIDER_MISMATCH`** (audited `PROVIDER_MISMATCH`) | “Code sign-in is not available. Verify your phone number to continue.” | use the Firebase phone flow; dev-only fallback flag exists but is off in production |
| Legacy server-OTP endpoint while provider=firebase | server (web `/login/otp`) | generic `302 /login` + flash | same message | use the Firebase phone flow |
| Wrong/expired SMS code | browser SDK | `auth/invalid-verification-code` / `auth/code-expired` | mapped client message | re-enter/resend |
| Unauthorized domain | browser SDK | `auth/unauthorized-domain` | “This address is not authorised for sign-in…” | add origin (§2.2) |
| reCAPTCHA failure | browser SDK | `auth/captcha-check-failed` | “The security check failed. Refresh the page…” | check reCAPTCHA config/quota |
| Phone auth not enabled | browser SDK | `auth/operation-not-allowed` | “Phone sign-in is not enabled for this estate yet.” | enable Phone provider (§2.1) |
| SMS quota exhausted | browser SDK | `auth/quota-exceeded` | “The SMS quota is exhausted right now…” | raise quota/billing or test numbers |
| Too many Firebase requests | browser SDK | `auth/too-many-requests` | mapped client message | wait |
| Offline/SDK load failure | browser | network error / `SDK_LOAD_FAILED` | mapped client message | check connection, retry |
| JS disabled | page | `<noscript>` notice | “Phone verification needs JavaScript…” | enable JS (or provider=server) |

### 7b. Error → cause quick map (client SDK / activation)

| Error / symptom | Most likely cause | Fix |
|---|---|---|
| `auth/operation-not-allowed` | **Phone sign-in provider not enabled** for project `komiuta` | Firebase console → Authentication → Sign-in method → Phone → Enable (owner step, §2.1) |
| `auth/unauthorized-domain` | login hostname not in **Authorized domains** (apex vs `www`, ngrok hostname changed) | add the exact origin (§2.2); hard-refresh |
| `auth/invalid-app-credential` (or `auth/invalid-api-key`) | wrong/mismatched **public web config** (API key/appId/project), key referrer restrictions, or a stale page | re-check `.env` public config against the Firebase web app; allow the domain referrer; hard-refresh |
| `auth/captcha-check-failed` | reCAPTCHA not configured for the domains, blocked challenge, Enterprise quota/score | configure reCAPTCHA (§2.3); check console/network; try a test number |
| `auth/quota-exceeded` | SMS quota/free tier exhausted or billing missing | enable Blaze/raise quota; use test phone numbers (§2.6) |
| `auth/invalid-verification-code` / `auth/code-expired` | wrong or expired SMS code (user input) | re-enter or resend; codes expire quickly |
| 503 `SERVICE_UNAVAILABLE` on `/login/verify` | server cannot fetch Google public certs (outbound HTTPS/DNS) or the cert set is empty — fail-closed by design | check server egress to `googleapis.com`; retry; inspect logs (§7/§9) |
| 401 `INVALID_FIREBASE_TOKEN` / 422 `FIREBASE_PHONE_INVALID` | expired/corrupt token, wrong project, or token without a usable phone claim | redo the phone step; confirm `FIREBASE_PROJECT_ID=komiuta`; re-request code |
| Owner sees **“Verify your phone number to continue.”** / “Code sign-in is not available. Verify your phone number to continue.” | the legacy CDE/server-OTP path is disabled or the page is the legacy form: either provider=firebase (expected — use the Firebase phone flow), or the **server `.env` is not activated yet** (§1b) | activate the server env (§3) + reload PHP + CF purge/SW refresh; otherwise use the Firebase phone flow |

## 8. Security rules (must hold)

- **Never trust the client phone.** Identity = verified token `phone_number`; the server ignores
  form phone values for authorisation and rejects tokens lacking a usable phone claim.
- **Public config is fine, secrets are not.** The Firebase web API key is public — restrict it by
  referrers/APIs (§2.5). Never ship a service-account JSON, private key, reCAPTCHA secret key or ID
  token in the repo, bundle, logs or audit.
- **Strict script CSP**; `style-src 'unsafe-inline'` exists only for the reCAPTCHA badge (§5) and
  should be removed when it is no longer needed. No inline scripts/handlers anywhere.
- **Server-side controls stay authoritative:** provider-bound verification, cert-fetch fail-closed,
  claim checks, IP/phone limits, route throttle, CSRF, active-user lookup, generic messages (no
  account enumeration), session/CSRF rotation, PII-free audit.
- **Provider guards run before validation/rate-limits (T138):** with provider=firebase the legacy
  server-OTP entry points are rejected up-front (no phone/code validation work, no OTP row, no
  account lookup), audited reason-only; the `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` escape hatch is
  non-production only and forced off in production in code.
- **Privacy:** the phone is sent to Firebase at verification time; keep the OTP/privacy wording
  current.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `auth/unauthorized-domain` | login origin not in Authorized domains (ngrok hostname changed, www vs apex) | add exact origin (§2.2); hard-refresh; retry |
| `auth/operation-not-allowed` | Phone provider not enabled for the project | enable it (§2.1) |
| `auth/invalid-app-credential` | wrong project/API-key pair or stale page | confirm `FIREBASE_PROJECT_ID`/`FIREBASE_API_KEY` are the `komiuta` pair; hard-refresh; check API-key restrictions |
| reCAPTCHA badge/errors | reCAPTCHA not configured for the domains, Enterprise quota, blocked challenge | configure (§2.3); check console/network; try a test number |
| invalid-token (`INVALID_FIREBASE_TOKEN`, 401) | token expired/corrupt, wrong project `aud`/`iss`, clock skew (leeway 60 s) | redo the phone step; sync server clock; confirm project `komiuta`/env; restart PHP pool |
| `FIREBASE_PHONE_INVALID` (422) | no/invalid `phone_number` claim | re-request the code; confirm the account is a phone-auth user |
| `SERVICE_UNAVAILABLE` (503) | cert endpoint unreachable / empty response, or missing `FIREBASE_PROJECT_ID` | check outbound HTTPS + DNS; verify project id; retry (fail-closed by design) |
| 503 “Sign-in is temporarily unavailable.” on old path | `AUTH_OTP_PROVIDER=server` in production with dev SMS adapter | set Firebase env + console (§2/§3) or configure a real gateway |
| 302 with “Too many sign-in attempts…” | IP/phone rate limit reached | wait the 900 s window; check audit reasons |
| CSP errors in browser console | CSP missing a Firebase/reCAPTCHA origin, or a new SDK origin | compare with §5; update `SecurityHeaders.php`; re-test |
| Stale login page after deploy | Cloudflare/SW cache | purge Cloudflare, hard-refresh; SW re-check per deploy runbook §6 |

## 10. Rollback / fallback to the server OTP path

No code change, no migration:

1. Set `AUTH_OTP_PROVIDER=server` in the server `.env` (optionally also
   `FIREBASE_SMS_ENABLED=false` to stop rendering the Firebase UI); restart the PHP pool.
2. The login page renders the legacy server OTP flow (`POST /login/otp` → `POST /login/verify`
   with a server code); the dev OTP panel appears only when `SMS_DEV_EXPOSE_OTP=true` **and**
   `APP_ENV!==production`.
3. Verify: `/login` shows the server OTP step; one login works on the level you can test (staging
   with the dev adapter; production only with a real SMS provider — the app refuses `log`/`mock`
   adapters when `APP_ENV=production`, by design).
4. Keep the Firebase env keys (unused) or remove them; never commit the values. Switch back by
   setting `AUTH_OTP_PROVIDER=firebase` again.

Emergency: if the Firebase project is compromised/quota-blocked, disable **Phone** in the Firebase
console (stops Firebase SMS) and switch to `AUTH_OTP_PROVIDER=server`; revoke/rotate the browser API
key in Google Cloud if abused. Server OTP in production still requires a real SMS provider (§11).

## 11. Separate SMS-gateway option (not the default)

The server OTP path is provider-abstracted (`SMS_DRIVER` + `app/Integrations/Sms/*`; only `log` and
`mock` adapters exist). A dedicated gateway such as **Africa's Talking or Twilio** would require:

- a provider account + credentials/Sender ID in the server `.env` (never committed), and
- a new `SmsProvider` adapter implementing the interface (not implemented yet).

That is optional, only if the owner prefers a direct SMS provider (custom sender ID/branding,
existing contract rates) or Firebase is unavailable. **Firebase phone auth is the default OTP
gateway** — it needs only the public web config + console setup, and no SMS infrastructure or
gateway credentials on the server.

## 12. Activation / verification checklist

Implementation (T127/T128) is landed in dev; **live front-end/assets are deployed but Firebase is
NOT activated** (login still legacy) until the server `.env` flags + console checklist are applied
(§1b; smoke pack `storage/temporary/firebase-live-smoke.md`):

- [ ] Test suite green (Firebase verifier/controller/UI tests) on the current tree.
- [ ] Firebase console: Phone enabled; authorized domains (§2.2); reCAPTCHA configured;
      billing/quota checked; optional test numbers added (§2.6).
- [ ] Google Cloud: API key restricted by referrers + Identity Toolkit API (§2.5).
- [ ] Server `.env`: `AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true`, all `FIREBASE_*`
      public values; `.env.example` untouched (safe defaults); `storage/temporary` writable (cert cache).
- [ ] **After editing `.env`:** reload PHP (**opcache / PHP-FPM restart** is safest on cPanel) →
      **Cloudflare Purge Everything** → unregister the old service worker / hard-refresh the login
      page → re-run `node storage/temporary/firebase-live-probe.js` (expect
      `activationDetected=true`). Without the purge/SW refresh the browser may keep serving the
      cached legacy login markup.
- [ ] `/health` unchanged (`200`, applied=8/pending=0 pre-Inc-10) — the OTP provider does not touch
      the DB.
- [ ] Staged smoke with **test phone numbers** (or one real SMS): code → login → `/app`; invalid
      code stays on `/login` with a mapped message; audit shows `auth.firebase_login`.
- [ ] Rollback drill: `AUTH_OTP_PROVIDER=server` → legacy OTP page appears → switch back.
- [ ] No Firebase secret/service-account JSON in the repo/bundle (bundle secret screen clean).

## References

- Implementation: `config/firebase.php`, `app/Modules/Authentication/FirebaseTokenVerifier.php`,
  `AuthController::webVerifyFirebase`, `app/Views/auth/login.php`,
  `public/assets/js/firebase-auth.js`, `app/Middleware/SecurityHeaders.php`.
- Firebase console: <https://console.firebase.google.com/project/komiuta/authentication/providers>
- Google public certs: <https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com>
- Deployment runbook: [`deployment-cpanel.md`](deployment-cpanel.md) (§2 env, §3d smoke, §4
  verification) · Owner packet: [`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) · Rollback:
  [`deployment-rollback.md`](deployment-rollback.md)
- Server OTP path: [`foundation-contract.md`](foundation-contract.md) §6–§7
