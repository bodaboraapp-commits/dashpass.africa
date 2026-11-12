# Automated billing contract — schedules, subscription invoices, utility bills *(DRAFT — DESIGN FROZEN, NOT IMPLEMENTED)*

**Status: DRAFT — DESIGN FROZEN (T164–T168) + BUILD IN PROGRESS (P0 gate, P1 CLI, P2a notices→bell,
P4 route/controller slice), NOT RELEASED.** Code/migration/tests now exist in the working tree and
are being validated slice by slice; **do not claim the feature as released**. Inc-13 remains
**planned / release pending** until its suite + Lead gate.
**Base state:** Inc-10 (165 routes / 9 migrations / 39 tables / 72 FKs / 52 permissions / 13 roles /
**217 role links**).

## 0. Consolidated design freeze (authoritative — T164–T168)

1. **No new permissions** — reuse existing `billing.*`/platform/estate gates; **permissions 52,
   roles 13, links 217 unchanged**.
2. **Routes: 195 total = 151 web + 44 API (+30 web)** — frozen list in §8: platform settings
   (notifications, payments), platform automation summary, platform pay-link create, public
   `/pay/{token}`, **2 webhooks** (`/webhooks/payments/mpesa|card`), resident bills list/detail/pay-
   link, notifications inbox/read, admin utility overview, and **`/app/navigation`** (shared nav +
   bell badges). **Estate settings fold into the existing `/admin/settings` route (no new route).**
   *(Post-freeze work in flight — 2026-11-12: estate appearance default, admin billing settings
   wiring, platform schedule routes (7), Inc-11 read-only/CSV (4) — **landed 2026-11-12: route table now 195**;
   final count reported by tests at the gate.)*
3. **CLI:** `scripts/billing-automate.php` (server-side; dry-run first; cron in rollout).
4. **Period keys in UTC**, monthly anchor **1st** with month-end clamp; `lead_days` before period
   start; **due in 14 days**; reminders **3 days before + on due**.
5. **Utility basis selectable per utility per estate** — `flat` / `metered` / `both` + tariff
   (estate admin sets; carried on the estate utility schedule; concrete `utility_charges` rows).
6. **Notification channels:** **super admin sets global availability** (email + in-app always;
   **SMS + WhatsApp only when a real gateway is configured**); **estate admin selects per estate**.
   Reuse **extended `notification_logs`** (+ `sent_at`/`reminder_sent_at`); **no outbox/inbox table**.
7. **Schedule defaults editable by the estate admin** (per estate) — default monthly **1st**, due
   **14d**, reminders **3d before + on due**, **past-due escalation**.
8. **Collection = M-Pesa & Card payment links** (not manual-only): super-admin provider config
   (mode `test`/`live`) in `platform_settings`, estate-level method enablement, **signed stateless
   pay links** (**TTL 24h default, max 7d**), **webhooks** with signature + replay protection →
   **idempotent `billing_payments`**, manual fallback.
9. **T160:** `billing_invoices.created_by_user_id` becomes **nullable** (automation rows audit actor
   **system**); resident read/pay via **auth + household-membership ownership**, no permission node;
   guard **none**.
10. **Schema delta = +3 tables** — `billing_schedules` (scope `estate_subscription` | `utility_bill`),
    `utility_charges`, `platform_settings`. Migration
    **`20261111_000011_add_billing_schedules_and_utility_billing.sql`**. **`000012` = Inc-12
    announcement images** (`20261111_000012_add_estate_notice_images.sql`, additive `ALTER
    estate_notices ADD image_path/image_alt`, no new table — **applied on the live preview**
    (applied=11); Inc-12 acceptance is **GATED — pending final suite**, not released).
11. **Expected DB gate after migration 11:** **10 migrations / 42 tables / 84 FK constraints / 52
    permissions / 13 roles / 217 role links** (from 9/39/72/52/13/217). **After migration 12 (Inc-12
    images): 11 migrations / 42 tables / 84 FKs / 52 / 13 / 217** (column-only migration; no new
    table/FK/index).
