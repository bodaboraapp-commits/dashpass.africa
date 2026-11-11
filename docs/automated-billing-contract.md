# Automated billing contract — schedules, subscription invoices, utility bills *(DRAFT — DESIGN FROZEN, NOT IMPLEMENTED)*

**Status: DRAFT — DESIGN FROZEN (2026-11-11), NOT IMPLEMENTED.** No code, routes, migrations or
scheduler exist for this feature. **Docs-only until the Lead's build gate**; do **not** enable or
claim it as released. Inc-13 remains **planned**.
**Base state:** Inc-10 (165 routes / 9 migrations / 39 tables / 52 permissions / **217 role links**;
manual invoice/payment ledger live). **Design freeze decisions:** Lead, 2026-11-11.

## 0. Design freeze (authoritative decisions)

1. **No new permissions** — reuse the existing `billing.*` families; **role links stay 217**.
2. **Minimal routes** — platform schedules (list/new, detail, run-now, run log), estate utility
   schedules (+ preview/generate), resident **My Bills** (list/detail); final count **~174–175**
   (frozen in this contract; exact number confirmed at the build gate).
3. **CLI:** `scripts/billing-automate.php` (server-side; dry-run first; cron in rollout).
4. **Utility bills:** **flat tariff** (fixed amount per service/period) **plus optional meter
   reading** (reading × rate where a reading is recorded).
5. **Period keys in UTC**, **monthly anchor on the 1st** with **month-end clamp**; `lead_days`
   before the period start for generation; **due in 14 days**; **reminders 3 days before due +
   on due**.
6. **Sending:** **email + in-app** notification (SMS only if an SMS provider is configured).
7. **Collection is manual:** invoice/bill shows **M-Pesa/bank payment instructions** and the
   operator **confirms payment manually** (reuse the Inc-10 manual ledger).
8. **Resident viewers:** the **household primary** resident and **active adult members** can read
   (read-only) their household bills; **guard: none**.
9. Docs-only until the build gate; nothing here is a release claim.

---

## 1. Scope and scenarios

**Goal:** generate and send recurring bills automatically by date/scenario for two audiences:

1. **Platform → estate subscription invoices** (platform finance): monthly invoice per estate from
   the active subscription, generated `lead_days` before the period start, due in 14 days, with
   reminders (3 days before due and on due).
2. **Estate → resident utility bills** (estate admin): periodic bills to the estate's active
   households for services (water/electricity/service charge/security/garbage etc.) using a **flat
   tariff per service/period**, or **optional meter reading × rate**; preview → generate → send;
   residents view their own bills under **My Bills**.

**Scenarios (v1):** monthly platform subscription invoice per estate; monthly utility batch per
estate (preview → generate); flat tariff and optional meter-reading bills; idempotent re-runs and
catch-up after missed days; pause/resume schedules; draft/preview before sending; reminders per
freeze; manual payment confirmation; audit + non-blocking send failures; tenant isolation; no hard
deletes (void/pause only); PII-free audit.

**Out of scope (v1):** online payments/M-Pesa API, card charges, credit notes/proration/tax/VAT,
PDF generation (email + in-app first), SMS reminders unless a provider is configured, bank feeds,
dunning, multi-currency conversion, estate→platform settlement automation.

## 2. Schedule model (planned additive tables)

| Table | Purpose |
|---|---|
| `billing_schedules` | Recurring definition: `uuid`, `scope` (`platform_subscription`/`estate_utility`), `estate_id`, `subscription_id` nullable, `utility_service_id` nullable, **`anchor_day=1`** (monthly 1st, clamped to month-end where needed), **`lead_days`** (generate this many days before period start), **`due_days=14`**, **`reminder_offsets` JSON `[-3, 0]`**, `status` (`active`,`paused`,`archived`), `next_run_on` DATE (UTC calendar), `last_run_at` UTC, `created_by_user_id`, timestamps, `UNIQUE(scope, estate_id, subscription_id, utility_service_id)`; no hard delete. |
| `billing_runs` | Run ledger: `uuid`, `schedule_id`, `period_key`, `run_date` (UTC), `mode` (`dry_run`/`apply`), `generated_count`, `skipped_count`, `failed_count`, `started_at`/`finished_at` UTC, `status`, `error_summary` (no PII); idempotent apply key `UNIQUE(schedule_id, period_key, mode)`. |
| `billing_reminders` | Reminder ledger: `uuid`, target (`invoice`/`utility_bill` + id), `offset_days` (**-3 / 0**), `channel` (`email`/`in_app`/`sms` when configured), `status` (`sent`/`failed`/`skipped`), `sent_at` UTC, `UNIQUE(target_type, target_id, offset_days, channel)` — never send twice. |

