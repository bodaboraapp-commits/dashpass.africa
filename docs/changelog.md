# Changelog — MtaaPass / DashPASS

**Current release:** `0.2.1-dashpass` · **Date:** 2026-11-11 (Africa/Nairobi)
**Repo:** <https://github.com/bodaboraapp-commits/dashpass.africa> (branch `main`)
**Environments:** development `http://127.0.0.1:8010` · staging/live `https://dashpass.africa`
**Product name:** config-driven — **MtaaPass** in development, **DashPASS** in production
(`APP_NAME` / `APP_SHORT_NAME`); the same codebase serves both.

Release notes are newest → oldest. Each increment links its binding contract and acceptance
record. Deployment steps live in [`deployment-cpanel.md`](deployment-cpanel.md), the owner
go-live packet in [`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md), and rollback/DB policy
in [`deployment-rollback.md`](deployment-rollback.md).

> **LIVE PREVIEW (2026-11-12, owner decision — no rollback):** live runs the **Inc-13/Inc-12 WIP**
> code deployed via `git pull`, with migrations **applied=11 / pending=0** (**42 tables / 84 FKs /
> 52 permissions / 13 roles / 217 links**) and **routes 183 at the E2E run** (current tree
> **194** = 150 web + 44 API after the Inc-13 schedule/Inc-11 batch); Firebase login
> **active**; billing, **notice-image** and incident routes live. **Not an accepted release** — the
> baseline suite, P2/P3 slices and Inc-13 acceptance are still pending (Inc-11 also pending).
> **Rollback path:** release pin **`f11367c`** = Inc-10 bundle artifact
> `storage/temporary/mtaapass-deploy.zip` (sha256
> `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`; archive
> `storage/temporary/f11367c.zip`), schema additive-compatible and migrations forward-only. The
> **UI hotfix is effectively live** (code included) and `storage/temporary/ui-hotfix-inc10.zip` is
> retained for rollback/reference. Known gaps: P2 settings forms, resident Account card removals /
> estate Appearance paused.
>
> Increment history on live: Inc-9 build (158 routes, applied=8) → Inc-10 (165 routes, applied=9,
> bundle pin `f11367c`) → current Inc-13/Inc-12 **preview** (183 routes at E2E → **194** current tree, applied=11).

---

## Unreleased — next release (in progress)

> **Not yet released.** Gates are pending; do not treat the expectations below as live until the
> Lead opens the DB/route gates and the release agent's bundle refresh lands.

### OTP channel — Firebase phone auth (approved default; implemented in dev, live activation pending tests)

- **Default OTP gateway changed to Firebase phone auth**: `AUTH_OTP_PROVIDER=firebase`,
  `FIREBASE_SMS_ENABLED=true`, project **`komiuta`**. The browser Firebase SDK sends/verifies the
  SMS; the server verifies the Firebase **ID token** submitted through the existing
  `POST /login/verify` (hidden `firebase_token`) against Google's public x509 certificates — **no
  service-account JSON/secret** and no server SMS credentials required. Failures map to
  `INVALID_FIREBASE_TOKEN` (401) / `FIREBASE_PHONE_INVALID` (422) / `SERVICE_UNAVAILABLE` (503);
  success audits `auth.firebase_login`, failures `auth.login_failed` (provider/reason only).
- **CSP:** Firebase/reCAPTCHA origins added; `style-src 'unsafe-inline'` is deliberately scoped to
  the reCAPTCHA badge **only** — the script policy stays strict (no inline scripts/eval).
- **Updated web config + SDK (T131/T133):** login UI now uses **Firebase modular SDK 13.2.0**
  (static ESM imports from gstatic via an external `type="module"` script, no inline config, no
  compat globals; **analytics intentionally omitted**). Refreshed public values:
  `FIREBASE_APP_ID=1:99186909972:web:ad3900fddee22fe6b91801`,
  `FIREBASE_MEASUREMENT_ID=G-D9JVCQJG9V` (apiKey/authDomain/projectId/storage/messagingSender
  unchanged). Project id is unchanged, so **server token verification is unaffected**; CSP script
  policy stays strict (gstatic already allowed).
- **Owner setup required before production login works**: Firebase console → Phone sign-in
  enabled; authorized domains `dashpass.africa`, `www.dashpass.africa`,
  `brim-liberty-liqueur.ngrok-free.dev`, `localhost`, `127.0.0.1`; reCAPTCHA configured; budget/quota
  checked; then one real OTP round-trip. Full guide + troubleshooting:
  [`firebase-sms-otp.md`](firebase-sms-otp.md).
- **Fallback/rollback:** `AUTH_OTP_PROVIDER=server` keeps the server-generated OTP path; a real SMS
  gateway (Africa's Talking/Twilio etc.) still needs provider credentials + an adapter (only
  `log`/`mock` adapters exist today), so Firebase is the default. No DB change — `/health` stays
  applied=8.
- **Status:** implemented in dev (T127/T128) and **ACTIVATED ON LIVE (verified 2026-11-11):** the
  owner applied the server `.env` (`AUTH_OTP_PROVIDER=firebase`, `FIREBASE_SMS_ENABLED=true` + the
  public `FIREBASE_*` values, project `komiuta`). Live `/login` now renders the Firebase markers
  (`data-firebase-auth`, provider `firebase`, appId `1:…:web:ad3900fddee22fe6b91801`, measurement
  `G-D9JVCQJG9V`, SDK **13.2.0** module script + reCAPTCHA container) and the read-only probe
  reports **`activationDetected=true`**; the T138 guard (API `request-otp`/`verify-otp` → **403
  `AUTH_PROVIDER_MISMATCH`**) remains in force. **This is separate from Inc-10, which is NOT
  deployed** — live `/health` is still applied=8 and `/platform/billing` returns 404 until the
  Inc-10 deploy (expected). No Inc-10 release claim.

### Auth-provider enforcement + billing hardening (T138, unreleased)

- **Auth-provider enforcement:** with `AUTH_OTP_PROVIDER=firebase` the legacy server-OTP entry
  points are closed — API `POST /api/v1/auth/request-otp` / `verify-otp` → **403
  `AUTH_PROVIDER_MISMATCH`**, web `POST /login/otp` → generic redirect to `/login`, web
  `POST /login/verify` requires the hidden `firebase_token`; `provider=server` is unchanged. Guards
  run before validation/rate-limits, messages are generic (no account enumeration) and audit
  records `auth.login_failed` with reason `PROVIDER_MISMATCH` + endpoint only (no PII). A
  dev/tooling-only `FIREBASE_ALLOW_SERVER_OTP_FALLBACK` (default `false`) can re-enable the legacy
  path outside production; it is **force-disabled in production** — never enable it on live.
- **Billing hardening (pre-gate):** invoice-number allocation reads the per-estate latest number
  with a row lock (`SELECT … FOR UPDATE`) inside the create transaction and retries **only** on a
  duplicate-key error 1062 (`UNIQUE(estate_id, invoice_number)`, max 5 attempts); any other DB
  failure surfaces immediately. Invoice creation accepts a `save_draft` boolean (creates `draft`
  instead of `open`), and `scripts/billing-past-due.php --estate=` with an empty value now exits
  **2** (usage error) instead of falling back to all estates.
- **Status:** both are unreleased hardening inside the gated Firebase/Inc-10 work — no release
  claim; Inc-10 remains gated/unreleased.

### Increment 10 — Billing / invoices (DELIVERED — gated bundle pin `f11367c`)

*Contract:* [`billing-invoices-contract.md`](billing-invoices-contract.md) · *Acceptance:*
[`increment-10-acceptance.md`](increment-10-acceptance.md) (**ACCEPTED — DELIVERED**) ·
*Status:* **released as the Inc-10 gated bundle** — final suite **757/757 PASS, 0 FAIL, 1576.72s,
exit 0** (`tests/_inc10-final.log`); bundle `storage/temporary/mtaapass-deploy.zip` =
`409` entries / `1,203,313` B / sha256 `18B6FB0326A45F8D1422A08174BC22E08B8AF4C1A6632EA566A4BC5A3F3C90E1`, frozen at pin
**`f11367c`**. Live already received the code (repo pull) and the Lead applied **migration 10** via
`deploy-sync install` — `/health` is **applied=9 / pending=0** (DB `tokeqvlx_dashpass_db`, seeded;
`/platform/billing` → **302 `/login`** unauth; expected seed 2 invoices + 1 payment — confirm with
the integrity check). The bundle is the release artifact + rollback record for fresh deploys and
re-deploys. Live Firebase is separately **ACTIVATED**.

- **Migration 10** (`20261111_000010_create_billing_tables.sql`): two additive tables —
  `billing_invoices` + `billing_payments` (append-only payment ledger) — plus an additive
  `subscriptions (id, estate_id)` unique index for the invoice composite FK. No other table/seed
  row is touched; no hard deletes. After the gate: **9 migrations / 39 tables / 52 permissions /
  13 roles / 217 role links / 72 FK constraints / 2 invoices + 1 payment** (Greenview only).
- **Routes 158 → 165** (web **121** + API 44): 7 platform-only billing routes
  (`/platform/billing`, invoices new/create/show/status, payments record/status) — `['auth']` +
  controller platform-area gate + `billing.invoice.view` / `billing.payment.manage`; estate users
  **403**, unauthenticated **302**; no new APIs.
- **Past-due sweep:** `php scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]` moves overdue
  `open` → `past_due` (audited, idempotent, server-side/cron; empty `--estate=` exits **2**).
- **Gate evidence:** final suite **757/757 PASS / 0 FAIL / 1576.72s / exit 0** (`_inc10-final.log`,
  `_inc10-final-exit.txt`); Lead DB gate **9/39/52/13/217/72 FK/2 invoices + 1 payment** with
  integrity **26/26** and cleanroom **28/28**; route gate readiness `ready=true` (165 routes, all 7
  billing routes + gates, seeded invoices/ledger, Daniel 200 / Grace 403 / unauth 302); backend
  proof **55/55** and numeric-bounds **24/24**; **T111 billing UI/smoke 49/49 PASS**
  (nav+quick-link, list/new/detail/payment/auto-paid/void-terminal, 403/302/404 matrix; harnesses
  127/95/46/25/76); **qa-e2e E2E 70/72 (0 FAIL, 2 SKIP)** with regressions Inc-5 71/71 · Inc-6
  95/96 · Inc-7 78/80 · Inc-8 101/102 · Inc-9 67/68 — two non-blocking observations tracked as
  **T147** (platform audit viewer excludes `billing.*`; month filter uses created-month).
- **Deploy:** `POST /deploy-sync.php?action=install` **required** for servers behind migration 10 →
  `/health` **applied=9 / pending=0**, **39 tables / 217 links / 2 invoices + 1 payment**, routes
  **165**; current live already applied=9 (bundle = release/rollback artifact). Estate billing read
  surfaces are deferred to **Inc-11**.

### Increment 13 — Automated billing & notifications (GATED — pending final suite)

*Contract:* [`automated-billing-contract.md`](automated-billing-contract.md) · *Acceptance:*
[`increment-13-acceptance.md`](increment-13-acceptance.md) (**GATED — pending final suite**) ·
*Status:* **built, E2E green, NOT released** — full E2E **130 checks → 129 PASS / 0 FAIL / 1 SKIP**
(`inc13-e2e-result.json`; only SKIP = `expired-at-publish fanout` fixture) with **Inc-5…Inc-10
regressions PASS**; gates **11 migrations / 42 tables / 84 FKs / 52 permissions / 13 roles / 217
links**, **routes 183 at the E2E run** (current tree **194** = 150 web + 44 API). Live preview (owner decision, no rollback) has it
deployed via git pull at **applied=11/pending=0** — but this is **not an accepted release** until
the final combined baseline suite is green (`tests/_baseline2-full.log` running) and the
Inc-12/13 bundle is rebuilt on “bundle go”. Includes migration 11 (`billing_schedules`,
`utility_charges`, `platform_settings`, notification markers, nullable `created_by_user_id`),
`scripts/billing-automate.php` (29/29 CLI checks), notices→bell fan-out + inbox, settings/channels/
providers (test-mode), utility bills + resident My Bills, signed pay links + CSRF-exempt HMAC webhooks,
and the carousel/hero hotfixes (`f192489`/`beeb320`/`57ab0c6`). Rollback = pin `f11367c` (Inc-10
bundle; additive schema/forward-only migrations); UI hotfix effectively live. Known gaps: P2 settings
form polish, resident Account card removals / estate Appearance paused, Inc-11 pending.

### Increment 12 — Estate notice images (GATED — pending final suite)

*Contract:* [`estate-notices-contract.md`](estate-notices-contract.md) (+ `storage/temporary/inc12/
DESIGN-NOTES.md`) · *Acceptance:* [`increment-12-acceptance.md`](increment-12-acceptance.md)
(**GATED — pending final suite**) · *Status:* **built, E2E green, NOT released** — migration
**`20261111_000012_add_estate_notice_images.sql`** (additive column-only: `estate_notices.image_path
VARCHAR(255)` + `image_alt VARCHAR(160)`; no new table/FK/index/backfill) is applied on the live
preview (**applied=11**, 42 tables / 84 FKs / 52/13/217 unchanged). Route
`GET /notices/{uuid}/image` (`NoticeImageController::show`: auth + estate + `notice.view`; streams
from `storage/uploads/notices`, jpeg/png/webp only, `private, max-age=3600`, `nosniff`;
archived/missing/foreign → 404) is in the frozen route table (183 at E2E; **194** current); **13/13 image E2E checks PASS**
(multipart upload/replace/publish, resident list+detail image+alt, authorized serving, anonymous
redirect, draft removal, published-edit lock, foreign 404, resident blocked, archived rejected).
**Not accepted** until the final suite + “bundle go”. Rollback = pin `f11367c`.

### Planned — automated billing & utility bills (Inc-13, DRAFT contract, DESIGN FROZEN)

- Skeleton contract drafted: [`automated-billing-contract.md`](automated-billing-contract.md) —
  **DRAFT / DESIGN FROZEN / BUILD IN PROGRESS — not released** (P0 DB gate, P1 CLI, P2a notices→bell,
  P4 route/controller slice landed; slice tests/E2E pending; do not claim released). **Consolidated
  freeze (T164–T168) applied:** **no new permissions** (billing families reused → 52 permissions /
  13 roles / **217 links unchanged**); **routes 194 total (150 web + 44 API = +29 web)**: platform
  settings notifications/payments (GET+POST), platform automation summary, platform pay-link create,
  public `/pay/{token}`, **2 webhooks** `/webhooks/payments/mpesa|card`, resident **My Bills**
  list/detail/pay-link, **notifications inbox/read**, **`/app/navigation`**, **`/app/account`**,
  **`/notices/{uuid}/image`** (Inc-12), admin utility overview; **estate settings fold into the existing `/admin/settings` (no new route)**; CLI
  `scripts/billing-automate.php`; **utility basis selectable per utility per estate** — **flat /
  metered / both + tariff** (estate admin sets); **period keys UTC**, monthly anchor **1st** with
  month-end clamp, `lead_days` before period start, **due-in 14d**, reminders **3 days before + on
  due**, **schedule defaults + past-due escalation editable by estate admin**;
  **notification channels**: super admin sets global availability (email/in-app always; **SMS +
  WhatsApp only when a real gateway is configured**), estate admin selects per estate;
  **collection = M-Pesa & Card payment links** (provider config + mode test/live, estate method
  enablement, **signed stateless links TTL 24h default / max 7d**, **webhooks signature + replay
  protection → idempotent `billing_payments`**, manual fallback) — not manual-only; **webhook
  CSRF-exempt route mechanism required** (route-level allowlist, no global disable); resident
  read/pay via **auth + household-membership ownership (no permission node)**, guard **none**.
  **T160/schema:** **+3 tables** — `billing_schedules` (scope `estate_subscription`|`utility_bill`)
  + `utility_charges` + **`platform_settings`**; `billing_invoices.created_by_user_id` **nullable**
  for automation/system rows (audit actor **system**); `notification_logs` extended
  (`sent_at`/`reminder_sent_at`, no outbox/inbox); single migration
  **`20261111_000011_add_billing_schedules_and_utility_billing.sql`** (`000012` = Inc-12 announcement
  images — staged additive `ALTER estate_notices ADD image_path/image_alt`; after apply **11
  migrations / 42 tables / 84 FKs / 52/13/217**); expected DB gate **10 migrations / 42 tables / 84
  FKs / 52 permissions / 13 roles / 217 links**; cron + dry-run-first + provider test-mode rollout.
  Remaining owner questions in contract §14. No release claim.

### Planned — estate billing read-only + reports/exports (Inc-11, DRAFT contract, never built)

- Draft contract: [`estate-billing-reports-contract.md`](estate-billing-reports-contract.md) —
  **DRAFT / design-only; build after Inc-12/Inc-13; no release claim**. Scope: estate admin/manager
  **read-only** own-estate subscription + invoices + payment ledger; finance reports (billing
  summary, outstanding/past-due, payments received, utility bills) with date-range filters;
  **streamed CSV** (UTF-8 BOM, RFC 4180, injection-safe, masked PII) + **browser print/PDF**;
  **no writes, no new permissions (52/13/217), no new tables**. Candidate routes `/admin/billing`,
  `/admin/billing/invoices/{uuid}`, `/admin/billing/reports`, `/admin/billing/export.csv`
  (+optional utility report; not added) → **current base 194 (150 web + 44 API); final count confirmed by tests
  at the gate** (194 currently; the batch landed); audit only
  `report.exported` (PII-free). Open owner decisions in contract §12; planned-acceptance skeleton:
  [`increment-11-acceptance.md`](increment-11-acceptance.md) (DRAFT).

### Landed — settings/appearance + platform schedule routes + Inc-11 reports/CSV (DRAFT docs, no claim)

- Backend batch **landed** (modules, 2026-11-12): **estate appearance default**, **admin billing
  settings wiring**, **7 platform schedule routes**, **4 Inc-11 read-only/CSV routes**
  (`/admin/billing`, `/admin/billing/invoices/{uuid}`, `/admin/billing/reports`,
  `/admin/billing/export.csv`). Route table is now **194 (150 web + 44 API: 183 at E2E + 11)**;
  appearance default + admin billing settings wiring are folded into existing settings routes (no
  new route). Docs updated DRAFT: `automated-billing-contract.md` (routes 194 + schedule routes),
  `estate-billing-reports-contract.md` (Inc-11 routes landed), `increment-11-acceptance.md`
  (**GATED — pending final suite**), PICKUP. Tests: baseline2 was **865/859 with 6 known reds**
  (PWA/SW, FirebaseSecurity allowlist, mock-SMS folds); **baseline3 is running** after the folds —
  Inc-11/12/13 acceptance stay **GATED** until it is green, then flip + bundle on “bundle go”.
  **No release claim.**

---

## `0.2.1-dashpass` — 2026-11-11

State at this release: **158 routes** (114 web + 44 API), **8 migrations / 37 tables /
52 permissions / 217 role links**, service-worker **v4**, `/health` → **applied=8**, `pending=0`,
live version **`0.2.1-dashpass`** (environment `production`).
Latest automated suite: **710/710 PASS** (Firebase + Inc-9 baseline; 0 fail, 1769.53s,
`tests/_resume-full.log`), superseding **676/676** at the Inc-9 close (`tests/_final-full.log`).
Gated commits to
deploy: **`742a9ad`** (Inc-8 gate) and **`be9b410`**
(Inc-9 gate); deployment bundle `storage/temporary/mtaapass-deploy.zip` (392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`).
Live enforcement was verified on 2026-11-11: `/health` version `0.2.1-dashpass`, environment
`production`, applied=8/pending=0; Inc-6/7/8/9 routes live; `/service-worker.js` Cloudflare bypass
working (`cf-cache-status: DYNAMIC`, `cache-control: no-cache, max-age=0`).

