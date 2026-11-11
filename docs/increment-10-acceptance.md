# Increment 10 — Acceptance record (billing / invoices)

**Date:** 2026-11-11 · **Status: ACCEPTED — DELIVERED** (final Inc-10 suite **757/757 PASS, 0 FAIL,
1576.72s, exit 0**; DB gate 9 migrations / 39 tables / 52 permissions / 13 roles / 217 links / 72 FKs /
2 invoices + 1 payment; routes **165**; T111 UI **49/49**; qa-e2e **70/72 (0 FAIL, 2 SKIP)**;
t77 **55/55**, t98 **24/24**) — **release bundle pin `__PIN__`** (sha256 `__BUNDLE_SHA__`,
`__BUNDLE_ENTRIES__` entries / `__BUNDLE_SIZE__` B)
**Contract:** [`billing-invoices-contract.md`](billing-invoices-contract.md) (binding)
· **Base:** Inc-9 closed at 158 routes / 8 migrations / 37 tables / 217 links
**Product name:** config-driven (dev MtaaPass, prod DashPASS)

> **Released as the Inc-10 gated bundle.** The final Inc-10 full suite is **GREEN: 757/757 PASS,
> 0 FAIL, 1576.72s, exit 0** (`tests/_inc10-final.log`, `tests/_inc10-final-exit.txt` =
> `FULL-EXIT=0`). Bundle frozen at source pin **`__PIN__`** (**sha256 `__BUNDLE_SHA__`**): this is
> the **release artifact + rollback record**. Live already received the Inc-10 code (repo pull) and
> **migration 10 is applied on live** (Lead `deploy-sync install`: `/health` **applied=9 /
> pending=0**, DB `tokeqvlx_dashpass_db`; `/platform/billing` → **302 `/login`** unauth; expected seed
> **2 invoices + 1 payment** — confirm with the integrity check); the bundle is for fresh deploys,
> re-deploys and rollback. Live Firebase is separately **ACTIVATED** (`activationDetected=true`).

## Delivered scope

- **DB — migration 10** (`database/migrations/20261111_000010_create_billing_tables.sql`,
  additive only): `billing_invoices` (draft/open/paid/void/past_due, invoice_number, amounts in
  cents, currency, period, due_at, account/subscription links) and `billing_payments` (append-only
  ledger: pending/confirmed/failed/refunded, method mpesa/bank/card/cash/other, reference, amount,
  metadata) plus the non-destructive `subscriptions (id, estate_id)` UNIQUE index required by the
  invoice composite FK. Order is fixed (ALTER → invoices → payments); no other table/seed row is
  touched; no hard deletes anywhere.
- **Routes 158 → 165** (web **121** + API 44): 7 platform-only billing routes —
  `/platform/billing`, `/platform/billing/invoices/new` (GET form), `POST /platform/billing/invoices`,
  `/platform/billing/invoices/{uuid}` (detail), `POST /platform/billing/invoices/{uuid}/status`,
  `POST /platform/billing/invoices/{uuid}/payments`, `POST /platform/billing/payments/{uuid}/status`.
  Routes are `['auth']` only + **controller platform-area gate** and exact
  `billing.invoice.view` / `billing.payment.manage` permissions; **no new APIs**. Estate users get
  **403**, unauthenticated **302 `/login`**.
- **Billing application layer + UI:** `app/Modules/Billing/{BillingService,BillingRepository,
  BillingPresenter,BillingPastDueService,PlatformBillingController}.php`; 3 platform views
  (`billing`, `billing-invoice`, `billing-invoice-form`); Billing nav + dashboard quick-link gated
  to platform `billing.*` / super-admin / platform-finance capability.
- **Past-due sweep:** `php scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]` moves overdue
  `open` invoices to `past_due`, audits `billing.invoice_status_changed`, is idempotent and runs
  server-side only (cPanel Terminal/cron; no scheduler daemon). Empty `--estate=` exits **2**
  (usage error).
- **Demo seed (`DemoBillingSeeder`, Greenview only):** **2 invoices + 1 payment** — paid
  `INV-202610-0001` (paid_at set) with one confirmed M-Pesa ledger payment `REF DEMO-MPESA-001`,
  and the current-month open invoice `INV-…-0001`; deterministic UUIDs + multi-key matching, so a
  second install is a no-op. Riverside gets none.
- **Hardening folded pre-gate:** invoice-number allocation reads the per-estate latest number with
  a row lock (`SELECT … FOR UPDATE`) inside the create transaction and retries **only** on
  duplicate-key 1062 (`UNIQUE(estate_id, invoice_number)`, max 5); all other failures surface.
  Invoice create accepts a `save_draft` boolean (draft vs open). All mutations audited
  (`billing.invoice_created`, `billing.invoice_status_changed`, `billing.payment_recorded`,
  `billing.payment_status_changed`) with PII-free metadata; CSRF + validation on every POST.