12. **Webhook CSRF-exempt route mechanism is required** — a reviewed route-level CSRF-exemption
    (allowlist/middleware flag) for the 2 webhook routes only (no global CSRF disable); webhooks
    authenticate by **provider signature + replay protection**, not session.
13. Docs-only until the build gate; nothing here is a release claim.
14. **Build progress (2026-11-12):** ✅ **P0 DB gate 10/42/84/52/13/217**; ✅ **P1 automation CLI
    (29/29 CLI tests; refold pending)**; ✅ **P2a notices→bell backend + UI**; ✅ Home announcements
    auto-scroll; ✅ Inbox+Notices+bell IA; ✅ **P4 route/controller slice landed** — settings
    (notifications/payments), automation summary, pay-link create, 2 CSRF-exempt webhooks (reviewed
    `config/security.php` allowlist), resident bills, admin utility, notifications
    (`webIndex`/`webRead`) + `/app/navigation`; **wired route table = 195 (151 web + 44 API)**.
    Remaining: P2 channels/settings UI wiring → P3 utility bills UI/tests → E2E/suites → Inc-13
    gate; Inc-12 images separately (see §15).

---

## 1. Scope and scenarios

**Goal:** generate/send recurring bills automatically and collect them with M-Pesa/Card payment
links:

1. **Platform → estate subscription invoices** (platform finance) — monthly from the active
   subscription, `lead_days` before period start, due 14d, reminders -3/0, sent by email/in-app,
   payable by signed M-Pesa/Card link.
2. **Estate → resident utility bills** (estate admin) — periodic charges per active household with
   **per-utility-per-estate basis (flat/metered/both + tariff)**; preview → generate → send;
   residents view/pay under **My Bills**.

**Scenarios (v1):** monthly platform invoice per estate; monthly utility batch (preview →
generate); flat/metered/both; idempotent re-runs + catch-up; pause/resume schedules; draft/preview;
reminders per freeze; **pay links + webhook confirmation** (idempotent ledger) + manual fallback;
audit + non-blocking send failures; tenant isolation; no hard deletes; PII-free audit.

**Out of scope (v1):** bank feeds/reconciliation, credit notes/proration/tax/VAT, PDF, dunning
automation, multi-currency, settlement/payouts, WhatsApp beyond a configured gateway.

## 2. Schedule model — one `billing_schedules` table

| Column group | Fields |
|---|---|
| Identity | `id`, `uuid`, `scope` ENUM(`estate_subscription`,`utility_bill`), `estate_id`, `subscription_id` NULL, `service_code` NULL |
| Utility config (estate admin) | `service_name`, `billing_basis` ENUM(`flat`,`metered`,`both`), `unit`, `flat_tariff_cents` NULL, `unit_rate_cents` NULL |
| Timing/defaults (estate-editable) | `anchor_day=1`, `lead_days`, `due_days=14`, `reminder_offsets` JSON `[-3,0]`, `past_due_escalation` JSON |
| State | `status` (`active`,`paused`,`archived`), `next_run_on` DATE (UTC), `last_run_at` UTC, `last_period_key` |
| Metadata | `created_by_user_id` NULL (system/operator), timestamps; `UNIQUE(scope, estate_id, subscription_id, service_code)`; no hard delete |

**Schedule payload/response keys (post-polish 2026-11-12):** decorated rows add
`last_invoice_number` (last generated invoice for the schedule/subscription), `anchor_day` **1–28**,
`anchor_month` **1–12**, `clamp_to_month_end` (bool, **default true** — the anchor day clamps to the
month's last day), and `amount_source` (`plan_price` | `fixed_amount` | `manual_draft`, with the
`amount_sources` list). Form defaults: `anchor_day=1`, `anchor_month=1`, `clamp_to_month_end=true`.
**Run-now returns a preview**, not a mutation: `dry_run=true`, `scanned`, `due`, `generated=0`,
`skipped`, `invoices[]` (each `preview=true`, `period_key`, `next_run_at`, `scope`, `amount_cents`,
`currency`, `unit`) plus `next_run_at`, `scope`, and `message` “Dry-run only: no invoices were
generated.”; audited as `billing.schedule_run_preview`.
**There is no UI apply endpoint by contract:** the platform schedules screen is
preview-only; applying a due run is done by the CLI automation (`--scope=all|schedule`
or `--all`) under its normal locks/audit. The UI must not mutate invoices from
`run-now`.

