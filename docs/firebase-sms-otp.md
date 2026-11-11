# Firebase phone auth (SMS OTP) — setup and operations guide

**Status: APPROVED DEFAULT OTP GATEWAY (2026-11-11).** Firebase phone authentication is the
default login provider for DashPASS/MtaaPass: `AUTH_OTP_PROVIDER=firebase` and
`FIREBASE_SMS_ENABLED=true` in the environment, using the Firebase **web** config below. The
existing server-generated OTP path (`AUTH_OTP_PROVIDER=server`) remains available as the
fallback/rollback (see §9). Server/UI wiring lands in T127/T128; this document is the binding
setup + operations guide for the owner and deploy operator.

**Key property:** the server **never sends SMS** and needs **no service-account JSON**. The
browser Firebase SDK sends and verifies the SMS code; the server receives a Firebase **ID token**
and verifies it against **Google's public certificates**. The Firebase web config is a set of
public client identifiers (safe to ship in the browser, still kept out of git).

---

## 1. How it works (flow)

```
 Resident/guard/admin browser                     DashPASS server
 ─────────────────────────                       ───────────────
 1. POST /login/otp  (phone, CSRF)  ────────────► validates + throttles (web-login)
 2. load Firebase JS SDK (login page only) ◄───── CSP allows www.gstatic.com
 3. signInWithPhoneNumber(phone, recaptcha) ─┐    reCAPTCHA runs in the browser
                                            │    Firebase sends the SMS code
 4. user enters the 6-digit code             │
 5. confirmationResult.confirm(code) ────────┘    (browser talks to Firebase only)
 6. POST /login/verify {idToken, phone} ────────► Firebase token verifier (server):
                                                  • Google public certs (cached)
                                                  • JWT RS256 signature + kid
                                                  • iss = https://securetoken.google.com/komiuta
                                                  • aud = komiuta; exp/iat/auth_time valid
                                                  • sub present; phone_number present
                                                  • token phone == submitted phone (normalized)
                                                  • resolve/create user by verified phone
                                                    (same rules as the server OTP path)
 7. session + CSRF rotated ◄─────────────────────  audit: auth.firebase_login
 8. redirect /app (internal path only) ◄─────────
```

- The **identity phone is the token's `phone_number` claim**, never the form value; the submitted
  phone is only cross-checked (mismatch → reject).
- Client-side errors (reCAPTCHA, quota, wrong code) never reach the server; the page maps them to
  plain messages (§7). The server only sees `idToken`.
- Audit `auth.firebase_login` records provider=firebase and a masked phone reference (no token, no
  full phone/PII); failures audit `auth.login_failed` with a reason code and still no token.

## 2. Owner console setup (“Firebase approval”)