## Gate evidence

| Check | Result |
|---|---|
| **DB gate (Lead)** | **9 migrations / 39 tables / 52 permissions / 13 roles / 217 role links / 72 FK constraints / 2 invoices + 1 payment**; dev `/health` **applied=9, pending=0** (verified on the running dev instance); idempotent install expected (gate report) |
| Integrity checker (read-only) | **26/26 checks PASS** (`php -d display_startup_errors=0 scripts/db-integrity-check.php`; post-Inc-10 branch 9/39/52/13/217/72 FK/5 settings + billing rows) — Lead gate report |
| Cleanroom rebuild | **28/28 assertions PASS** on a fresh `mtaapass_test`-style rebuild (9 migrations applied, billing tables/seed present, no drift) — Lead gate report |
| **Route gate** | **165 routes** (web 121 + API 44), all **7 billing routes registered exactly** with `PlatformBillingController@action` + `['auth']`; no new APIs; gate readiness `ready=true` (`storage/temporary/inc10-gate-readiness.json`) |
| Platform gate / access matrix | Daniel (`platform_super_admin`) `/platform/billing` → **200** with money stats + New-invoice CTA; Grace (estate admin) → **403**; unauthenticated → **302 `/login`**; Billing nav + dashboard quick-link visible post-flip; seeded paid/open invoices + `DEMO-MPESA-001` ledger visible |
| Backend proof (T77) | **55/55 PASS** — route count/gates, invoice numbering `INV-YYYYMM-####` + sequence, status machine (draft→open, void terminal 409, invalid transition 422, paid requires covering payment 422), partial/overpayment, refund re-opens paid invoice, payment pending/confirmed/failed/refunded + terminal 409, past-due CLI dry-run→apply→idempotent, foreign invoice 404, frozen keys, no hard deletes, PII-free audit (`storage/temporary/t77_out.txt`) |
| Numeric-bounds proof (T98) | **24/24 PASS** — validator length vs numeric semantics, plan numeric bounds, invoice/payment `amount_cents` 0/negative → 422 (no row/500), max accepted (`storage/temporary/t98_out.txt`) |
| Billing UI / live smoke (T111) | **49/49 PASS** — Billing nav + dashboard quick-link visible for the operator; `/platform/billing` list (money stats + filters + seeded rows), `invoices/new` form → create → detail with payment ledger, record pending payment → confirm → **auto-paid**, partial payment keeps `open`, `void`/terminal → **409**, 403/302/404 matrix (estate users blocked, unauth redirect, foreign uuid 404); render/alignment harnesses **127 / 95 / 46 / 25 / 76** all pass; 2 flag flips (`layouts/platform.php`, `platform/dashboard.php`) |
| Billing tests (T112) | **25 tests** in 3 files (`BillingInvoiceTest`, `BillingPaymentTest`, `BillingPastDueJobTest`) — latest `--filter=Billing` iteration **25/25 PASS** (after test-side fixes; one `SeederTest` helper fix was pending in that run); fold into the final full suite via T112 |
| Independent E2E (qa-e2e) | **70/72 PASS, 0 FAIL, 2 SKIP** (`inc10-e2e-report.md`) — matrix: gate preconditions 1, unauth routes 3/3, sessions+billing gate **12/12**, dashboard+filters 6/6, invoice create+numbering **11/11**, status machine **5/5**, payments **14/14**, past-due CLI **9/9**, security/audit/tenant **9 PASS + 1 SKIP**, regression hand-off 1 SKIP; regressions on the same instance: Inc-5 **71/71**, Inc-6 **95/96 (0 FAIL)**, Inc-7 **78/80 (0 FAIL)**, Inc-8 **101/102 (0 FAIL)**, Inc-9 **67/68 (0 FAIL)** |
| Full test suite (final) | **757/757 PASS, 0 FAIL, 1576.72s, exit 0** (`tests/_inc10-final.log`; marker `tests/_inc10-final-exit.txt` = `FULL-EXIT=0`) — Firebase + Inc-10 baseline, superseding 710/710. Acceptance flips to **ACCEPTED**. |
| Release bundle (T113) | `storage/temporary/mtaapass-deploy.zip` — **`__BUNDLE_ENTRIES__` entries / `__BUNDLE_SIZE__` B / sha256 `__BUNDLE_SHA__`**, frozen at source pin **`__PIN__`**; secret screen **CLEAN**; excludes `.env*` (only `.env.example` placeholders), `storage/**` runtime except `.gitkeep`, `.git/`, `.tools/`, logs/scratch/zips, and Inc-12/Inc-13 staging (`storage/temporary/**`) |