**Run semantics:** the scheduler selects `status=active` schedules where
`next_run_on <= today_utc`; for each it computes the period key (UTC), generates target rows in
`draft` (or `open` when the schedule permits auto-send), records the run, advances `next_run_on`,
sends and books reminders when enabled. Failures are isolated per schedule, recorded and safely
retryable.

## 3. Period keys and timezone (frozen: UTC)

- **Storage:** all `*_at` timestamps are **UTC** (DATETIME(3)); `period_start`/`period_end`/
  `due_at`/`next_run_on`/`run_date` are **UTC calendar dates**.
- **Period key:** monthly → **`YYYY-MM` computed in UTC** (period_start = 1st 00:00 UTC,
  period_end = last day of month, leap-year safe); **month-end clamp** when a derived day exceeds
  the month length (the anchor is 1 so this mainly affects lead-time calculations).
- **Generation** happens **`lead_days` before `period_start`** (e.g. `lead_days=3` → generate on the
  28th/29th/30th/31st of the previous UTC month as applicable, clamped); a missed run generates the
  next correct period when it happens (one period per run, catch-up safe).
- **Display:** EAT (`Africa/Nairobi`, UTC+3 no DST) for UI dates/times only; scheduling math is UTC.
- **Due:** `due_at` = `period_start` + **14 days** (UTC date), i.e. due-in 14d.
- **Reminders:** **3 days before `due_at`** and **on `due_at`** (offsets `-3`, `0`), each at most
  once per target/channel.

## 4. Utility bills model (flat tariff + optional meter reading)

| Table | Purpose |
|---|---|
| `utility_services` | Per-estate catalogue: `uuid`, `estate_id`, `code` (`water`,`electricity`,`service_charge`,`security`,`garbage`…), `name`, `unit` (`flat`,`m3`,`kwh`,`month`), **`tariff_cents`** (flat amount and/or rate per unit), `status` (`active`,`archived`), timestamps, `UNIQUE(estate_id, code)`; archive only. |
| `utility_bills` | `uuid`, `estate_id`, `household_id`, `service_id`, `period_key` (`YYYY-MM`), **`billing_mode`** (`flat`/`meter`), optional `reading_previous`/`reading_current`/`units` (`DECIMAL(12,3)`), `rate_cents`, `amount_cents`, `currency` KES, `status` (`draft`,`open`,`paid`,`void`), `due_at` (UTC date), `sent_at` UTC nullable, `payment_instructions` (M-Pesa/bank text, no secrets), `payment_confirmed_at` nullable, `notes`, `created_by_user_id`, timestamps; `UNIQUE(household_id, service_id, period_key)`; composite tenant FKs `(household_id, estate_id)`/`(service_id, estate_id)`; no hard deletes. |
| `utility_bill_lines` *(optional, later)* | Multi-service bill lines; v1 can stay one bill per service. |

Money = integer minor units (cents); consumption decimals is `DECIMAL(12,3)`. **Collection is
manual:** the bill shows M-Pesa/bank instructions and the operator records/confirms the payment
(reuse Inc-10 `billing_payments` ledger/confirmation patterns); no online charge.

## 5. Generation, sending, reminders

- **CLI (server-side only):**
  `php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD] [--schedule=<uuid>] [--all]
  [--send]` — `--date` is UTC calendar injection for tests; `--dry-run` writes nothing and prints
  planned counts; `--schedule`/`--all` select schedules; `--send` sends the generated batch (default
  is draft/preview); exits `0` ok, `1` failed run(s), `2` usage/validation/feature-disabled.
- **Generation:** create target rows in `draft` by default; preview via CLI/UI; auto-send only when
  the schedule permits and the owner policy enables it. **`lead_days` before period start**; due-in
  14d.
- **Sending:** email via the existing Mail abstraction + **in-app** notification (SMS only when an
  SMS provider is configured); recipients from the estate billing contact / household
  primary+active-adult viewers; masked logs/audit; failures non-blocking, recorded and retried
  (bounded).
- **Reminders:** offsets `-3` (3 days before due) and `0` (on due), at most once per
  target/channel; terminal states (`paid`/`void`) skip reminders. No SMS in v1 unless configured.
- **Idempotency:** `UNIQUE(household_id, service_id, period_key)` for utility bills; platform
  invoices protected by `billing_runs (schedule_id, period_key, mode=apply)` + the existing invoice
  numbering/unique keys — a re-run creates **0 duplicates**.
