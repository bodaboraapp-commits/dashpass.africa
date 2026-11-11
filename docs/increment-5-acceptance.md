# Increment 5 — Email Alerts (Resend SMTP), PWA Install Enforcement, Time-based Greeting — Acceptance Record

**Date:** 2026-11-11 (Africa/Nairobi)
**Contract:** `docs/email-pwa-greeting-contract.md`
**Product name:** config-driven (dev MtaaPass, prod DashPASS)

## 1. Delivered

| Area | Deliverable |
|---|---|
| Mail abstraction | `app/Integrations/Mail/` — `MailMessage`, `MailProvider`, `MailSendException`, `LogMailProvider` (masked JSON log), `ResendMailProvider` (HTTP API), **`SmtpMailProvider`** (implicit TLS 465 / STARTTLS 587, AUTH LOGIN + AUTH PLAIN, dot-stuffing, MIME subjects, timeouts, no credential leakage), `MailManager` (log/smtp/resend, misconfig → log fallback) |
| Config | `config/mail.php`: `MAIL_DRIVER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, `MAIL_ALERT_TO`, `MAIL_SMTP_*`, `RESEND_*`, `MAIL_TIMEOUT_SECONDS`; placeholders only in `.env.example`; real values in gitignored `.env`/`.env.production.local` and server env |
| Security alerts | `EmailAlertService` for `visitor.checked_in`, `visitor.checked_out`, `walkin.created`: host `users.email` + `MAIL_ALERT_TO` fallback, dedupe, post-commit only, failures audited (`email.alert_failed`) and never block the flow; success audited with masked recipients; no PIN/OTP/QR token/PII |
| CLI | `scripts/mail-test.php --check` (SMTP auth only) and `--to=` (explicit send only) |
| PWA manifest | Hardened fields: `id`, `lang`, `dir`, `display_override`, `categories`, `prefer_related_applications:false`, `launch_handler`, `handle_links`, `orientation`, shortcuts (Invite visitor, My parking), 192/512/maskable icons, config-driven name/start/scope |
| Install enforcement | App-wide auto install banner (prompt / iOS steps / Android menu / in-app-browser guidance), per-day dismissal, `appinstalled` toast, installed-state detection; account **Installation check** card (HTTPS/manifest/SW/prompt/display/platform + "Why can't I install?"); welcome bar guidance; early `beforeinstallprompt` capture; SW registration retry/status |
| Service worker | `VERSION='v5'`, `mtaapass-shell-v5`/`mtaapass-static-v5`, tolerant precache, handlers/logout intact |
| Headers | `public/.htaccess`: `/service-worker.js` → `Cache-Control: no-cache, max-age=0` + `Service-Worker-Allowed: /` (mod_headers guarded) |
| Greeting | Server-rendered on resident home: 05–11 "Good morning,", 12–16 "Good afternoon,", 17–21 "Good evening,", 22–04 "Hello,", estate-local timezone, invalid timezone → Hello fallback |

## 2. Verification

- **Mail**: `php scripts/mail-test.php --check` → `OK SMTP authentication succeeded (smtp.resend.com:465 (ssl))` exit 0 (auth only, no email sent). Provider transcript proof 33/33 (AUTH LOGIN/PLAIN, STARTTLS, dot-stuffing, queued id, error mapping without leakage); `php -l` 151 files.
- **PWA**: dev `/manifest.json` contains all hardened fields + 2 shortcuts + 3 icons (verified via JSON parse); `/service-worker.js` VERSION `v5`; install guidance/self-check static checks 25/25 and render harness 76/76; live 8010 pages expose banner/self-check hooks.
- **Greeting**: render harness across 6 timezones + invalid-timezone fallback (65/65 including layout/banner checks); no JS required.
- **Tests**: T35 suite result recorded in task board / run log.
- **Secrets**: `RESEND_API_KEY`/`MAIL_SMTP_PASSWORD` absent from tracked tree, index and full history (git-release T44 secret screen CLEAN); `.env*` gitignored.
- **Release**: GitHub `main` at `df1ea7e…`+ (watcher healthy); Increment 4/5 add no migrations → no deploy-sync step needed for them.

## 3. Known limitations
- No real email may be delivered until the owner verifies a sending domain in Resend (or uses `onboarding@resend.dev` to the Resend account email); the current key is valid for SMTP auth but HTTP API returns 401.
- No browser automation in the sandbox: native install prompts / in-app / standalone states verified via statics + markup hooks; the on-device Installation check shows live values.
- Chrome Android engagement heuristics can delay the native prompt; the app shows manual steps; ngrok free URLs and in-app browsers cannot install.
- No retry queue/attachments; alert emails best-effort and audited. No per-user email preference yet.
- `display_override`/`launch_handler` are progressive enhancements ignored by older browsers.

## 4. Next increments
- **Increment 6** — household staff directory + visitor incidents (migration 8, routes 121, role links 204).
- **Increment 7** — estate notices/announcements + notification centre.
- **Increment 8** — parking administration (bays/allocations/violations/overstay).
- **Increment 9** — platform super-admin (estate provisioning, plans/subscriptions), billing/reports.
- **Queued:** Firebase phone-auth OTP (T46) after owner approves CSP/reCAPTCHA decisions.