### Observations (non-blocking — tracked as T147)

1. **Platform audit viewer excludes billing actions.** `PlatformRepository::recentPlatformAudit`
   filters `action LIKE 'platform.%'`, so the `/platform` audit table does not surface the
   `billing.*` rows even though they are recorded platform-scope (`estate_id NULL`, verified in the
   E2E by read-only SQL). Auditing itself meets the contract; surfacing finance rows in the viewer
   is a UX/consistency follow-up (include `billing.%` in the query or document the scope).
2. **Month filter semantics.** The billing month filter uses the invoice **creation** month
   (`DATE_FORMAT(created_at,'%Y-%m')`), not `period_start`/invoice-number month; the paid seed
   invoice `INV-202610-0001` is created today, so it appears under the current month. Contract
   wording is ambiguous — document the chosen semantics or switch to period/invoice-month if
   intended.

Both are recorded as **SKIP/observation, not failures** in the E2E (2 SKIP); T147 tracks them.

### Known limitations / deferred (documented)

- **Estate billing read surfaces deferred to Increment 11** — invoices/payments are visible only in
  the platform portal; residents/estate admins have no billing screen yet (no estate-facing list or
  download).
- **No online payments** — payments are a manual ledger (bank/M-Pesa reference recorded by the
  operator); there is no M-Pesa API/callback, card charge, receipt or reconciliation automation.
- **Past-due is a CLI/cron job** — `scripts/billing-past-due.php` must be scheduled (or run
  manually); there is no web trigger/daemon.
- **No hard deletes** — invoices are `void`; payments go `failed`/`refunded`; corrections are status
  changes with audit.
- **No proration/tax/credit notes**, no invoice email/PDF delivery, no multi-currency conversion
  (currency stored as given, KES default).
- **Access scope** — Billing is visible to platform `billing.*` / super-admin / platform-finance
  only; estate roles are intentionally blocked (403) and there is no billing API.
- Reports/exports and subscription self-service are Inc-11 scope.

## Deploy requirement

`POST /deploy-sync.php?action=install` with `X-Deploy-Token` is **required** for any server that
does not yet have migration 10 — after the pull, expect `/health` → **applied=9 / pending=0**,
**39 tables / 217 role links / 52 permissions**, demo seed **2 invoices + 1 payment**, routes
**165**. **Current live (2026-11-11, Lead-applied):** code pulled + migration 10 applied — `/health`
already shows **applied=9/pending=0** (DB `tokeqvlx_dashpass_db`, seed true; `/platform/billing`
302 unauth); the **bundle is the release artifact/rollback record**, not a prerequisite for this
live (redeploys/rollbacks use the bundle). Then smoke as Daniel `+254700000020`:
`/platform/billing` 200 (stats/table), `/platform/billing/invoices/new` 200, create invoice →
detail, `void` terminal 409, record payment → auto-paid/partial, payment refund/failed,
Grace/Jane/guard **403**, unauth **302**; on the server `php scripts/billing-past-due.php --dry-run`
then apply (idempotent second run = 0 new). Owner packet
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) and [`deployment-cpanel.md`](deployment-cpanel.md)
§3d carry the runbook. Rollback/DB policy: [`deployment-rollback.md`](deployment-rollback.md).

## Next

- **Inc-11** — estate billing read surfaces + reports/exports (see
  [`increment-11-brief.md`](increment-11-brief.md)); no core migration expected.
- Future automated billing/utility schedules remain **DRAFT** (`automated-billing-contract.md`,
  Inc-13) — no release claim.
- Keep rollback artifact: bundle `__BUNDLE_SHA__` @ **`__PIN__`**; live applied=9, Firebase
  activated.

## Evidence artifacts (local/gitignored unless noted)

`storage/temporary/inc10-gate-readiness.json` · `t77_out.txt` (55/55) · `t98_out.txt` (24/24) ·
`inc10-e2e-result.json` (70/72) · `inc10-e2e-report.md` · `inc10-e2e-run.log` · `inc10-reg-inc{5,6,7,8,9}-e2e.log` ·
`tests/_inc10-final.log` + `tests/_inc10-final-exit.txt` (757/757 PASS, exit 0) ·
`mtaapass-deploy.zip` (sha256 `__BUNDLE_SHA__`, `__BUNDLE_ENTRIES__` entries) ·
`qa_billing_confirm{1,2,3}.txt` (T112 iterations) · `inc10-parking-regression-matrix.md` ·
`firebase-live-probe.json` (activation) · `storage/temporary/gates/GATES.md` · this doc +
`docs/changelog.md`.
