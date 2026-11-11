# MtaaPass/DashPASS — Increment 5: Email Alerts (Resend), PWA Install Enforcement, Time-based Greeting — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation/visitor/vehicles/parking/admin contracts; `docs/increment-3-acceptance.md`; current state 99 routes, 6 migrations, 34 tables.
**Product name:** configuration-driven (dev **MtaaPass**, prod **DashPASS**).
**Owner request:** "for email alerts use resend API" (key provided out-of-band, never commit), "PWA is not enforced on production", "instead of 'Hello,' use dynamic Good Morning, Good Afternoon, Good evening and Hello based on time of day".

## 0. Scope

1. **Transactional email via Resend** — provider abstraction + configuration + security event alerts (visitor check-in/check-out, walk-in created) to the host's `users.email` when present, plus a configurable fallback inbox for pilots.
2. **PWA install enforcement (production)** — make installability and the install affordance explicit and reliable: hardened manifest, auto-shown install banner on app pages, iOS instructions, installed-state detection, cache-safe service worker update, SW serving headers.
3. **Time-based greeting** — replace the static "Hello," on the resident home screen with Good morning / Good afternoon / Good evening / Hello by estate-local time.

**No schema changes** (`users.email` already exists, nullable). No migrations. No new routes required (email is emitted by existing flows). Route table stays **99**.

## 1. Email (Resend) — modules

### 1.1 Configuration (`config/mail.php` + `.env` keys)
```
MAIL_DRIVER=log|resend        # default log in local/test; resend in production pilots
MAIL_FROM_ADDRESS=onboarding@resend.dev   # must be a verified Resend domain/sender in prod
MAIL_FROM_NAME=DashPASS
MAIL_ALERT_TO=                # optional comma-separated fallback recipients for pilot alerts
RESEND_API_KEY=               # secret; .env only (gitignored), never committed
RESEND_API_URL=https://api.resend.com
MAIL_TIMEOUT_SECONDS=10
```
`config/mail.php` exposes `driver`, `from.address`, `from.name`, `reply_to`, `alert_to` (array, filtered valid emails), `resend.key`, `resend.url`, `timeout`. `Env::bool` for flags; no secrets in code/defaults.

### 1.2 Providers (`app/Integrations/Mail/`)
- `MailMessage` value object: `to[]`, `subject`, `text`, `html?`, `replyTo?`, `metadata[]`.
- `MailProvider` interface: `send(MailMessage $message): array{message_id,provider,status}`; `name(): string`.
- `LogMailProvider` — writes a redacted JSON line to `storage/logs/mail.log` (recipient masked like `j***@example.com`, subject, provider id, status=sent/logged; **never** log the API key or full body with secrets), returns success. Default in dev/test; works with no key.
- `ResendMailProvider` — POST `{RESEND_API_URL}/emails` with `Authorization: Bearer <key>`, JSON `{from:"Name <addr>", to:[...], subject, text, html?, reply_to?}`, timeout, no key/secret in exceptions/logs; map 2xx → success (return `id`), 4xx/5xx/network → `MAIL_SEND_FAILED` (do not throw into business flows — see 1.4); guard: if key empty → treat as disabled/no-op with a logged warning (never fatal).
- `MailManager` — picks driver from config (`log`/`resend`), lazy provider creation, `send()` helper; `isConfigured(): bool`.
- Production guard: if `APP_ENV=production` and driver is `log`, log a warning (do not fail boot).

### 1.3 Alert service (`app/Modules/Notifications/EmailAlertService.php`)
- `sendSecurityAlert(string $event, array $context): void` — builds subject/text from a small template map:
  - `visitor.checked_in`: "Visitor checked in — {visitor_name} at {gate_name} for {household}, {time}" (include estate name).
  - `visitor.checked_out`: "Visitor checked out — {visitor_name} …".
  - `walkin.created`: "Walk-in visitor awaiting approval — {visitor_name} at {gate_name}".
- Recipients: host user's `users.email` when present and valid (`security_alert` opt-out flag stored in estate_settings? **No schema/flag this increment** — always send if email exists), plus `config('mail.alert_to')` fallback recipients (dedupe). If no recipients → silently skip (debug log).
- Never include PIN/OTP/QR token/resident phone in email body. Do not block the originating transaction: send after commit (like SMS), catch all provider errors → audit `email.alert_failed` (event + provider status) and continue.
- Wire in `VisitorNotificationService`/`AccessService`/`WalkInService` at the existing post-commit notification points (same places SMS is sent). Keep SMS behaviour unchanged.

