# Firebase Phone Auth (SMS OTP) — stored config + integration plan

**Status:** config stored, integration **not yet enabled** (`FIREBASE_SMS_ENABLED=false`).
Current OTP continues to use the existing `OtpService` + SMS adapters (`log` in dev, real provider later).
This document is the plan for switching the OTP channel to Firebase SMS.

## 1. Credentials (stored, never committed)

The Firebase **web** config is a set of public client identifiers (safe to ship in browser JS,
but still kept out of git and restricted in Google Cloud):

```dotenv
FIREBASE_API_KEY=AIza...            # restrict by HTTP referrer in Google Cloud console
FIREBASE_AUTH_DOMAIN=komiuta.firebaseapp.com
FIREBASE_PROJECT_ID=komiuta
FIREBASE_STORAGE_BUCKET=komiuta.firebasestorage.app
FIREBASE_MESSAGING_SENDER_ID=99186909972
FIREBASE_APP_ID=1:99186909972:web:56961f0fa3c0d53fb91801
FIREBASE_MEASUREMENT_ID=G-KX9KHKD4E9
FIREBASE_SMS_ENABLED=false
```

Real values live only in `.env` / `.env.production.local` (gitignored) and on the server `.env`.
`.env.example` contains placeholders only. **Never** put a Firebase **service-account JSON** /
private key in the repo or client code — that is a true secret; if server-side admin APIs are
needed later, store the service-account file outside the web root (e.g. `/home/<user>/secrets/`)
and reference it by path in `.env`.

## 2. Important constraints before enabling

1. **CSP/external scripts:** today the app has no external CDNs and a strict CSP. Loading the
   Firebase client SDK from `www.gstatic.com` (as in the paste snippet) would require adding
   `https://www.gstatic.com` to `script-src` and `connect-src` to Firebase endpoints — an approved
   deviation must be documented (owner decision). Prefer isolating it to the login page only.
2. **reCAPTCHA:** Firebase web phone auth requires reCAPTCHA (invisible/Enterprise) for
   `signInWithPhoneNumber`. Configure the authorized domain (`dashpass.africa` + `localhost`) and
   reCAPTCHA keys; test phone numbers can avoid captcha in dev.
3. **iOS/Android app vs web:** we are a PWA; Firebase web phone auth works, but on iOS in-app
   browsers it may still fail — keep the existing server-side OTP as fallback.
4. **Provider fallback:** keep `OtpService` provider-agnostic; Firebase becomes a new SMS/OTP
   driver, not a replacement of PIN/QR visitor credentials (those stay server-side/HMAC).
5. **Rate limits:** keep existing per-phone/IP rate limits and single-use/expiry rules; Firebase
   adds its own quotas, but our server must still enforce.
6. **Privacy:** phone numbers already stored for registered users; Firebase only receives the phone
   number at verification time. Add privacy-notice wording before enabling in production.

## 3. Suggested implementation (future increment)

- `app/Integrations/Firebase/FirebaseWebConfig.php` — read env, expose safe config for the login
  page only (no secrets; fail closed if `FIREBASE_SMS_ENABLED=false`).
- Login page (`auth/login.php`): when enabled, render a Firebase phone-auth widget (module script
  with `nonce` from CSP; no inline handlers), then post the Firebase **ID token** to a new endpoint
  `POST /login/firebase` (+ API equivalent) which verifies the token server-side using Google's
  public certs (`https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com`)
  and standard JWT checks (iss=`https://securetoken.google.com/<project>`, aud=`<project>`,
  exp/iat, phone_number present).
- On success: resolve/create user by verified phone exactly like the existing OTP path
  (same `users`/memberships rules, audit `auth.login`, session regeneration, CSRF rotation).
- Keep the current OTP endpoints working; `FIREBASE_SMS_ENABLED` toggles only the login UI path.
- Tests: token verification (valid/expired/bad signature/wrong aud), user resolution, rate limits,
  fallback when disabled, CSP/markup assertions, no token/secret in logs/audit.
- Docs: enable/disable procedure, reCAPTCHA + domain setup, rollback.

## 4. What "keep it" means now

- Config is stored (gitignored env + placeholders).
- No code paths use it yet; the current OTP flow is unchanged.
- Integration is queued as **T46 — Firebase phone-auth SMS OTP (login)** after the current
  Increment 5/6 gates; it will require an owner decision on the CSP/CDN deviation and reCAPTCHA
  setup before enabling in production.