No separate runs/reminders tables: run outcome = **audit** (`billing.run_*`), schedule state, and
target-row markers (`sent_at`/`reminder_sent_at`/status); idempotency via `last_period_key` +
generated-row unique keys.

## 3. Period keys and timezone (UTC)

All `*_at` timestamps UTC (DATETIME(3)); period/due/next-run are **UTC calendar dates**. Monthly
period key **`YYYY-MM` UTC** (1st → last day, leap-year safe; month-end clamp). Generation
`lead_days` before `period_start`; missed runs generate the next correct period (one per run).
UI display in EAT (`Africa/Nairobi`, UTC+3, no DST). `due_at` = start + **14 days**; reminders
**-3**/**0**, once per target/channel.

## 4. Utility charges — one `utility_charges` table (+ basis on the schedule)

`flat` → flat tariff; `metered` → units × unit rate; `both` → flat base + metered usage.

| Column group | Fields |
|---|---|
| Identity | `id`, `uuid`, `estate_id`, `household_id`, `schedule_id` NULL, `service_code`, `service_name`, `period_key` |
| Billing | `billing_basis`, `flat_amount_cents` NULL, optional `reading_previous`/`reading_current`/`units` (`DECIMAL(12,3)`), `unit_rate_cents` NULL, `amount_cents`, `currency` KES |
| Lifecycle | `status` (`draft`,`open`,`paid`,`void`), `due_at`, `sent_at` NULL, `reminder_sent_at` NULL, `payment_method` NULL (`mpesa`,`card`), `payment_confirmed_at` NULL, `notes` |
| Metadata | `created_by_user_id` NULL, timestamps |

`UNIQUE(household_id, service_code, period_key)`; composite tenant FKs; no hard deletes. Money =
minor units; consumption `DECIMAL(12,3)`. **Signed pay links are stateless** — the token is minted
on demand; an optional marker column is never a secret.

## 5. Generation, sending, reminders, pay links, webhooks

- **CLI:** `php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD] [--schedule=<uuid>] [--all] [--send]`
  — `--date` UTC injection; `--dry-run` writes nothing; `--send` sends the batch (default
  draft/preview); exits 0/1/2.
- **Generation:** drafts by default; auto-send per policy; `lead_days` before start, due 14d; re-run
  **0 duplicates**; system rows `created_by_user_id = NULL` + actor **system**.
- **Sending/reminders:** channels — email + in-app always available; **SMS + WhatsApp only with a
  real gateway** (global availability by super admin; estate selection by estate admin); every
  attempt in extended `notification_logs` (+ row markers), **no outbox/inbox table**; failures
  non-blocking/audited/retried; reminders -3/0 once; terminal rows skip.
- **Pay links (signed, stateless, expiring):** super admin configures provider(s) + mode
  (`test`/`live`) in `platform_settings` (secrets server-side only); estate admin enables methods;
  an operator (or the resident) requests a link → **HMAC-signed payload** (target type/id, estate,
  amount, currency, method, `exp`, nonce) — **TTL 24h default, max 7d**; public `/pay/{token}`
  verifies signature/expiry server-side; no state/secret in the DB or URL beyond the opaque token.
- **Webhooks:** `POST /webhooks/payments/mpesa` and `/webhooks/payments/card` — **CSRF-exempt
  (route-level allowlist mechanism, no global disable)**, authenticated by **provider signature**
  and **replay protection** (event id/idempotency + timestamp window); verification records an
  **idempotent `billing_payments`** row and the paid transition; duplicate/replayed events are
  no-ops; audited (`billing.payment_webhook_*`). **Manual fallback** confirmation remains.
- **Feature flag:** `AUTOMATED_BILLING_ENABLED=false` by default; CLI refuses (exit 2) until
  enabled; cron after staging dry-run.

## 6. Permissions, ownership, settings scope

| Role / account | Access |
|---|---|
| **Super admin** | Global settings: provider(s) + mode, channel availability (email/in-app; SMS/WhatsApp with gateway), defaults (platform settings pages). |
| **Platform finance** | Platform automation summary + schedule management (via automation page/CLI), platform invoices/run outcomes, pay-link creation; may inspect estate utility billing per policy. |
| **Estate admin** | Own estate: utility basis/tariff, schedule defaults incl. past-due escalation, enabled methods/channels (**all via existing `/admin/settings` — no new settings route**), utility overview + generate/send. |
| **Resident — household primary + active adult members** | **Read-only + pay**: own household charges (My Bills list/detail), request pay link, notifications inbox/read; gated by **auth + household-membership ownership**; no permission node; guard **none**. |
| **Guard** | None. |
| **Unauthenticated** | `302 /login` (exceptions: `/pay/{token}` signed page, 2 provider webhooks signature-verified). |

**Settings payload — estate `billing_meta`** (from `SettingsController` /
`EstateBillingSettingsService`, stored in `estate_settings` scoped by estate): per-utility
`billing.utility_basis.*`, `billing.utility_flat_cents.*`, `billing.utility_unit_rate_cents.*`,
`billing.notification_channels` (source `platform` when global channel config is set),
`billing.payment_methods`, `billing.schedule_defaults` (`anchor_day=1`, `due_days=14`,
`reminder_before_days=3`, `remind_on_due=true`, `past_due_escalation=[7,14,30]`), and
`ui.appearance_default` (`default` | `light` | `dark`); each entry exposes
`value` / `source` (`estate` | `platform` | `default`) / `overridable`.

**No new permission slugs/role links** (52/13/217 unchanged). Platform area gate on platform routes;
estate scoping by `Context`; webhooks bypass session only, never signature checks.

## 7. Audit actions

PII-free metadata (uuids/codes/amounts/statuses/counts, masked recipients); automation rows actor
**system**: `billing.schedule_created/updated/paused/resumed`, `billing.run_started/completed/
failed`, `billing.invoice_generated/sent`, `utility.charge_generated/sent/voided`,
`utility.charge_payment_confirmed`, `billing.reminder_sent/failed`, `billing.payment_link_created`,
`billing.payment_webhook_received/verified/rejected`, `billing.payment_confirmed`,
`billing.settings_updated` (mode/channels only — never values), `notification.read`.

## 8. Route plan — frozen: **195 total (151 web + 44 API = +30 web)**

| # | Method | Path | Controller/action | Gate |
|---|---|---|---|---|
| 1 | GET | `/platform/settings/notifications` | `PlatformBillingSettingsController::notifications` (global channel availability) | platform area; super admin |
| 2 | POST | `/platform/settings/notifications` | `…::saveNotifications` | platform area; super admin |
| 3 | GET | `/platform/settings/payments` | `…::payments` (provider config + mode test/live) | platform area; super admin |
| 4 | POST | `/platform/settings/payments` | `…::savePayments` | platform area; super admin |
| 5 | GET | `/platform/billing/automation` | `PlatformBillingAutomationController::index` (automation summary: schedules, next runs, last run outcomes, pay-link entry) | platform area; `billing.*` |
| 6 | POST | `/platform/billing/pay-links` | `PlatformBillingAutomationController::createPayLink` (signed stateless link for invoice/charge) | platform area; `billing.*` |
| 7 | GET | `/pay/{token}` | `PublicPaymentController::show` (signed expiring pay page; amount/methods) | signed token (no session) |
| 8 | POST | `/webhooks/payments/mpesa` | `PaymentWebhookController::mpesa` (signature + replay → idempotent ledger) | **CSRF-exempt**; provider signature |
| 9 | POST | `/webhooks/payments/card` | `PaymentWebhookController::card` | **CSRF-exempt**; provider signature |
| 10 | GET | `/app/bills` | `ResidentBillingController::index` (My Bills list) | auth + household-membership ownership |
| 11 | GET | `/app/bills/{uuid}` | `ResidentBillingController::show` (detail/status/pay CTA) | same |
| 12 | POST | `/app/bills/{uuid}/pay-link` | `ResidentBillingController::payLink` (request signed link) | same + CSRF |
| 13 | GET | `/notifications` | `NotificationController::webIndex` (in-app inbox) | auth |
| 14 | POST | `/notifications/{uuid}/read` | `NotificationController::webRead` | auth + own notification |
| 15 | GET | `/admin/utility-bills` | `AdminUtilityChargeController::index` (own-estate utility overview + generate) | admin area; estate-scoped |
| 16 | GET | `/app/navigation` | `NavigationController::index` (shared navigation model + notification bell badges) | auth + estate |
| 17 | GET | `/app/account` | `AccountController::webAccount` (resident account/home card removals surface) | auth |
| 18 | GET | `/notices/{uuid}/image` | `NoticeImageController::show` (Inc-12 notice image: streams from `storage/uploads/notices`, jpeg/png/webp only, `private, max-age=3600`, `nosniff`; archived/missing/foreign → 404) | auth + estate + `notice.view` |

**Estate settings (channels/methods/defaults) fold into the existing `/admin/settings` route — no
new route.** Expected/current totals: **165 → 195 routes** (**web 121 → 151 = +30**, API stays 44).
Schedule create/edit/pause/run-now actions live on the automation page (`#5`) + CLI.
**P4 status:** all 16 routes/controllers landed (2026-11-12). **Post-E2E batch (2026-11-12):** 7
platform schedule routes landed — `GET /platform/billing/schedules`, `GET …/new`,
`POST /platform/billing/schedules`, `GET /…/{uuid}`, `POST /…/{uuid}/edit`,
`POST /…/{uuid}/toggle`, `POST /…/{uuid}/run-now` (`PlatformBillingScheduleController`); plus 4
Inc-11 read-only/CSV routes under `/admin/billing*` (see [`increment-11-acceptance.md`](increment-11-acceptance.md));
estate **appearance default** + **admin billing settings wiring** fold into existing `/admin/settings`
(no new route). **Current route table = 195 (151 web + 44 API)** — final count confirmed by tests at
the gate.

## 9. Migration plan — one migration

**`20261111_000011_add_billing_schedules_and_utility_billing.sql`** (single, additive):
1. `ALTER TABLE billing_invoices MODIFY created_by_user_id … NULL`;
2. extend `notification_logs` with `sent_at`/`reminder_sent_at` (+ WhatsApp channel support);
3. `CREATE TABLE billing_schedules` (both scopes, utility basis/tariff, estate-editable defaults);
4. `CREATE TABLE utility_charges` (flat/metered/both + readings + payment markers);
5. `CREATE TABLE platform_settings` (global billing/provider/channel config, KV/JSON);
6. indexes/unique keys + composite tenant FKs.

**DB gate exact:** **10 migrations / 42 tables / 84 FKs / 52 permissions / 13 roles / 217 links**
(from 9/39/72/52/13/217). **`000012` = Inc-12 announcement images** (additive `ALTER estate_notices
ADD image_path VARCHAR(255) NULL, image_alt VARCHAR(160) NULL`; no new table/FK/index; staged, not
applied until the Inc-12 gate → then **11 migrations / 42 tables / 84 FKs / 52/13/217**).
Forward-only; no destructive changes; clean-room + integrity checker updated in the same increment.
**CSRF-exempt route mechanism for the 2 webhooks is part of the increment** (middleware/allowlist +
security tests) — landed via `config/security.php` allowlist.

## 10. Acceptance criteria (v1)

1. **Schedules** — `billing_schedules` both scopes; estate-editable defaults (anchor 1st, due 14d,
   reminders -3/0, past-due escalation); pause/resume/run-now via automation page/CLI.
2. **Periods** — UTC keys, 1st anchor + clamp, `lead_days`; deterministic `--date`; re-run 0 dupes.
3. **Utility basis** — flat/metered/both + tariff per utility per estate; correct amounts; preview
   no-writes; catch-up correct.
4. **Sending/reminders/channels** — global availability (super admin), estate selection, extended
   `notification_logs`, reminders once, failure non-blocking + retry.
5. **Pay links + webhooks** — TTL **24h default / max 7d**, HMAC-signed stateless tokens; 2 webhook
   routes **CSRF-exempt only via reviewed route allowlist**; signature + replay protection →
   idempotent `billing_payments`; duplicates no-op; manual fallback works.
6. **Permissions/ownership/security** — 52/13/217 unchanged; resident via membership ownership;
   guard none; system rows actor **system**; CSRF/XSS; tenant isolation; no hard deletes; PII-free
   audit; feature flag off.
7. **Ops/tests** — cron+dry-run docs; exit codes; clean-room + integrity green (**10/42/84/52/13/
   217**); **routes exactly 195 (150 web + 44 API)**; unit/integration/E2E (schedule→generate→send→
   My Bills→pay link→webhook→reminder→manual) + regressions.
8. **Docs** — contract finalized, deploy/cron/webhook runbook, changelog/acceptance, idempotent demo.

## 11. Demo data (seeder, idempotent)

Greenview: `platform_settings` demo defaults (providers **test mode**, email/in-app available,
SMS/WhatsApp off); 2 schedules (`estate_subscription` Growth: anchor 1, lead 3d, due 14, reminders
-3/0; `utility_bill` Water: basis `both`, flat + meter rate); one draft + one open `utility_charges`
row (flat and metered examples); demo notifications inbox entries; demo signed pay token shown in
test mode only (no real money). Riverside: empty (honest). Never seed provider credentials.

## 12. Rollout (cron + dry-run + test-mode payments first)

1. Implement behind `AUTOMATED_BILLING_ENABLED=false`; tests + clean-room; docs.
2. Staging: `billing-automate.php --dry-run --date=<UTC> --all` (no writes) → review; apply on a
   copy DB; review drafts + `notification_logs`.
3. Provider **test mode**: mint a link (TTL 24h), complete test payment, exercise **both webhooks**
   incl. **CSRF-exempt** check, signature + **replay** rejection, duplicate no-op, manual fallback;
   then switch to `live` with owner approval.
4. Enable schedules (draft-first), add **cPanel cron** (daily 06:00 EAT:
   `php /home/<user>/mtaapass/scripts/billing-automate.php --all`); auto-send after a clean cycle.
5. Monitor run audit, `notification_logs`, webhook logs; pause/disable on incidents; rollback =
   flag off + pause schedules + provider back to test.
6. Live smoke: CLI **dry-run on live before cron**; `/health` unchanged; second dry-run 0 new;
   My Bills + `/pay/{token}` render; platform automation summary; notifications inbox.

## 13. Compatibility / dependencies

Builds on Inc-10 billing tables/subscriptions/estates/plans; migration 11 alters only
`billing_invoices.created_by_user_id` (nullable), extends `notification_logs`, adds the 3 tables.
Email/in-app reuse existing Mail/PWA; SMS/WhatsApp need a real gateway. Payment providers external
(M-Pesa aggregator / card processor) — server-side secrets only; webhooks secured by signature +
replay protection + CSRF exemption. No public API surface change beyond webhooks/pay page.

## 14. Remaining owner questions

1. **Which M-Pesa aggregator and card processor** (e.g. IntaSend/Flutterwave/Paystack/Stripe) and
   their event-id/webhook contract.
2. Payment-link **TTL confirmation** (frozen 24h default / max 7d) and re-send/rotate policy.
3. Auto-send switch timing after the draft-first cycle.
4. Past-due escalation specifics (days/action: notify only vs restrict).
5. Meter readings source — admin entry (frozen) vs CSV import; resident submission later?
6. SMS gateway and WhatsApp provider choices/credentials (only when configured).
7. Estate billing recipient/email source for platform subscription invoices.
8. Proration/credit rules for mid-month schedules — deferred to v2.
9. Fees/commission handling and settlement reporting for M-Pesa/Card collections.
10. Notification retention/pruning for `notification_logs` (inbox growth policy).

---

*DRAFT skeleton — design frozen 2026-11-11 + owner decisions + T164–T168 consolidated; **no
implementation, routes, migrations or scheduler exist**. Inc-13 is planned/next, not released.
Update at the Lead's build gate.*