- **Feature flag (planned):** `AUTOMATED_BILLING_ENABLED=false` by default; the CLI refuses to run
  (exit 2) until enabled on the server; cron only after a staging dry-run.

## 6. Permissions (frozen: no new permissions; links stay 217)

| Role / account | Scope |
|---|---|
| **Platform finance** (`platform_super_admin`, `platform_finance`, existing `billing.*`) | Manage platform subscription schedules (list/new, detail, run-now, run log); view platform invoices/runs. |
| **Estate admin** (estate-scoped, existing admin/billing family) | Manage own estate's utility schedules/services and preview/generate/send own-estate bill batches only; no platform invoice access; no hard deletes. |
| **Resident** — **household primary + active adult members** | **Read-only** own household bills under **My Bills** (list/detail/status); no create/edit. |
| **Guard** | **None** — no billing routes/surfaces. |
| **Unauthenticated** | `302 /login`. |

**No new permission slugs and no role-link changes** (links stay **217**): platform surfaces reuse
`billing.invoice.view` / `billing.payment.manage`; estate utility surfaces are estate-scoped by the
existing estate-admin area gate (exact gate mapping frozen at build, no seed changes); resident
read-only via the existing resident area + household-membership scoping. Platform area gate applies
to platform routes; tenant isolation by `Context` estate/membership.

## 7. Audit actions

All mutations audited with **PII-free** metadata (uuids/codes/amounts/statuses/counts, masked
recipient counts — never full emails/phones/notes):

- `billing.schedule_created`, `billing.schedule_updated`, `billing.schedule_paused`,
  `billing.schedule_resumed`;
- `billing.run_started`, `billing.run_completed`, `billing.run_failed` (mode/counts only);
- `billing.invoice_generated`, `billing.invoice_sent`;
- `utility.service_created`, `utility.service_archived`;
- `utility.bill_generated` (batch totals), `utility.bill_sent`, `utility.bill_voided`,
  `utility.bill_payment_confirmed` (manual collection);
- `billing.reminder_sent` / `billing.reminder_failed` (target + offset/channel only).

Reuse `billing.invoice_created` / `billing.invoice_status_changed` / `billing.payment_recorded`
from Inc-10 for the underlying rows.

## 8. API / route plan (frozen minimal; final ~174–175)

Planned **~10 new web routes** (no APIs), giving **165 → ~174–175** (exact number frozen at the
build gate):

| Method | Path | Controller/action | Gate |
|---|---|---|---|
| GET | `/platform/billing/schedules` | `PlatformBillingScheduleController::index` (list + new form) | platform area; `billing.*` |
| POST | `/platform/billing/schedules` | `…::store` | platform area; `billing.*` |
| GET | `/platform/billing/schedules/{uuid}` | `…::show` (detail/edit/pause) | platform area; `billing.*` |
| POST | `/platform/billing/schedules/{uuid}/run-now` | `…::runNow` (dry-run default; apply flagged) | platform area; `billing.*` |
| GET | `/platform/billing/runs` | `…::runs` (run log) | platform area; `billing.*` |
| GET | `/admin/utility-bills` | `AdminUtilityBillController::index` (own-estate schedules + batch list) | admin area; estate-scoped |
| POST | `/admin/utility-bills/preview` | `…::preview` (dry-run batch, no writes) | admin area; estate-scoped |
| POST | `/admin/utility-bills/generate` | `…::generate` (apply batch + optional send) | admin area; estate-scoped |
| GET | `/app/bills` | `ResidentBillingController::index` (own household bills) | auth+estate; resident/household scoping |
| GET | `/app/bills/{uuid}` | `ResidentBillingController::show` (own bill detail/status) | same |

Expected totals: **165 → ~174–175 routes** (web 121 → ~131–132, API stays 44).

## 9. Migration plan (11 / 12)

- **Migration 11** (schedules/automation): `billing_schedules`, `billing_runs`, `billing_reminders`
  — additive only. Expected **9 → 10 migrations**, tables **39 → ~42**.
- **Migration 12** (utility bills): `utility_services`, `utility_bills` (+ optional lines) —
  additive only. Expected **10 → 11 migrations**, tables **~42 → ~44**.
- **No permission/role-link changes** (52 permissions / **217 links stay**); no destructive ALTERs;
  Inc-10 invoice/payment tables untouched. Both forward-only; clean-room install + integrity checker
  updated in the same increments.

## 10. Acceptance criteria (v1)