### 1.4 Audit + privacy
- Audit actions: `email.alert_sent` (event, recipient count, provider — **masked recipients only**), `email.alert_failed` (event, provider error code/status). Never log raw addresses in audit metadata; mail log masks recipients.
- Dev SMS/log behaviour unchanged.

## 2. PWA install enforcement (production) — modules (manifest) + pwa-shell (UI/SW/headers)

### 2.1 Manifest hardening (`app/Modules/Platform/ManifestController.php` — modules)
Keep dynamic config-driven name; add:
`lang:"en"`, `dir:"ltr"`, `id:"/"` (already), `display_override:["standalone","minimal-ui"]`, `categories:["business","productivity","utilities"]`, `prefer_related_applications:false`, `launch_handler:{client_mode:"navigate-existing"}`, `handle_links:"preferred"` (harmless if unsupported), `orientation:"portrait"`.
Add 2 **shortcuts**: "Invite visitor" → `/app/visitors/invite`, "My parking" → `/app/parking` (icons optional reuse existing 192 icon).
Icons stay 192/512/maskable-512 any. `start_url:"/"`, `scope:"/"`.
Add `screenshots` only if real images exist (skip).

### 2.2 Install UX (pwa-shell)
- **Auto install banner on all app pages** (not just welcome): sticky, dismissible (localStorage `mtaapass.install.dismissed` with date), shown only when not in standalone mode (`display-mode: standalone` / `navigator.standalone`) and an install path exists:
  - Chromium: capture `beforeinstallprompt` (defer), show banner "Install {product} on this device" with Install button → `prompt()`; hide on `appinstalled` + toast "Installed — open it from your home screen."
  - iOS Safari (no prompt): show banner with "Add to Home Screen" instructions (Share → Add to Home Screen), same dismiss.
  - If already installed: never show.
- Add the banner markup to `layouts/app.php` (and welcome/guest via the existing `data-install-bar` where appropriate) so app.js can reveal it; keep accessible (role/status, labelled buttons, 44px, reduced-motion).
- Account screen install card: keep; make it reflect installed state ("Installed on this device") when standalone.
- `public/assets/js/app.js`: robust install-state detection, banner show/hide, `appinstalled` handling, no console noise, no inline code.
- **Service worker**: bump `VERSION` to `v4` and cache names (`mtaapass-shell-v4`, `mtaapass-static-v4`); keep precache list valid on production (manifest route, offline.html, icons, CSS/JS), navigation fallback unchanged, logout cache clearing unchanged; ensure install failure cannot trap old caches.
- **`public/.htaccess`** (pwa-shell may add): serve `/service-worker.js` with `Cache-Control: no-cache, max-age=0` and `Service-Worker-Allowed: /` (wrap in `<IfModule mod_headers.c>`), and never cache `manifest.json` (`no-store` already from app headers).
- Add `<meta name="theme-color">` parity already present; keep dark default.

### 2.3 Production verification
- Verify live: `https://<domain>/manifest.json` 200 `application/manifest+json` with new fields; `/service-worker.js` 200 JS + no-cache + allowed header; icons 192/512/maskable 200; HTML links manifest + apple icons + `application-name`; SW registers with scope `/`; no mixed content/absolute URLs. (Browser install prompt itself cannot be automated in this sandbox — record as limitation; static criteria must be proven.)
- If the live server still runs an older snapshot, note that the owner must redeploy for these changes to take effect.

## 3. Time-based greeting (pwa-shell view + helper)

Resident home (`app/Views/app/home.php`, line with `home-head__hello`): replace static `Hello,` with a server-rendered greeting:
- Estate-local time (timezone from `$estate['timezone']`/`estate_timezone` if provided by the shell, else `config('app.timezone', 'Africa/Nairobi')`).
- Rules: **05:00–11:59 "Good morning,"** · **12:00–16:59 "Good afternoon,"** · **17:00–21:59 "Good evening,"** · **22:00–04:59 "Hello,"** (neutral night greeting; this is the explicit "Hello" fallback).
- Deterministic helper `App\Core\Clock`-based; no DB use. Must work without JS (server-side), be escaped (`e()`), and degrade to "Hello," if timezone/config invalid.
- Apply the same greeting wherever a static "Hello," appears (grep); do not change other copy.

