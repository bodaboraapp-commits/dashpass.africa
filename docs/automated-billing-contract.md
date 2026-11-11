# Automated billing contract — schedules, subscription invoices, utility bills *(DRAFT — DESIGN FROZEN, NOT IMPLEMENTED)*

**Status: DRAFT — DESIGN FROZEN + OWNER DECISIONS FINAL (2026-11-11), NOT IMPLEMENTED.** No code,
routes, migrations or scheduler exist for this feature. **Docs-only until the Lead's build gate**;
do **not** enable or claim it as released. Inc-13 remains **planned**.
**Base state:** Inc-10 (165 routes / 9 migrations / 39 tables / 72 FKs / 52 permissions / 13 roles /
**217 role links**; manual invoice/payment ledger live).

## 0. Design freeze + final owner decisions (authoritative)

**Core freeze**
1. **No new permissions** — reuse existing `billing.*`/platform/estate gates; **role links 217**.
2. **Minimal routes** — platform schedules (list/new, detail, run-now, run log), estate utility
   schedules + preview/generate, resident **My Bills** (list/detail); **plus settings / payment-link /
   webhook routes** (see §8) → **~174–180**, final count frozen at the build gate.
3. **CLI:** `scripts/billing-automate.php` (server-side; dry-run first; cron in rollout).
4. **Period keys in UTC**, monthly anchor **1st** with month-end clamp; `lead_days` before period
   start; **due in 14 days**; reminders **3 days before + on due**.
5. **Resident viewers:** household **primary + active adult members read-only**, via **auth +
   household-membership ownership**; **guard: none**; no new permission node.

