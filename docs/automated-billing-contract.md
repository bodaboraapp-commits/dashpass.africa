# Automated billing contract — schedules, subscription invoices, utility bills *(DRAFT — NOT IMPLEMENTED)*

**Status: DRAFT / PLANNED — no code, routes, migrations or scheduler exist for this feature yet.**
Do **not** enable or claim it as released. This skeleton captures the agreed shape for the next
billing increment(s) so implementation can start after the Inc-10 release and the owner's
**“build go”**.
**Base state:** Inc-10 (165 routes / 9 migrations / 39 tables / 52 permissions / 217 role links;
billing invoices/payments manual ledger live). **Date:** 2026-11-11 · **Owner decision needed:**
open questions in §14.

---

## 1. Scope and scenarios

**Goal:** generate and send recurring bills automatically by date/scenario, with two distinct
audiences:

1. **Platform → estate subscription invoices** (platform finance): monthly invoice per estate (and
   optional per-subscription/plan) created from the estate's active subscription, sent to the
   estate contact/billing email, with reminders until paid/void.
2. **Estate → resident utility bills** (estate admin): periodic (e.g. monthly) bills to active
   households for estate utilities/services (water, electricity, service charge, security, garbage),
   either a fixed amount per service/period or a consumption × rate calculation from recorded
   readings; sent to residents (email + in-app “My Bills”), with reminders.

**Scenarios in scope (v1):**
- Monthly platform subscription invoice per estate (configurable day-of-month + due days).
- Monthly utility bill batch per estate for active households (manual “generate now” + scheduled).
- Fixed-amount utility bills, and consumption-based bills (reading × rate) when readings exist.
- **Dry-run first** (`--dry-run`) for every generator; draft batch review before sending.
- Idempotent re-runs (one bill per schedule/household/service/period), catch-up after missed days.
- Pause/resume a schedule; reminders before due, on due, and after past-due (bounded count).
- Audit + failure handling (non-blocking sends, retry, no partial duplicates).
- Tenant isolation, no hard deletes (void/cancel only), PII-free audit.

**Out of scope (deferred / explicitly not v1):** online payments or M-Pesa API reconciliation,
card charging, credit notes/proration/tax/VAT engines, PDF generation (email/in-app only at first),
SMS reminders (email + PWA first; SMS adapter later), bank feeds, dunning legal notices, multi-
currency conversion (KES stored as given), estate→platform settlement.

## 2. Schedule model

Proposed additive tables (names indicative; final at implementation):

| Table | Purpose |
|---|---|
| `billing_schedules` | Recurring generation definition: `uuid`, `scope` (`platform_subscription` / `estate_utility`), `estate_id` (nullable for platform-wide? — v1 requires estate), `subscription_id` nullable, `utility_service_id` nullable, `cadence` (`monthly`; v1 only), `day_of_month` 1–28 (clamped), `due_after_days` 0–60, `reminder_offsets` JSON (`[-3,0,7]` days), `status` (`active`,`paused`,`archived`), `next_run_on` DATE (EAT), `last_run_at` UTC, `created_by_user_id`, timestamps, `UNIQUE(scope, estate_id, subscription_id, utility_service_id)`; no hard delete (status only). |
| `billing_runs` | Generation/run ledger: `uuid`, `schedule_id`, `period_key`, `run_date` (EAT), `mode` (`dry_run`/`apply`), `generated_count`, `skipped_count`, `failed_count`, `started_at`/`finished_at` UTC, `status` (`started`,`completed`,`failed`), `error_summary` (no PII). **Idempotency key** `UNIQUE(schedule_id, period_key, mode)` (apply runs only). |
| `billing_reminders` | Reminder ledger: `uuid`, `invoice_id`/`utility_bill_id`, `offset_days`, `channel` (`email`), `status` (`sent`,`failed`,`skipped`), `sent_at` UTC, `UNIQUE(target_type, target_id, offset_days)` so a reminder is never sent twice. |

**Timezone:** schedule/period decisions are computed in **Africa/Nairobi (EAT, UTC+3, no DST)**;
all timestamps are **stored in UTC**. See §3.

**Run semantics:** the scheduler (cron/CLI) selects schedules whose `next_run_on <= today_eat`, then
for each computes the `period_key`, generates the target bills in `draft` (or `open` under an
owner-approved “auto-send” mode), records `billing_runs`, advances `next_run_on`, and (when enabled)
sends + schedules reminders. Failures are isolated per schedule; a failed run does not block others,
is recorded, and is safely retryable (idempotent).