## 4. Tests (tests) — T35 after T29 closes

- Mail: `MailManager` driver selection (log default; resend with key; empty key → disabled no-op); `LogMailProvider` writes mail.log with masked recipient (no raw phone/email, no secrets); `MailMessage` validation; `ResendMailProvider` payload/headers via a fake HTTP transport (no real network) incl. error mapping (401/422/timeout → MAIL_SEND_FAILED, no secret in message); `EmailAlertService` recipients (host email + fallback dedupe), no-PIN/OTP/token content, no-recipient skip, post-commit non-blocking (failure does not break check-in), audit `email.alert_sent/failed` with masked recipients.
- Greeting: boundary times (04:59 Hello, 05:00 Good morning, 11:59 morning, 12:00 afternoon, 16:59 afternoon, 17:00 evening, 21:59 evening, 22:00 Hello) via injectable clock/timezone; home page renders greeting server-side, escaped, no notices; invalid timezone falls back safely.
- PWA static: manifest new fields valid JSON (lang/dir/display_override/categories/prefer_related_applications/launch_handler/shortcuts), icons 192/512/maskable, start_url/scope; install banner markup hooks in app layout; app.js contains install-state/banner logic + SW register + appinstalled; service worker `VERSION='v4'` + caches + fetch/install/activate handlers + precache includes manifest/offline/icons; `public/.htaccess` SW headers present; no inline handlers.
- Email alerts integration: dev LogMailProvider mail.log entry on visitor check-in when `MAIL_ALERT_TO` set/host email present; no email when neither; admin/normal flows unaffected; existing suite stays green; README + counts (route/migrations unchanged 99/6).
- README: Resend setup (env keys, verified sender domain, test mode `onboarding@resend.dev`), alert triggers, PWA install (Android/iOS instructions + installability criteria), greeting rules, limitations (no browser install automation, email requires verified domain, no per-user email preference yet).

## 5. Tasks / scopes

| Task | Agent | Scope |
|---|---|---|
| T33 mail + manifest hardening | modules | `app/Integrations/Mail/**`, `config/mail.php`, `app/Modules/Platform/ManifestController.php`, `app/Modules/Notifications/EmailAlertService.php`, minimal additive wiring in `app/Modules/Visitors/**` (post-commit), `.env.example` placeholders (no secrets) |
| T34 greeting + install UX + SW/headers | pwa-shell | `app/Views/app/home.php`, `app/Views/layouts/app.php`, `app/Views/app/account.php`, `public/assets/js/app.js`, `public/service-worker.js`, `public/.htaccess` |
| T35 tests + README | tests | `tests/**`, `README.md` (after T29) |
| T36 Lead gate | Lead | full suite + live verification (manifest/SW install criteria, Resend key validity/send test per owner key status, greeting) + acceptance doc |

## 6. Acceptance

1. `php tests/run.php` green (current count + new email/greeting/PWA tests); routes still 99; migrations 6.
2. With `MAIL_DRIVER=log`: visitor check-in/check-out/walk-in produce `storage/logs/mail.log` lines with masked recipients and no secrets, and `email.alert_sent` audit when recipients exist; no email rows when none.
3. With a valid `RESEND_API_KEY` + verified sender: a test alert is accepted by Resend (2xx); invalid/unauthorized key results in `MAIL_SEND_FAILED` + audit, never a 500 or blocked check-in. (Current provided key returns **401** — must be replaced/verified by owner.)
4. `/manifest.json` includes the hardened fields/shortcuts; `/service-worker.js` served no-cache with `Service-Worker-Allowed: /`, `VERSION='v4'`, valid handlers; app pages include install banner hooks; installed mode hides install affordances; iOS instructions shown on iOS.
5. Resident home greeting is time-correct server-side (Good morning/afternoon/evening/Hello) and escaped; "Hello," remains the night fallback.
6. Live verification on the deployed host after redeploy; docs updated; no hardcoded product names.