Do this in the Firebase console for project **`komiuta`**
(<https://console.firebase.google.com/project/komiuta/authentication/providers>):

### 2.1 Enable Phone sign-in

- Firebase console → **Authentication** → **Sign-in method** → **Phone** → **Enable** → **Save**.
- Ensure **Google Cloud billing** (Blaze) is attached if the project is on Spark — phone-auth SMS
  needs billing/quota beyond the free tier (§2.4).

### 2.2 Authorized domains

Firebase console → **Authentication** → **Settings** → **Authorized domains** → add these exact
hostnames (no scheme, path or port):

- `dashpass.africa`
- `www.dashpass.africa`
- `brim-liberty-liqueur.ngrok-free.dev` (dev tunnel; update if the ngrok hostname changes)
- `localhost`
- `127.0.0.1`

An unlisted origin fails at `signInWithPhoneNumber` with `auth/unauthorized-domain` (§7/§8).

### 2.3 reCAPTCHA

Web phone auth requires reCAPTCHA. Depending on the project it uses **reCAPTCHA Enterprise** (an
Identity Platform upgrade may be requested) or reCAPTCHA v3 automatically.

- Configure it in **Authentication → Settings → reCAPTCHA** (or Google Cloud → reCAPTCHA) and keep
  the **site key** (public) if the page uses one; never ship a secret key client-side.
- Invisible reCAPTCHA must be allowed to run on the authorized domains.
- Enterprise assessments have their own billing/quota + monitoring; a too-strict score threshold
  shows up as `auth/captcha-check-failed` (§7/§8).

### 2.4 Quotas and billing notes

- Firebase phone auth has a small free daily SMS quota; production needs **Blaze** and quota
  monitoring (**Authentication → Usage**, Google Cloud quotas).
- reCAPTCHA Enterprise has a free monthly assessment quota and its own alerts.
- Test phone numbers (§2.6) do **not** consume SMS quota, but their ID tokens are real — never use
  test numbers as a production login path.
- The app keeps its own server-side rate limits regardless of Firebase quotas (§6).

### 2.5 API-key hardening (recommended; the key is not a secret)

Google Cloud console → **APIs & Services → Credentials** → the browser API key:

- **Application restrictions:** HTTP referrers → `https://dashpass.africa/*`,
  `https://www.dashpass.africa/*`, the ngrok dev origin, `http://localhost/*`,
  `http://127.0.0.1/*`;
- **API restrictions:** allow the Identity Toolkit API and the Firebase APIs the SDK needs only.

### 2.6 Optional test phone numbers for demo accounts

Firebase console → **Authentication → Sign-in method → Phone → Phone numbers for testing**
(add pairs; no SMS is sent and the configured code works). Suggested for staging/demo:

| Phone | Code | Demo account |
|---|---|---|
| `+254700000001` | `123456` | Grace Wanjiku (`estate_admin`) |
| `+254700000010` | `123456` | Jane Smith (`resident`) |
| `+254700000014` | `123456` | David Njoroge (Riverside-only admin) |
| `+254700000004` | `123456` | John Mutiso (`security_guard`) |
| `+254700000020` | `123456` | Daniel Mwangi (`platform_super_admin`) |

Remove test numbers (or keep them only on a staging project) before real residents; on production
keep `SMS_DEV_EXPOSE_OTP=false`.

## 3. Server environment (`.env` only — never committed)

| Key | Value / notes |
|---|---|
| `AUTH_OTP_PROVIDER` | `firebase` (default; `server` = fallback server-generated OTP) |
| `FIREBASE_SMS_ENABLED` | `true` (when `false`, login uses the server OTP path) |
| `FIREBASE_API_KEY` | public browser API key (restrict by referrer, §2.5) |
| `FIREBASE_AUTH_DOMAIN` | `komiuta.firebaseapp.com` |
| `FIREBASE_PROJECT_ID` | `komiuta` |
| `FIREBASE_STORAGE_BUCKET` | `komiuta.firebasestorage.app` |
| `FIREBASE_MESSAGING_SENDER_ID` | `99186909972` |
| `FIREBASE_APP_ID` | `1:99186909972:web:56961f0fa3c0d53fb91801` |
| `FIREBASE_MEASUREMENT_ID` | `G-KX9KHKD4E9` |

- Real values live only in the server `.env` and the local gitignored `.env.production.local`;
  `.env.example` keeps **empty placeholders** and `AUTH_OTP_PROVIDER=server`.
- **No Firebase service-account JSON / private key is needed** — ID tokens are verified with
  Google's public certificates. Never put a service-account file in the repo, bundle or web root;
  if a future server Admin API needs one, store it outside the web root and reference it by path.
- After changing `.env`, restart the site's PHP pool if the host caches environment at process
  start. The public config may be rendered into the login page only; it is not a secret, but do
  not print it into logs/audit, and keep it out of the committed bundle (empty placeholders).
- Owner console steps are required **in addition** to the env — env alone does not send SMS.

## 4. CSP and page requirements

The strict CSP is built in `app/Middleware/SecurityHeaders.php` (no inline script). Firebase phone
auth needs these additions on the login page (keep everything else locked; no `unsafe-inline`):

| Directive | Add |
|---|---|
| `script-src` | `https://www.gstatic.com` (SDK); `https://www.google.com` for reCAPTCHA |
| `connect-src` | `https://identitytoolkit.googleapis.com`, `https://securetoken.googleapis.com`, `https://firebaseinstallations.googleapis.com`, `https://www.googleapis.com` |
| `frame-src` (new) | `https://www.google.com`, `https://recaptcha.google.com`, `https://komiuta.firebaseapp.com` |

- Load the SDK **only on the login page** (defer/module); no other page needs it.
- Markup stays CSP-safe: no inline `<script>`, no inline event handlers. The login form is
  progressively enhanced — plain POST `/login/otp` + `/login/verify` keep working without JS
  (with `AUTH_OTP_PROVIDER=server`).
- If the browser console reports CSP violations for Firebase/reCAPTCHA styles, fix the directive
  for that origin rather than adding `'unsafe-inline'`; re-verify after the code merge (T128).

## 5. Login UX (browser)

1. Phone step → POST `/login/otp` (CSRF, throttle `web-login,20,60`).
2. With `AUTH_OTP_PROVIDER=firebase`, the page loads the SDK and calls
   `signInWithPhoneNumber(phone, recaptchaVerifier)`; reCAPTCHA may verify invisibly.
3. Verify step → user enters the 6-digit code; `confirmationResult.confirm(code)` returns the
   Firebase user; the page POSTs `{idToken, phone}` to `/login/verify` (CSRF, `web-verify,30,60`).
4. The server verifies the token (§1), resolves/creates the user, rotates the session + CSRF,
   audits `auth.firebase_login`, and redirects to `/app` (internal path only).
5. “Resend code” re-runs step 2 (still rate-limited); “Change number” returns to the phone step.

## 6. Rate limits, CSRF, audit

- **Server throttles** (independent of Firebase): `POST /login/otp` → `web-login,20,60` (20 per
  60 s per identity) and `POST /login/verify` → `web-verify,30,60`. Firebase has its own quotas,
  but the app enforces its own limits first.
- **CSRF** is required on every web login POST (`_token`); the API/JSON equivalent returns
  `403 CSRF_TOKEN_INVALID` without a valid token. Session is regenerated and the CSRF token
  rotated on successful login.
- **Audit:** successful Firebase logins record `auth.firebase_login`; failures record
  `auth.login_failed` (reason code). Metadata contains provider/claim references only — **never**
  the ID token, OTP, full phone or other PII.
- **Replay/abuse:** ID tokens are short-lived and verified with `exp/iat/auth_time` checks; the
  session is created once per verified token; excessive verifies are throttled server-side.

## 7. Error matrix

| Situation | Where | HTTP / code | User message | Action |
|---|---|---|---|---|
| Provider disabled/misconfigured (`AUTH_OTP_PROVIDER=server`, `FIREBASE_SMS_ENABLED=false`) | server | login page uses server OTP | — | expected fallback; no error |
| Firebase requested but not configured (missing env) | server | `503 SERVICE_UNAVAILABLE` | “Sign-in is temporarily unavailable. Please try again shortly.” | owner: set `AUTH_OTP_PROVIDER=firebase` + `FIREBASE_*`, restart PHP pool |
| Missing/invalid CSRF token | server | `403 CSRF_TOKEN_INVALID` | reload the page and retry | client reloads form |
| Server rate limit (OTP or verify) | server | `429 RATE_LIMITED` | “Too many attempts. Please wait and try again.” | wait for the window |
| Wrong/expired SMS code | browser SDK | `auth/invalid-verification-code` / `auth/code-expired` | “That code is incorrect or expired.” | re-enter or resend |
| Invalid/expired/mismatched ID token | server | `401 UNAUTHENTICATED` / `422` generic sign-in failure | “Sign-in failed. Please try again.” | retry from the phone step; check project/clock (§8) |
| Token phone ≠ submitted phone (or no phone claim) | server | `422` generic sign-in failure | “Sign-in failed. Please try again.” | reject; never use form phone |
| Unauthorized domain / bad app credential | browser SDK | `auth/unauthorized-domain`, `auth/invalid-app-credential` | “Verification could not start. Please refresh and try again.” | add the origin to authorized domains (§2.2) |
| reCAPTCHA failed / score rejected | browser SDK | `auth/captcha-check-failed` | same generic start failure | check reCAPTCHA settings/quota (§2.3) |
| Firebase quota exceeded | browser SDK | `auth/quota-exceeded` (or 429 from API) | “Too many sign-in requests right now. Try again later.” | raise quota/billing or use test numbers |
| Offline / network error | browser | SDK network error | “Network problem — check your connection and retry.” | retry; PWA shell loads offline but OTP needs network |
| Suspended/nonexistent account (valid token) | server | generic failure (no enumeration) | “Sign-in failed. Please try again.” | admin resolves account |

Exact server error codes are owned by the implementation (T127) and covered by its tests; this
matrix is the binding behaviour contract (generic messages, no token/PII leakage).

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `auth/unauthorized-domain` | login origin not in Authorized domains (ngrok hostname changed, www vs apex) | add the exact origin (§2.2); hard-refresh; retry |
| `auth/invalid-app-credential` | stale page/SDK mismatch, wrong project/API key | verify `FIREBASE_PROJECT_ID`/`FIREBASE_API_KEY` are the `komiuta` pair; hard-refresh; check API-key referrer restrictions |
| reCAPTCHA errors / no badge | reCAPTCHA Enterprise not configured, domain blocked, quota | configure reCAPTCHA (§2.3); check console/network; try a test phone number |
| `auth/captcha-check-failed` | score threshold or blocked challenge | lower threshold / verify site key + domains; re-test |
| `auth/quota-exceeded` | free tier used up or per-project limit | enable Blaze/raise quota; use test numbers for the pilot (§2.6) |
| invalid-token / token rejected (`auth/id-token-expired`, `auth/invalid-id-token`, server 401) | token expired (>1 h), clock skew, wrong project (`aud`/`iss`), server cert cache stale | redo the phone step for a fresh token; sync server clock; confirm project `komiuta`; restart PHP pool; the verifier refreshes Google certs automatically |
| Server rejects a valid SDK token | clock skew, wrong project (`aud`/`iss`), token from another Firebase project, phone mismatch | sync server clock; confirm project `komiuta`; check `.env` and restart PHP pool; confirm the submitted phone equals the token's `phone_number` |
| 503 “Sign-in is temporarily unavailable.” | provider set to firebase without config, or dev adapter in production | set Firebase env + console (§2/§3); or rollback to `AUTH_OTP_PROVIDER=server` (§9) |
| Login page CSP errors in console | CSP missing gstatic/Google/Firebase origins | update CSP (§4); re-verify |
| PWA/offline stale shell after deploy | cached service worker/login page | purge Cloudflare, hard-refresh; SW re-check per deployment runbook §6 |

## 9. Rollback / fallback to the server OTP path

No code change, no migration:

1. Set `AUTH_OTP_PROVIDER=server` (and, if needed, `FIREBASE_SMS_ENABLED=false`) in the server
   `.env`; restart the site's PHP pool.
2. The login page uses the existing server-generated OTP flow (`/login/otp` → `/login/verify`,
   dev panel only when `SMS_DEV_EXPOSE_OTP=true` **and** non-production).
3. Verify: `/login` renders the server OTP step; one login works on the level you can test
   (staging with the dev adapter; production only with a real SMS provider — the app refuses
   `log`/mock adapters when `APP_ENV=production`, by design).
4. Keep the Firebase env keys in place (unused) or remove them; never commit the values.

Emergency: if the Firebase project is compromised or quota-blocked, disable **Phone** in the
Firebase console (stops all Firebase SMS) and switch `AUTH_OTP_PROVIDER=server`; revoke/rotate the
browser API key in Google Cloud if it was abused. Server OTP in production still requires a real
SMS provider (§10).

## 10. Separate SMS gateway option (not the default)

The server OTP path is provider-abstracted (`SMS_DRIVER` + `app/Integrations/Sms/*`; currently
`log`/`mock` adapters only). Using a dedicated gateway such as **Africa's Talking, Twilio, etc.**
would require:

- provider account + credentials/Sender ID in the server `.env` (never committed), and
- writing a new `SmsProvider` adapter for that gateway (not implemented yet).

That path is optional and only needed if the owner prefers a direct SMS provider (custom sender
ID/branding, existing contract rates) or if Firebase is unavailable. **Firebase phone auth is now
the default OTP gateway** — it needs only the public web config + console setup and no SMS
infrastructure/credentials on the server.

## 11. Setup verification checklist (owner + operator)

- [ ] Firebase console: Phone enabled; authorized domains listed (§2.2); reCAPTCHA configured;
      billing/quota checked; optional test numbers added (§2.6).
- [ ] Google Cloud: API key restricted by referrers + Identity Toolkit API (§2.5).
- [ ] Server `.env`: `AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true`, all `FIREBASE_*`
      public values; `.env.example` untouched (empty placeholders); PHP pool restarted.
- [ ] `/health` unchanged (`200`, applied=8/pending=0 pre-Inc-10) — OTP provider does not change
      the DB.
- [ ] One login round-trip works end-to-end (staging/test numbers first, then one real SMS on the
      target environment); session lands on `/app`.
- [ ] Audit shows `auth.firebase_login` with no token/PII; failed attempts show `auth.login_failed`.
- [ ] Fallback drill: switch to `AUTH_OTP_PROVIDER=server`, confirm the server OTP page appears,
      switch back.
- [ ] No Firebase secret/service-account JSON in the repo/bundle (bundle secret screen clean).

## 12. Security rules (must hold)

- **Never trust the client phone.** Identity comes only from a verified ID token's
  `phone_number`; the form phone is cross-checked and rejected on mismatch.
- **Public config is fine, secrets are not.** The Firebase web API key is public; restrict it by
  referrers + APIs (§2.5). Never ship a service-account JSON, private key, reCAPTCHA secret key or
  ID token in the repo, bundle, logs or audit.
- **Keep the strict CSP**; add only the origins in §4. No inline scripts/handlers on the login page.
- **Server-side controls stay authoritative:** throttles, CSRF, account status/membership checks,
  generic error messages (no account enumeration), session/CSRF rotation, audit.
- **Phone privacy:** the phone is sent to Firebase at verification time; keep the privacy notice
  wording for OTP delivery current.

## References

- Firebase console: <https://console.firebase.google.com/project/komiuta/authentication/providers>
- Google public certs used by the verifier:
  <https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com>
- Deployment runbook: [`deployment-cpanel.md`](deployment-cpanel.md) (§2 env, §3d smoke, §4
  verification) · Owner packet: [`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) · Rollback:
  [`deployment-rollback.md`](deployment-rollback.md)
- Server OTP path contract: [`foundation-contract.md`](foundation-contract.md) §6–§7