### Increment 9 — Platform super-admin portal
*Contract:* [`platform-admin-contract.md`](platform-admin-contract.md) ·
*Acceptance:* TBD (gate record) · *Routes:* 147 → **158** (114 web + 44 API; 11 platform web
routes) · *Migration:* **none** (DB stays 8 migrations / 37 tables / 217 links; `/health` applied=8).

**What's new** — a separate `/platform` shell for platform operators: dashboard with real SaaS
stats + the **dashboard-only platform audit** viewer (no `/platform/audit` route); estates list +
provisioning form (account + estate + admin user + default settings + optional plan subscription in
one transaction); estate detail + status form (active/suspended/archived, no hard deletes); plans
list + add + retire; subscriptions list + change plan/status (cancelled/expired terminal →
`409 SUBSCRIPTION_CLOSED`); audited `platform.*` mutations with PII-free metadata; platform-area gate
(platform membership required — estate users 403, unauth 302).

**What owners will notice** — the operator manages all estates/plans/subscriptions from
`/platform`; estate admins, residents and guards cannot access it; provisioning a new estate creates
a working admin login in one step.

**Deploy** — no migration: pin **`be9b410`** (or later) → `deploy-sync install` is a safe no-op →
`/health` stays applied=8 / 37 tables / 217 links → purge + smoke per
[`deployment-cpanel.md`](deployment-cpanel.md) §3d. **Delivered live 2026-11-11** (bundle
`storage/temporary/mtaapass-deploy.zip`, 392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`); `/platform*` routes verified
live (302 unauth, 403 for estate users) and `/health` version `0.2.1-dashpass` / environment
`production` / applied=8 confirmed.

### Increment 8 — Parking administration + overstay
*Contract:* [`parking-administration-contract.md`](parking-administration-contract.md) ·
*Acceptance:* **TBD** (gate record in progress) · *Routes:* 131 → **147** · *Migration:* **none**
(8 migrations / 37 tables / 217 links unchanged; adds seed setting
`parking_overstay_grace_minutes=30` for Greenview).

**What's new**
- Admin parking surfaces: `/admin/parking` overview + zones, bays, allocations (create/end) and
  violations (create/resolve) — soft archive/end/resolve only, no hard deletes.
- Guard violation reporting at `/gate/parking/violations`; admin resolution queue.
- **Overstay detection** as a server-side CLI: `php scripts/parking-overstay.php [--dry-run]`
  flags active sessions past invitation validity + grace as `overstay` and creates exactly one
  violation per session (idempotent; run from cPanel Terminal or a cron entry).
- Estate settings allowlist gains `parking_overstay_grace_minutes` (0–120, seeded 30).

**What owners will notice**
- Parking is now manageable in the admin portal instead of seed/API only; guard devices can log
  parking violations; overdue sessions can be flagged by a scheduled job (no automatic fines).

**Deploy:** no new migration — pull the gated build; `deploy-sync.php?action=install` remains
safe (idempotent) and applies the grace-setting seed; `/health` stays `applied=8`.
Smoke: [`deployment-cpanel.md`](deployment-cpanel.md) §3d (Inc-8) and
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) §4f.

### Increment 7 — Estate notices / announcement centre
*Contract:* [`estate-notices-contract.md`](estate-notices-contract.md) ·
*Acceptance:* [`increment-7-acceptance.md`](increment-7-acceptance.md) · *Routes:* 121 → **131** ·
*Migration:* **9** (`estate_notices`; 8 migrations / 37 tables / 52 permissions / 217 links).

**What's new**
- Admin composer: create draft, edit draft inline, publish, archive — with 409 semantics
  (`NOTICE_ALREADY_PUBLISHED`, `NOTICE_LOCKED`, `NOTICE_CLOSED`) surfaced as flash messages.
- Resident notices list + detail (`/app/notices`, `/app/notices/{uuid}`) with priority ordering
  (urgent → important → normal), audience filtering and expiry; guard read-only list
  (`/gate/notices`).
- **Home announcement feed is now real**: up to 3 published, unexpired notices with a
  “View All” link; the demo/preview announcement content is retired.
- `DemoNoticeSeeder`: 4 Greenview demo notices (3 published incl. one urgent with expiry,
  1 draft; Riverside empty state), idempotent.

**What owners will notice** — notices are created/published in the admin portal and appear on
resident Home + Notices screens and guard devices immediately; drafts, expired and archived
notices are never exposed; cross-tenant views show an honest empty state.

**Deploy:** required `POST /deploy-sync.php?action=install` (migration 9) →
`/health` **applied=8 / 37 tables / 217 links**. Smoke per [`increment-7-acceptance.md`](increment-7-acceptance.md).

### Increment 6 — Household staff directory + incidents
*Contract:* [`staff-incidents-contract.md`](staff-incidents-contract.md) ·
*Acceptance:* [`increment-6-acceptance.md`](increment-6-acceptance.md) · *Routes:* 99 → **121** ·
*Migration:* **8** (7 migrations / 36 tables / 204 links).

**What's new**
- Resident household staff directory (`/app/staff`: create/list/deactivate/reactivate; masked
  phones; no user accounts/invites); admin estate-wide staff view.
- Incidents: residents report + see only their own; guards report at the gate and see their own
  list (`/gate/incidents`); admin queue/filters/detail with `open → investigating →
  resolved/dismissed`, required resolution notes and terminal 409; no hard deletes.
- High/critical incidents raise an **email alert** (`MAIL_ALERT_TO`, masked log, audited,
  non-blocking) using the Inc-5 mail infrastructure.
- `DemoStaffIncidentSeeder`: 3 demo staff + 3 demo incidents (Greenview).

**What owners will notice** — households manage domestic staff in-app; security incidents get a
tracked workflow instead of ad-hoc messages; alert emails reach the configured ops inbox.

**Deploy:** required `POST /deploy-sync.php?action=install` (migration 8) →
`/health` **applied=7 / 36 tables / 204 links**.

### Increment 5 — Email alerts, PWA install enforcement, time-based greeting
*Contract:* [`email-pwa-greeting-contract.md`](email-pwa-greeting-contract.md) ·
*Acceptance:* [`increment-5-acceptance.md`](increment-5-acceptance.md) · *Routes:* 99 (unchanged)
· *Migration:* **none**.

**What's new**
- Mail abstraction: `log` / `SMTP` (implicit TLS 465 / STARTTLS 587) / `Resend` HTTP providers;
  security alert emails for visitor check-in/out and walk-ins (`MAIL_ALERT_TO` fallback),
  post-commit, masked recipients, failure audited and non-blocking; `scripts/mail-test.php --check|--to=`.
- Hardened PWA manifest (id/display_override/categories/shortcuts/icons) and app-wide install
  enforcement (banner, iOS/Android guidance, account “Installation check” card).
- Service worker **v4**; `public/.htaccess` sets `no-cache` + `Service-Worker-Allowed` for the
  worker; **status-bar theming** matches the app surface (theme-color metas + iOS
  `black-translucent`), and the theme toggle updates the meta live.
- Server-rendered time-based greeting (estate-local timezone, “Good morning/afternoon/evening/
  Hello” fallback).

**What owners will notice** — install prompts/instructions and an installation self-check on
account; a greeting that follows the estate clock; security emails from a verified sender.

**Deploy:** no migration; set the server-only mail keys (`MAIL_DRIVER=smtp`,
`smtp.resend.com:465`, username `resend`, key as password, verified sender, `MAIL_ALERT_TO`) and
purge caches.

### Increment 4 — Estate/admin management portal
*Contract:* [`estate-admin-management-contract.md`](estate-admin-management-contract.md) ·
*Acceptance:* [`increment-4-acceptance.md`](increment-4-acceptance.md) · *Routes:* 73 → **99** ·
*Migration:* **none** (tables already existed; soft archive only).

**What's new** — web admin portal for `estate_admin`: dashboard with real stats + recent audit;
buildings; households + members; residents (create/link/suspend/reactivate); guards + gate
assignments; gates; allowlisted estate settings. 18 modules / 10 screens / `admin.css`; every
`/admin*` handler behind an admin-area permission gate; tenant-scoped (David Riverside-only);
no hard deletes.

**What owners will notice** — estate operations are manageable from a browser instead of
seed/CLI; suspended residents lose access; audit trail visible.

**Deploy:** no migration; no `deploy-sync` needed for this increment alone.

### Increment 3 — Vehicles & parking
*Contract:* [`vehicles-parking-contract.md`](vehicles-parking-contract.md) ·
*Acceptance:* [`increment-3-acceptance.md`](increment-3-acceptance.md) · *Routes:* 61 → **73** ·
*Migration:* **7** (`vehicles`, parking zones/bays/allocations/reservations/sessions/violations).

**What's new** — household vehicles (add/list/deactivate, plate normalisation, duplicate 409);
real parking in Bookings (visitor-bay availability, reservations) replacing the preview rows;
invite with `vehicle_id` + `parking_required` (atomic rollback with `NO_PARKING_AVAILABLE`);
guard parking check-in/out sessions; pass + public pass show bay/zone only (no plate/PII).

**What owners will notice** — residents register plates and reserve visitor bays; the guard
session flow handles parking; “Facilities/My Bookings” show real data.

**Deploy:** required migration 7 (fresh install 6 migrations / 34 tables).

### Increment 2 — Visitor access (PIN/QR) + resident PWA layout refresh
*Contract:* [`visitor-access-module-contract.md`](visitor-access-module-contract.md) ·
[`resident-pwa-layout-addendum.md`](resident-pwa-layout-addendum.md) · *Migration:* **6**
(visitor invitations, credentials, logs).

**What's new** — resident invitations (PIN shown once, rotating/extending/revoking, QR image +
public pass page), guard verification/check-in/check-out/walk-in approvals with rate limits and
single-use credentials; PWA layout refresh (dark-navy premium shell, hero estate card, 3×2 quick
tiles, bottom nav with centre QR, Visitors/Bookings screens); one-time dev SMS log adapter.

### Increment 1 — Foundation (Task 001)
*Contract:* [`foundation-contract.md`](foundation-contract.md) · *Migrations:* **1–4**
(SaaS/estates, identity + RBAC, estate structure, security/audit).

**What's new** — plain-PHP MVC core, MySQL/MariaDB schema, phone+OTP authentication (dev SMS
adapter), multi-estate tenancy + RBAC + permission middleware, CSRF + security headers, audit
log, PWA shell/manifest/service-worker, health endpoint, installer/migrator/seeder CLIs, test
harness + README. Baseline version was `0.1.0-foundation`.

---

## Behaviour & breaking changes to be aware of

| Change | Impact |
|---|---|
| **Real notices replace the Home announcement demo** | Home shows published estate notices (≤3, priority order) with “View All”; demo announcements only appear when no real notices exist (local preview mode). |
| **Home “Notices & Updates” tile is now a real link** | It navigates to `/app/notices` (previously a “Soon” toast). |
| **Status-bar theming** | Installed PWAs tint the Android/Chrome status bar from `theme-color` metas and use `black-translucent` on iOS so the app surface shows behind it; the theme toggle updates it live. |
| **Seeded demo notices are no longer prefixed** | Demo notice bodies no longer include a “Demo notice -” prefix (they are still demo data — purge before a real pilot). |
| **Staff editing has no route** | Staff can only be created, deactivated or reactivated (`/app/staff`); correct a record by deactivating and re-creating it. |
| **Guard incident detail is admin-only by design** | Guards list/report incidents (`/gate/incidents`); the detail/triage screen exists only in the admin portal. |
| **Overstay is a server-side job** | No web trigger: run `php scripts/parking-overstay.php` (dry-run first) from the cPanel Terminal or a cron entry. |
| **Bookings shows real parking** | “Facilities” lists real visitor-bay availability and “My Bookings” real reservations; non-parking facilities and preview rows are gone (preview content is disabled in production via `UI_PREVIEW_CONTENT=false`). |
| **`.env` is server-only** | No credentials in the repo/bundle; `APP_KEY` must stay stable (rotation invalidates sessions/QR/pass tokens). |

## Deploy notes per increment

| Increment | Migration | DB sync (owner) | Routes | Fresh-install state | Expected `/health` |
|---|---|---|---|---|---|
| 1 Foundation | 1–4 | first install | 25 | 4 migrations | applied=4 (historical) |
| 2 Visitor access | 6 (no 000005 file) | yes | 35 new / 61 | 6 migrations | applied=6 (historical) |
| 3 Vehicles & parking | 7 | yes | 73 | 6 migrations / 34 tables | applied=6 (historical) |
| 4 Estate/admin portal | **none** | no | 99 | 6 / 34 | applied=6 (historical) |
| 5 Mail/PWA/greeting | **none** | no | 99 | 6 / 34 | applied=6 (historical) |
| 6 Staff + incidents | 8 | **yes** (`install`) | 121 | 7 / 36 / 204 links | applied=7 |
| 7 Notices | 9 | **yes** (`install`) | 131 | 8 / 37 / 217 links | applied=8 |
| 8 Parking admin + overstay | **none** | optional (`install` applies the grace-30 seed) | 147 | 8 / 37 / 217 | applied=8 |
| 9 Platform portal *(delivered live 2026-11-11)* | none | optional (`install` no-op) | 158 | 8 / 37 / 217 | applied=8 |
| 10 Billing *(DELIVERED — bundle pin `f11367c`)* | 10 | **yes** (`install`) | 165 | 9 / 39 / 217 (+2 invoices / 1 payment) | applied=9 |

*Cumulative:* `deploy-sync.php?action=install` applies **all pending migrations** idempotently, so
one deploy can jump a server from any earlier state to the current one (e.g. live pre-Inc-4 → the
Inc-9 build). Use `action=status` (read-only) to inspect, and
[`deployment-rollback.md`](deployment-rollback.md) §2 before any rollback. Migrations are
forward-only; a DB rollback is a backup restore.

**Current live build:** pin gated commit **`be9b410`** (Inc-9), deployed from the
`storage/temporary/mtaapass-deploy.zip` bundle (392 entries, sha256
`8F8C511A87701801F5913D911BCC66E0403D1CD252FE1027747775EF0995DFC9`); the packet
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) documents the procedure (pull →
`deploy-sync install` → `/health` applied=8 → cache purge → smoke matrix). Live was verified on
2026-11-11 (`/health` version `0.2.1-dashpass`, production, applied=8/pending=0; Inc-6/7/8/9 routes
live; SW bypass verified). The next release is **Inc-10 billing** — **DB + route gates passed**
(9 migrations / 39 tables / 165 routes / 2 invoices + 1 payment on the gate DB) but **gated pending
the final suite, billing-test/UI/E2E reports and the T113 bundle**; it is **not deployed** yet
(live stays applied=8 until the owner deploys).

## Known issues / deferred

| Item | Status / workaround |
|---|---|
| Live site is behind the repo | One deployment follows `deploy-now-inc6-inc7.md`; the document-root finding must be fixed first ([`deployment-cpanel.md`](deployment-cpanel.md) §12). |
| Cloudflare / service-worker staleness | Purge Everything after each deploy; hard-refresh once (SW v4 activates and clears old caches). |
| No push notifications / notice fan-out | Notices are in-app only (no email/SMS fan-out, no attachments, no scheduling, no read receipts). |
| Billing | Not installed — Increment 10 (migration 10); the future contract is [`billing-invoices-contract.md`](billing-invoices-contract.md). |
| Firebase phone-auth OTP | Deferred behind a feature flag (`FIREBASE_SMS_ENABLED=false`); server/dev OTP remains the login path. |
| No staff-edit route | Create/deactivate/reactivate only (see behaviour changes). |
| Guard incident detail | Admin-only by design (guards list/report only). |
| Parking | No barriers/ANPR/fines/payments; overstay requires the CLI/cron job; bay archive uses `maintenance` state. |
| Manual install test | Browser install prompts cannot be automated; verify on a real device (manifest/SW/headers are covered by tests). |
| Demo data | Greenview/Riverside demo accounts, notices, staff, incidents and parking fixtures must be purged/replaced before a real resident pilot (`UI_PREVIEW_CONTENT=false`). |
| `APP_KEY` stability | Never rotate casually — sessions and QR/pass tokens are derived from it. |

## QA evidence summary

- **Automated suite (plain PHP):** Increment 3 → **400/400**; Increment 6 → **575/575** (70
  files, 1157s, exit 0); Inc-9 close → **676/676** (172 unit / 438 integration / 66 security, 80
  files; `tests/_final-full.log`); **current baseline → 710/710 PASS, 0 fail**
  (Firebase + Inc-9; `tests/_resume-full.log`, 1769.53s) — the Lead runs the exclusive full suite
  per gate.
- **Independent HTTP E2E (qa-e2e):** Inc-5 **71/71**, Inc-6 **95/96 (0 FAIL)**, Inc-7
  **78/80 (0 FAIL, 2 SKIP)** — real sessions/CSRF/dev-OTP, tenant isolation, 422/409 codes.
- **Per-increment proofs:** Inc-3 114/114 in-process + 46/46 live; Inc-4 150/150 in-process +
  145/145 render + 57/57 HTTP; Inc-6 57/57 backend + 66/66 UI live; Inc-7 51/51 backend +
  81/81 UI live (a real priority-ordering bug was found and fixed by the gate).
- **Database gates:** Inc-6 7 migrations / 36 tables / 204 links; Inc-7 8 / 37 / 52 / 217;
  install twice idempotent on dev + clean-room test DBs; cross-tenant composite-FK rejection
  probed.
- **Release/security:** auto-commit/push watcher healthy; bundle secret screens **CLEAN**
  (only empty `.env.example` placeholders and approved fake test sentinels; no APP_KEY /
  DEPLOY_SYNC_TOKEN / RESEND_API_KEY / MAIL_SMTP_PASSWORD values in tree, index or history);
  `.env*` gitignored; no force-push. See [`git-release-process.md`](git-release-process.md).

## Documentation map

| Increment | Contract | Acceptance record |
|---|---|---|
| Foundation | [`foundation-contract.md`](foundation-contract.md) | task board / README |
| Visitor access | [`visitor-access-module-contract.md`](visitor-access-module-contract.md) | task board / README |
| PWA layout refresh | [`resident-pwa-layout-addendum.md`](resident-pwa-layout-addendum.md) | task board / README |
| 3 Vehicles & parking | [`vehicles-parking-contract.md`](vehicles-parking-contract.md) | [`increment-3-acceptance.md`](increment-3-acceptance.md) |
| 4 Estate/admin portal | [`estate-admin-management-contract.md`](estate-admin-management-contract.md) | [`increment-4-acceptance.md`](increment-4-acceptance.md) |
| 5 Mail / PWA / greeting | [`email-pwa-greeting-contract.md`](email-pwa-greeting-contract.md) | [`increment-5-acceptance.md`](increment-5-acceptance.md) |
| 6 Staff + incidents | [`staff-incidents-contract.md`](staff-incidents-contract.md) | [`increment-6-acceptance.md`](increment-6-acceptance.md) |
| 7 Notices | [`estate-notices-contract.md`](estate-notices-contract.md) | [`increment-7-acceptance.md`](increment-7-acceptance.md) |
| 8 Parking admin + overstay | [`parking-administration-contract.md`](parking-administration-contract.md) | TBD (gate record in progress) |
| 9 Platform portal *(delivered live 2026-11-11)* | [`platform-admin-contract.md`](platform-admin-contract.md) | TBD (gate record) |
| 10 Billing *(future)* | [`billing-invoices-contract.md`](billing-invoices-contract.md) | — |

See also: [`deployment-cpanel.md`](deployment-cpanel.md) (deploy runbook),
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) (owner go-live packet),
[`deployment-rollback.md`](deployment-rollback.md) (rollback + verification),
[`git-release-process.md`](git-release-process.md) (publishing pipeline),
[`database-schema.md`](database-schema.md) (schema reference), and [`../README.md`](../README.md)
(developer overview, demo accounts, routes).

---

*Prepared by the release agent for the product owner/pilot operators. Update this file at each
increment gate (next: **Inc-10 billing — migration 10**; Inc-9 platform portal is delivered live).*