## 3. Period keys and timezone

- **Storage:** every `*_at` timestamp is UTC (DATETIME(3) as elsewhere). DATE fields
  (`period_start`, `period_end`, `due_at`, `next_run_on`, `run_date`) are **local EAT calendar
  dates**, not UTC instants.
- **Display/scheduling:** EAT = `Africa/Nairobi` (UTC+03:00, **no DST**). The scheduler converts
  “today” once per run using EAT; a run at EAT 00:00–02:00 belongs to that **local** date even
  though it is the previous UTC day.
- **Period keys:**
  - Monthly → `YYYY-MM` (e.g. `2026-11`), computed in EAT; `period_start` = 1st, `period_end` =
    last day of month in EAT (leap-year safe).
  - Optional quarterly/annual later → `YYYY-Qn` / `YYYY`; not v1.
- **Due date:** `due_at` = period_start (or send date) + `due_after_days`, at 23:59 EAT stored as a
  DATE; or an explicit DATE if the owner prefers due-day-of-month.
- **Month-end:** `day_of_month` capped at 28 in v1 so every month has the day; if a future cadence
  needs 29–31, clamp to the month's last day (documented + tested).
- **DST safety:** Africa/Nairobi has no DST, so no ambiguity; rules still implemented via
  `DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi'))`, never string arithmetic.

## 4. Utility bills model

Proposed additive tables (v1):

| Table | Purpose |
|---|---|
| `utility_services` | Per-estate catalogue: `uuid`, `estate_id`, `code` (`water`,`electricity`,`service_charge`,`security`,`garbage`,…), `name`, `unit` (`m3`,`kwh`,`month`,`flat`), `default_rate_cents`, `status` (`active`,`archived`), timestamps, `UNIQUE(estate_id, code)`; archive (no hard delete). |
| `utility_bills` | `uuid`, `estate_id`, `household_id`, `service_id`, `period_key` (`YYYY-MM`), optional `reading_previous`/`reading_current`/`units` (decimal strings for exactness), `rate_cents`, `amount_cents`, `currency` KES, `status` (`draft`,`open`,`paid`,`void`; maybe `past_due`), `due_at` DATE, `sent_at` UTC nullable, `notes`, `created_by_user_id`, timestamps, `UNIQUE(household_id, service_id, period_key)`, composite tenant FKs `(household_id, estate_id)`, `(service_id, estate_id)`; no hard deletes (void). |
| `utility_bill_lines` *(optional)* | If a bill bundles multiple services: `bill_id`, `service_id`, `description`, `units`, `rate_cents`, `amount_cents`; v1 can keep one row per service without this table. |

Money remains integer minor units (cents); decimals for consumption stored as `DECIMAL(12,3)`.

**Resident visibility:** resident sees only own household's bills (list + detail + status), no
platform subscription invoices; guard none; estate admin sees own estate's bills/services only.
Platform finance sees platform subscription invoices and may (read-only or support) inspect
estate utility billing per contract/owner decision.

## 5. Generation, sending, reminders

- **CLI (server-side only):**
  `php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD] [--schedule=<uuid>] [--all]
  [--send]` — `--date` is for tests/time control (EAT), `--dry-run` writes nothing, `--schedule`
  targets one, `--all` all due schedules; exit codes `0` success, `1` failed run(s), `2` usage/
  validation error. No web trigger, no scheduler daemon in v1 (cPanel cron calls the CLI).
- **Generation:** create the target rows in `draft` by default; when the schedule/owner policy permits
  auto-send, create and send in one run (flagged on the schedule). **Draft review** flow lets the
  owner/estate admin inspect the batch before sending (`--send` or UI “Send batch” action).
- **Sending:** email via the existing `Mail` abstraction (log driver in dev, SMTP/Resend in
  production); recipients come from estate billing contact / resident email; masked in logs/audit;
  failures non-blocking, recorded on the bill/reminder (`failed`) and retried next run (bounded).
- **Reminders:** offsets from `reminder_offsets` (default `[-3, 0, 7]` days relative to due date;
  e.g. 3 days before, on due, 7 days past). Each reminder is attempted at most once per
  (target, offset) via the `billing_reminders` unique key; terminal states (void/paid) skip further
  reminders. No SMS in v1.
- **Idempotency:** `UNIQUE(household_id, service_id, period_key)` for utility bills; for platform
  invoices use the existing invoice numbering + a `billing_runs` guard (`schedule_id, period_key`)
  plus a deterministic `(estate_id, invoice_number)`/period check so a re-run never duplicates.