**Final owner decisions (2026-11-11)**
6. **Utility basis is selectable per utility per estate** — `flat`, `metered`, or `both` (+ tariff,
   rate); **estate admin sets it** (carried on the estate's utility schedule/charge config).
7. **Notification channels:** **super admin sets global availability** (email + in-app always;
   **SMS + WhatsApp only when a real gateway is configured**); **estate admin selects which
   channels are used for their estate**. No new outbox/inbox table — reuse the **extended
   `notification_logs`**.
8. **Schedule defaults are editable by the estate admin** (per estate): default **monthly 1st**,
   **due 14 days**, **reminders 3 days before + on due**, **past-due escalation** rules.
9. **Collection = M-Pesa & Card payment links** (not manual-only): **super-admin provider config**
   (mode `test`/`live`), **estate-level method enablement**, **signed expiring payment links**,
   **webhook confirmation** (signature verification + replay protection) → **idempotent
   `billing_payments`**, with a **manual confirmation fallback**.
10. **T160 — automation/system rows:** `billing_invoices.created_by_user_id` becomes **nullable**;
    automation rows audit actor **"system"** (utility charges likewise nullable creator).
11. **T160 — schema delta = +3 tables:** one **`billing_schedules`** (scope
    `estate_subscription` | `utility_bill`), one **`utility_charges`**, and one **`platform_settings`**
    (global billing/provider/channel configuration); **no** separate utility_schedules/runs/
    reminders/outbox tables. Migration file
    **`20261111_000011_add_billing_schedules_and_utility_billing.sql`**; **`000012` reserved for
    Inc-12 images**.
12. **Expected DB gate after migration 11:** **10 migrations / 42 tables / 84 FK constraints / 52
    permissions / 13 roles / 217 role links** (from 9/39/72/52/13/217).
13. Docs-only until the build gate; nothing here is a release claim.

---

## 1. Scope and scenarios

**Goal:** generate and send recurring bills automatically, and collect them via payment links, for
two audiences:

1. **Platform → estate subscription invoices** (platform finance): monthly invoice per estate from
   the active subscription, generated `lead_days` before period start, due in 14 days, reminders
   (3 days before + on due), sent by email/in-app, collectable by **M-Pesa/Card payment link**.
2. **Estate → resident utility bills** (estate admin): periodic charges to active households for
   estate utilities with a **per-utility-per-estate basis (flat / metered / both + tariff)**;
   preview → generate → send; residents view/own-pay under **My Bills**.

**Scenarios (v1):** monthly platform invoice per estate; monthly utility batch per estate
(preview → generate); flat/metered/both utility bases; idempotent re-runs and catch-up after missed
days; pause/resume schedules; draft/preview before sending; reminders per freeze; **payment links +
webhook confirmation** (idempotent ledger) with manual fallback; audit + non-blocking send failures;
tenant isolation; no hard deletes (void/pause only); PII-free audit.

**Out of scope (v1):** automatic bank feeds/reconciliation, credit notes/proration/tax/VAT, PDF
generation, dunning automation/legal notices, multi-currency conversion, estate→platform
settlement automation, card/M-Pesa **payouts** (collection only), WhatsApp beyond a configured
gateway.

## 2. Schedule model — one `billing_schedules` table (T160)

| Column group | Fields |
|---|---|
| Identity | `id`, `uuid`, `scope` ENUM(`estate_subscription`,`utility_bill`), `estate_id`, `subscription_id` NULL, `service_code` NULL (utility scope) |
| Utility config (estate admin sets per utility) | `service_name`, `billing_basis` ENUM(`flat`,`metered`,`both`), `unit` (`flat`,`m3`,`kwh`,`month`), `flat_tariff_cents` NULL (flat/both), `unit_rate_cents` NULL (metered/both) |
| Timing + **estate-editable defaults** | `anchor_day = 1` (monthly 1st, month-end clamp), `lead_days`, `due_days = 14`, `reminder_offsets` JSON `[-3, 0]`, `past_due_escalation` JSON (e.g. `{“after_days”: 7, “action”: “notify”}`) — **all editable by the estate admin per estate**, starting from these defaults |
| State | `status` (`active`,`paused`,`archived`), `next_run_on` DATE (UTC), `last_run_at` UTC, `last_period_key` |
| Metadata | `created_by_user_id` NULL (system/operator), timestamps; `UNIQUE(scope, estate_id, subscription_id, service_code)`; no hard delete |

**No separate runs/reminders tables (T160):** run outcome is the **audit log**
(`billing.run_started/completed/failed`), schedule state (`next_run_on`/`last_run_at`/
`last_period_key`) and target-row markers (`sent_at`, `reminder_sent_at`, status). Idempotency via
`last_period_key` + generated-row unique keys.

## 3. Period keys and timezone (frozen: UTC)

- All `*_at` timestamps **UTC** (DATETIME(3)); `period_start`/`period_end`/`due_at`/`next_run_on`
  are **UTC calendar dates**.
- Monthly period key **`YYYY-MM` in UTC** (1st → last day, leap-year safe); **month-end clamp**.
- Generation `lead_days` before `period_start`; missed runs generate the next correct period (one per
  run, catch-up safe). UI display in EAT (`Africa/Nairobi`, UTC+3, no DST) only.
- `due_at` = `period_start` + **14 days**; reminders **-3** and **0** days relative to `due_at`,
  each at most once per target/channel.

## 4. Utility model — one `utility_charges` table (+ basis on the schedule)

Per-utility-per-estate **basis + tariff** come from the estate utility schedule (§2): `flat` →
`amount = flat_tariff_cents`; `metered` → `amount = units × unit_rate_cents`; `both` → flat base +
metered usage. `utility_charges` rows carry the concrete charge for a household/period:

| Column group | Fields |
|---|---|
| Identity | `id`, `uuid`, `estate_id`, `household_id`, `schedule_id` NULL, `service_code`, `service_name`, `period_key` (`YYYY-MM`) |
| Billing | `billing_basis` (`flat`,`metered`,`both`), `flat_amount_cents` NULL, optional `reading_previous`/`reading_current`/`units` (`DECIMAL(12,3)`), `unit_rate_cents` NULL, `amount_cents`, `currency` KES |
| Lifecycle | `status` (`draft`,`open`,`paid`,`void`), `due_at` (UTC date), `sent_at` UTC NULL, `reminder_sent_at` UTC NULL, `payment_link_token` NULL (signed/expiring reference, no secret stored), `payment_method` NULL (`mpesa`,`card`), `payment_confirmed_at` NULL, `notes` |
| Metadata | `created_by_user_id` NULL (system/operator), timestamps |

Constraints: `UNIQUE(household_id, service_code, period_key)` (idempotent batch), composite tenant
FKs; no hard deletes (void only). Money = integer minor units; consumption `DECIMAL(12,3)`.

## 5. Generation, sending, reminders, payment links

- **CLI:** `php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD] [--schedule=<uuid>] [--all] [--send]`
  — `--date` injects the UTC calendar for tests; `--dry-run` writes nothing; `--send` sends the
  generated batch (default draft/preview); exits `0` ok / `1` failed run(s) / `2` usage/validation/
  feature-disabled.
- **Generation:** target rows `draft` by default; auto-send only when enabled per owner policy;
  `lead_days` before start, due-in 14d; re-run **0 duplicates**; system rows `created_by_user_id =
  NULL`, audit actor **system**.
- **Sending/reminders:** channels — **email + in-app always available; SMS + WhatsApp only when a
  real gateway is configured** (super-admin global availability; estate admin selects per estate).
  Every attempt recorded in the **extended `notification_logs`** (+ row `sent_at`/
  `reminder_sent_at`), **no outbox/inbox table**; failure non-blocking, audited, retried (bounded);
  reminders `-3`/`0` once per target/channel; terminal rows skip.
- **Collection — payment links (M-Pesa & Card):**
  - **Provider config:** super admin configures the provider(s) + mode `test`/`live` in
    **`platform_settings`** (credentials server-side env/secret store only — never in repo/bundle).
  - **Estate enablement:** estate admin enables the allowed methods for their estate (M-Pesa and/or
    Card) subject to global availability.
  - **Signed expiring links:** each bill/invoice gets `payment_link_token` (HMAC-signed payload:
    target type/id, estate, amount, currency, method, expiry) — **stateless, time-limited**, no
    secret in the URL beyond the opaque signed token; the public pay page shows amount + methods.
  - **Webhook confirmation:** provider webhook endpoint verifies **signature** + **replay protection**
    (event id/idempotency key + timestamp window) and records an **idempotent `billing_payments`**
    row + status transition (paid), audited; duplicate events are no-ops.
  - **Manual fallback:** operator can still record/confirm a payment manually (M-Pesa/bank
    reference) if provider confirmation is unavailable; same idempotent ledger.
  - Production rollout starts in **provider test mode** (webhook tested with test signatures) before
    live mode.

## 6. Permissions and resident ownership (no new permissions / 217 links)

| Role / account | Access |
|---|---|
| **Super admin** (`platform_super_admin`) | Global billing settings: provider(s) + mode, channel availability (email/in-app; SMS/WhatsApp when gateway configured), schedule defaults; platform schedules/invoices/run log. |
| **Platform finance** (`platform_finance`, existing `billing.*`) | Manage platform subscription schedules (list/new, detail, run-now, run log); view platform invoices/runs; may inspect estate utility billing per owner policy. |
| **Estate admin** (estate-scoped, existing admin area gate + billing family) | Set own estate's **utility basis/tariff**, schedule defaults (anchor/due/reminders/past-due escalation), enabled payment methods + notification channels (from globally available options); preview/generate/send own-estate charges; no platform invoices; no hard deletes. |
| **Resident — household primary + active adult members** | **Read-only + pay**: own household charges under **My Bills**, gated by **auth + household-membership ownership** (`/app/bills`, `/app/bills/{uuid}`, pay page via signed link); **no new permission node**; no create/edit schedules/bills. |
| **Guard** | **None** — no billing routes/surfaces. |
| **Unauthenticated** | `302 /login` (except the signed, expiring public pay page + provider webhook). |

**No new permission slugs, no role-link changes** (52 permissions / 13 roles / **217 links stay**).

## 7. Audit actions

PII-free metadata only (uuids/codes/amounts/statuses/counts, masked recipient counts — never full
emails/phones/notes); automation rows audit actor **system**:

- `billing.schedule_created/updated/paused/resumed`, `billing.run_started/completed/failed`;
- `billing.invoice_generated`, `billing.invoice_sent`;
- `utility.charge_generated`, `utility.charge_sent`, `utility.charge_voided`,
  `utility.charge_payment_confirmed`;
- `billing.reminder_sent/failed` (target + offset/channel only, mirrored in `notification_logs`);
- `billing.payment_link_created`, `billing.payment_webhook_received/verified/rejected`
  (provider event id, outcome — no payload secrets), `billing.payment_confirmed` (manual or
  webhook), `billing.settings_updated` (provider mode/channels — never values).

## 8. API / route plan (frozen minimal; final ~174–180)

Planned **~10–15 new web routes** (no JSON APIs beyond the provider webhook), giving **165 →
~174–180** (finalized at the build gate):

| Method | Path | Controller/action | Gate |
|---|---|---|---|
| GET | `/platform/billing/schedules` | `PlatformBillingScheduleController::index` (list + new form) | platform area; `billing.*` |
| POST | `/platform/billing/schedules` | `…::store` | platform area; `billing.*` |
| GET | `/platform/billing/schedules/{uuid}` | `…::show` (detail/edit/pause) | platform area; `billing.*` |
| POST | `/platform/billing/schedules/{uuid}/run-now` | `…::runNow` (dry-run default; apply flagged) | platform area; `billing.*` |
| GET | `/platform/billing/runs` | `…::runs` (run log; audit-derived) | platform area; `billing.*` |
| GET/POST | `/platform/settings/billing` | `PlatformBillingSettingsController::index/store` (providers, mode, channel availability, defaults) | platform area; super admin |
| GET | `/admin/utility-bills` | `AdminUtilityChargeController::index` (own-estate schedules + batch list) | admin area; estate-scoped |
| POST | `/admin/utility-bills/preview` | `…::preview` (dry-run batch, no writes) | admin area; estate-scoped |
| POST | `/admin/utility-bills/generate` | `…::generate` (apply batch + optional send) | admin area; estate-scoped |
| GET | `/app/bills` | `ResidentBillingController::index` (own household charges) | auth + household-membership ownership |
| GET | `/app/bills/{uuid}` | `ResidentBillingController::show` (own charge detail/status + pay CTA) | same |
| GET | `/pay/{token}` | `PublicPaymentController::show` (signed expiring pay page; amount/methods) | signed token |
| POST | `/pay/{token}/start` | `PublicPaymentController::start` (create provider payment intent/session) | signed token + CSRF |
| POST | `/webhooks/billing/{provider}` | `BillingWebhookController::handle` (signature + replay protection → idempotent ledger) | provider signature (no session/CSRF) |

Expected totals: **165 → ~174–180 routes** (web 121 → ~130–136, API stays 44); final count recorded
in this contract at the build gate.

## 9. Migration plan — one migration (T160 + final decisions)

- **`20261111_000011_add_billing_schedules_and_utility_billing.sql`** (single, additive):
  1. `ALTER TABLE billing_invoices MODIFY created_by_user_id … NULL` (automation/system rows);
  2. extend `notification_logs` with `sent_at` / `reminder_sent_at` markers (+ WhatsApp channel
     enum/value support, no new table);
  3. `CREATE TABLE billing_schedules` (scope `estate_subscription` | `utility_bill`, utility
     basis/tariff + estate-editable defaults);
  4. `CREATE TABLE utility_charges` (flat/metered/both + readings + pay markers);
  5. `CREATE TABLE platform_settings` (global billing/provider/channel configuration, KV/JSON);
  6. indexes/unique keys + composite tenant FKs as above.
- **DB gate exact:** **10 migrations / 42 tables / 84 FK constraints / 52 permissions / 13 roles /
  217 role links** (from 9/39/72/52/13/217).
- **`000012` is reserved for Inc-12 images** (not this feature). Forward-only; no destructive
  changes; Inc-10 invoice/payment tables otherwise untouched; clean-room + integrity checker
  updated in the same increment.

## 10. Acceptance criteria (v1)

1. **Schedules** — one `billing_schedules` model for both scopes; estate-editable defaults (anchor
   1st, due 14d, reminders -3/0, past-due escalation); create/edit/pause/run-now; paused no-run.
2. **Period/timezone** — UTC period keys (`YYYY-MM`), 1st anchor + month-end clamp, `lead_days`
   before start; deterministic `--date`; re-run 0 duplicates.
3. **Utility basis per utility per estate** — flat / metered / both + tariff set by estate admin;
   correct amounts/rounding; readings optional; preview changes nothing; catch-up correct.
4. **Sending/reminders/channels** — global availability by super admin (email/in-app; SMS+WhatsApp
   only when a real gateway is configured), estate admin selects per estate; recorded in extended
   `notification_logs`; reminders -3/0 once; terminal rows skip; failure non-blocking + retry.
5. **Payment links + webhook** — providers + mode configured by super admin (`platform_settings`,
   secrets never in repo); estate enablement per method; signed expiring links; webhook signature +
   replay protection → **idempotent `billing_payments`** + paid transition; duplicate events no-op;
   manual fallback confirmation works.
6. **Permissions/ownership/security** — no new permissions/links (217); resident pay/read via
   household-membership ownership; guard none; system rows `created_by_user_id = NULL` actor
   **system**; CSRF/XSS; tenant isolation; no hard deletes; PII-free audit; CLI-only automation;
   feature flag off by default; webhook unauthenticated but signature-verified + replay-safe.
7. **Ops/tests** — cron + dry-run documented; exit codes; run outcomes audited; clean-room +
   integrity green (**10/42/84/52/13/217**); unit (UTC period math, lead/due/reminder offsets,
   pricing incl. flat/metered/both), integration (generation/reminders/ownership/payment link/
   webhook idempotency/tenant), E2E (schedule → generate → send → My Bills → pay link → webhook →
   reminder → manual fallback) + regressions.
8. **Docs** — contract finalized, deploy/cron/webhook runbook, changelog/acceptance, idempotent demo
   data.

## 11. Demo data (seeder, idempotent)

Greenview only: `platform_settings` demo defaults (providers in **test mode**, email/in-app
available, SMS/WhatsApp off unless configured); two `billing_schedules` — one `estate_subscription`
(Growth plan, anchor 1st, lead 3d, due 14, reminders -3/0) and one `utility_bill` (Water, basis
`both`: flat tariff + meter rate, anchor 1st, lead 3d, due 14); one draft + one open
`utility_charges` row for a Greenview household (flat and metered examples); a demo **signed pay
token** shown in test mode only (no real money). Riverside: no utility schedules/charges (honest
empty state). Deterministic UUIDs/natural keys → re-seeding is a no-op; never seed provider
credentials.

## 12. Rollout (cron + dry-run + test-mode payments first)

1. Implement behind `AUTOMATED_BILLING_ENABLED=false`; tests + clean-room; docs.
2. Staging: `php scripts/billing-automate.php --dry-run --date=<UTC date> --all` (no writes) →
   review; then apply on a copy DB, review drafts + `MAIL_DRIVER=log` + `notification_logs`.
3. **Payments in test mode:** configure provider test keys in `platform_settings`, run a test
   payment link → test webhook with **signature + replay** checks → confirm idempotent ledger and
   manual fallback; only then switch provider to `live` with owner approval.
4. Enable schedules (draft-first), add **cPanel cron** (e.g. daily 06:00 EAT:
   `php /home/<user>/mtaapass/scripts/billing-automate.php --all`); auto-send only after a clean
   cycle.
5. Monitor run audit, `notification_logs`, provider/webhook logs; pause schedules or disable the
   flag on incidents; rollback = disable flag + pause schedules (rows retained; provider mode back
   to test).
6. Live smoke: CLI **dry-run on live before enabling cron**; `/health` unchanged; second dry-run
   0 new; resident My Bills + pay page; estate preview; platform run log.

## 13. Compatibility / dependencies

Builds on Inc-10 billing tables (`billing_invoices`, `billing_payments`) + subscriptions/estates/
plans; migration 11 only alters `billing_invoices.created_by_user_id` (nullable), extends
`notification_logs`, and adds the 3 tables above. Email/in-app use existing Mail/PWA mechanisms;
SMS/WhatsApp require a real configured gateway. Payment providers are external (M-Pesa aggregator /
card processor) — server-side secrets only, webhook endpoint secured by signature + replay
protection. Reuses CLI/migrator/seed/audit/release pipeline. No public API surface change beyond the
webhook + pay page.

## 14. Remaining owner questions

1. **Which M-Pesa aggregator and card processor** (e.g. IntaSend/Flutterwave/Paystack/Stripe) — and
   their webhook contract/event ids for idempotency?
2. Payment-link expiry window (e.g. 7/14/30 days) and whether a link can be re-sent/rotated.
3. Platform subscription invoice cadence beyond monthly (quarterly/annual) — v1 monthly only.
4. Auto-send switch timing after the draft-first cycle.
5. Past-due escalation specifics (after how many days, what action: notify only vs restrict?).
6. Meter readings source — admin entry (frozen) vs CSV import; resident submission later?
7. SMS gateway and WhatsApp provider choices/credentials (only enable when configured).
8. Estate billing recipient/email source for platform subscription invoices.
9. Proration/credit rules for mid-month schedules — deferred to v2.
10. Fees/commission handling and settlement reporting for M-Pesa/Card collections.

---

*DRAFT skeleton — design frozen 2026-11-11 + T160 reconciled + owner decisions final; **no
implementation, routes, migrations or scheduler exist**. Inc-13 is planned/next, not released.
Update at the Lead's build gate.*