1. **Schedules** — platform + estate utility schedules create/edit/pause/run-now; anchor **1st**,
   `lead_days`, due-in **14d**, reminders **-3/0**; validation; paused schedules do not run.
2. **Period/timezone** — **UTC** period keys (`YYYY-MM`), 1st anchor + month-end clamp,
   `lead_days` before period start; deterministic `--date`; no duplicates on re-run (0 new).
3. **Generation** — platform invoice per estate/period; utility bill per active household/service/
   period; **flat tariff** and **optional meter reading × rate**; correct amounts/rounding;
   preview changes nothing; catch-up correct.
4. **Sending/reminders** — email + in-app (SMS only if configured), masked recipients; reminders
   3-days-before + on-due at most once; terminal bills skip; failure non-blocking + retry.
5. **Collection** — manual: M-Pesa/bank instructions on the bill + operator confirmation recorded
   in the ledger; no online charge; paid/void terminal behaviour.
6. **Permissions/security** — no new permissions/links (217); platform finance vs estate admin vs
   **resident primary + active adults read-only** vs **guard none**; CSRF/XSS; tenant isolation; no
   hard deletes; PII-free audit; CLI-only automation; feature flag off by default.
7. **Ops/tests** — cron + dry-run documented; exit codes; run ledger; clean-room + integrity
   green; unit (UTC period math, lead/due/reminder offsets, pricing), integration (generation/
   reminders/permissions/tenant), E2E (schedule → generate → send → My Bills → reminder →
   manual payment confirm) + regressions.
8. **Docs** — contract finalised, deploy/cron section, changelog/acceptance, idempotent demo data.

## 11. Demo data (seeder, idempotent)

Greenview only: utility services **Water** (flat tariff + optional meter rate), **Electricity**
(flat or meter), **Service charge** (flat monthly); one active **monthly** utility schedule
(anchor 1st, `lead_days=3`, due +14, reminders **-3/0**); one **draft** and one **open** demo
utility bill for a Greenview household; one active **platform subscription schedule** (Growth plan,
anchor 1st, lead 3d, due +14). Riverside: no utility services/bills (honest empty state).
Deterministic UUIDs/natural keys so `php scripts/install.php` re-runs are no-ops.

## 12. Rollout (cron + dry-run first)

1. Implement behind `AUTOMATED_BILLING_ENABLED=false`; tests + clean-room; docs.
2. Staging: `php scripts/billing-automate.php --dry-run --date=<UTC date> --all` → review counts (no
   writes); then apply on a copy DB, review drafts + `MAIL_DRIVER=log`.
3. Owner sign-off on tariffs/templates; enable flag; add **cPanel cron** (e.g. daily 06:00 EAT:
   `php /home/<user>/mtaapass/scripts/billing-automate.php --all`) — start **draft-only**, owner
   sends batches manually; auto-send only after a clean cycle.
4. Monitor run ledger/audit/mail log; pause schedules or disable the flag on incidents; rollback =
   disable flag + pause schedules (rows retained).
5. Live smoke: CLI **dry-run on live before enabling cron**; `/health` unchanged; second dry-run
   0 new; resident My Bills + estate preview + platform run log verified.

## 13. Compatibility / dependencies

Builds on Inc-10 billing tables (`billing_invoices`, `billing_payments`) + subscriptions/estates/
plans; no Inc-10 table changes. Email uses the existing Mail abstraction + verified sender; SMS is a
later adapter. Reuses CLI/migrator/seed/audit patterns and the release pipeline (bundle/secret
screen/cron docs). No public API surface change.

## 14. Remaining owner questions

1. Plan cadence beyond monthly (quarterly/annual) — v1 frozen monthly only; confirm.
2. Auto-send vs draft-only first cycle — frozen **draft-first**; confirm when to switch to auto-send.
3. Meter readings source for v1 — admin web entry (frozen) or CSV import; resident submission later?
4. Late-fee handling / past-due utility status — v1 has no late fees; confirm.
5. Email templates/sender branding (reuse existing sender or billing-specific?).
6. In-app notification surface (My Bills badge vs account notices card) — design at build.
7. SMS provider activation for reminders (off unless configured).
8. Platform subscription invoice recipient (estate billing contact source) — confirm field.
9. Proration for mid-month schedule/service creation — deferred to v2; confirm.
10. Which operator confirms manual utility payments — estate admin (frozen candidate) or platform.

---

*DRAFT skeleton — design frozen 2026-11-11; **no implementation, routes, migrations or scheduler
exist**. Inc-13 is planned/next, not released. Update at the Lead's build gate.*