- **Catch-up:** a missed day still generates the correct period when the run happens later
  (period derived from the run's EAT date, not from “expected” date); only one period per run.
- **Feature flag (planned):** `AUTOMATED_BILLING_ENABLED=false` by default; the scheduler refuses to
  run (exit 2, logged) until enabled on the server, and cron is added only after a successful
  staging dry-run.

## 6. Permissions

| Role / account | Scope |
|---|---|
| **Platform finance** (`platform_super_admin`, `platform_finance`, `billing.*`) | Manage platform subscription schedules (create/edit/pause/run dry-run), see platform invoices + run ledger; per owner decision may view estate utility billing read-only for support. |
| **Estate admin** (`estate_admin`, estate-scoped) | Manage own estate's utility services, schedules and bill batches (create/edit/pause/generate/send own estate only); **cannot** see other estates or platform subscription invoices; no hard deletes. |
| **Resident** | Read-only own household utility bills (list/detail/status) in the PWA; no create/edit; no platform invoices. |
| **Guard** | **None** — no billing surfaces or routes. |
| **Unauthenticated** | `302 /login` for web routes. |

New permissions (planned, additive): `utility.service.manage`, `utility.bill.manage`,
`utility.bill.view_all` (estate admin), `utility.bill.view_own` (resident), `billing.schedule.manage`
(platform finance). Estate area gate + tenant scoping by `Context` estate/membership; platform area
gate for platform routes. Existing platform `billing.invoice.view`/`billing.payment.manage` remain.

## 7. Audit actions

All mutations audited with **PII-free** metadata (uuids, codes, amounts, statuses, counts; masked
recipient counts — never full emails/phones/notes):

- `billing.schedule_created`, `billing.schedule_updated`, `billing.schedule_paused`,
  `billing.schedule_resumed`;
- `billing.run_started`, `billing.run_completed`, `billing.run_failed` (counts/mode, no PII);
- `billing.invoice_generated`, `billing.invoice_sent`;
- `utility.service_created`, `utility.service_archived`;
- `utility.bill_generated` (batch totals), `utility.bill_sent`, `utility.bill_voided`;
- `billing.reminder_sent` / `billing.reminder_failed` (target type/id + offset, no content).

Reuse `billing.invoice_created` / `billing.invoice_status_changed` from Inc-10 where the generated
rows are normal invoices.

## 8. API / route plan (expected 165 → ~172)

Planned **7 new web routes** (no APIs in v1), subject to the implementation gate:

| Method | Path | Controller/action | Gate |
|---|---|---|---|
| GET | `/platform/billing/schedules` | `PlatformBillingScheduleController::index` | platform area + `billing.schedule.manage` |
| POST | `/platform/billing/schedules` | `…::store` | same |
| POST | `/platform/billing/schedules/{uuid}` | `…::update` (edit/pause/resume) | same |
| POST | `/platform/billing/schedules/{uuid}/run` | `…::run` (dry-run by default; apply flagged) | same |
| GET | `/admin/utility-bills` | `AdminUtilityBillController::index` (estate-scoped list + batch form) | admin area + `utility.bill.manage` |
| POST | `/admin/utility-bills/generate` | `…::generate` (dry-run preview → apply) | same |
| GET | `/app/bills` | `ResidentBillingController::index` (own household bills) | auth+estate + `utility.bill.view_own` |

Expected totals: **165 → ~172 routes** (web 121 → ~128, API stays 44). Existing
`/platform/billing*` pages gain schedule entry points; no new JSON API is planned.

## 9. Migration plan (11 / 12)

- **Migration 11** (platform automation): `billing_schedules`, `billing_runs`, `billing_reminders`
  (+ needed indexes/unique keys) — additive only, no changes to existing billing tables. Expected
  **9 → 10 migrations**, tables **39 → ~42**.
- **Migration 12** (utility bills): `utility_services`, `utility_bills` (+ optional
  `utility_bill_lines`) — additive only. Expected **10 → 11 migrations**, tables **~42 → ~44**.
- Both forward-only (no downgrade). New permissions/role links added via seeders idempotently
  (expected **217 → ~223 links**, **52 → ~57 permissions** at v1). No destructive ALTERs; existing
  invoices/payments untouched. Clean-room install + integrity checker must be updated in the same
  increments.

## 10. Acceptance criteria (v1)

1. Schedules: create/edit/pause/resume platform + estate utility schedules; validation (day 1–28,
   due days, offsets, scoped ids); tenant isolation; paused schedules do not run.
2. Generation: correct **EAT period key** and period_start/end; one invoice/bill per
   schedule/household/service/period; **re-run idempotent (0 new)**; catch-up generates the correct
   period; `--dry-run` changes nothing; unique constraints enforce no duplicates.
3. Utility bills: only **active households** of the estate; fixed and consumption×rate modes;
   amounts/rounding correct; resident sees own bills only; estate admin scoped; guard/none.
4. Sending + reminders: email attempts recorded (log driver in dev), masked recipients; reminder
   offsets before/on/past-due sent at most once; terminal bills skip reminders; send failure
   non-blocking + retry path.
5. Security: permissions matrix enforced (platform finance vs estate admin vs resident vs guard),
   CSRF on all POSTs, XSS escaping, no hard deletes (void/archive/pause), PII-free audit,
   no enumeration, CLI-only for automation, feature flag default off.
6. Ops: cron command + dry-run documented; `--date` injection deterministic; exit codes; audit
   run ledger; second run no-op; clean-room + integrity checker green.
7. Tests: unit (period/timezone math, rounding, offset math), integration (generation/reminders/
   permissions/tenant), security (CSRF/XSS/mass-assignment), E2E (schedule → generate → send →
   resident view → reminder), plus regressions green.
8. Docs: this contract finalised, deploy runbook/cron section, changelog/acceptance, demo data.

## 11. Demo data (seeder, idempotent)

Greenview only: utility services **Water** (m³ rate), **Electricity** (kWh rate),
**Service charge** (flat monthly); one active **monthly** utility schedule for the 1st with due +14
days and reminders `[-3,0,7]`; one **draft** and one **open** demo utility bill for a Greenview
household; one active **platform subscription schedule** for the Growth plan (invoice on the 1st,
due +14). Riverside: no utility services/bills (honest empty state). Deterministic UUIDs + natural
keys so `php scripts/install.php` re-runs are no-ops; purge/demo flag documented for pilots.

## 12. Rollout (cron + dry-run first)

1. Implement behind `AUTOMATED_BILLING_ENABLED=false`; run tests + clean-room; docs.
2. Staging: `php scripts/billing-automate.php --dry-run --date=<EAT date> --all` → review counts,
   no writes; then apply on a copy DB, review generated drafts + email log (`MAIL_DRIVER=log`).
3. Owner sign-off on templates/amounts; enable flag; add **cPanel cron** (e.g. daily 06:00 EAT:
   `php /home/<user>/mtaapass/scripts/billing-automate.php --all`) — start with **draft-only** mode,
   owner sends batches manually; switch to auto-send only after a clean cycle.
4. Monitor run ledger + audit + mail log; pause schedules / disable flag on incidents; no hard
   deletes; rollback = disable flag + pause schedules (rows retained).
5. Live smoke: run the CLI **dry-run on live** before enabling cron; verify `/health` unchanged,
   no duplicate bills on a second dry-run, and the resident/admin/platform surfaces.

## 13. Compatibility / dependencies

- Builds on Inc-10 billing tables (`billing_invoices`, `billing_payments`) and the existing
  subscription/estate/plan models; no changes to Inc-10 tables.
- Email uses the existing Mail abstraction + verified sender; SMS reminders are a later adapter.
- Reuses the CLI/migrator/seed/audit patterns and the release pipeline (bundle/secret screen/cron
  docs). No change to the public API surface.

## 14. Open questions for the owner (to resolve before implementation)

1. Platform subscription cadence: monthly only, or monthly/quarterly/annual in v1?
2. Who approves/sends generated batches — platform operator only, or estate admin self-serve
   (with platform oversight)?
3. Utility bill model: fixed amount per service vs consumption × rate (readings entered where — admin
   web form, CSV import, or resident submission)?
4. Due-day policy: fixed day-of-month vs `send date + N days`; late fee/past-due handling?
5. Reminder channels/offsets: email only, which offsets, maximum count?
6. PDF/attachment requirement in v1, or in-app + email text first?
7. Resident visibility of historical bills + download/export expectations.
8. Credit/proration rules for mid-month schedule creation or plan changes (defer?).
9. Notification inbox/recipient for platform subscription invoices (which estate contact/email?).
10. Rollout: drafts-only first cycle? cron time? who is paged on failed runs?

---

*DRAFT skeleton — no implementation, routes, migrations or scheduler exist for this feature. Inc-13
is **planned/next**, not released. Update this contract at the Lead's build gate.*
