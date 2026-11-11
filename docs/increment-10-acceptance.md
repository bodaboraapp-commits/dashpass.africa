# Increment 10 — Acceptance record (billing / invoices)

**Date:** 2026-11-11 · **Status: GATED — PENDING FINAL SUITE** (DB + route gates passed; UI smoke
and billing-test reports pending; final full suite to run before this flips to ACCEPTED/delivered)
**Contract:** [`billing-invoices-contract.md`](billing-invoices-contract.md) (binding)
· **Base:** Inc-9 closed at 158 routes / 8 migrations / 37 tables / 217 links
**Product name:** config-driven (dev MtaaPass, prod DashPASS)

> Gate status: **DB gate passed** and **routes are 165**. The release bundle has **not** been
> rebuilt yet (T113 waits for the Lead's “bundle go” after the final suite). Nothing here claims a
> live release; see the deploy requirement below.

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
| Billing UI / live smoke (T111) | **PENDING pwa-shell report** — preliminary gate readiness confirms the flipped nav/quick-link, `/platform/billing` 200, seeded data, Grace 403, unauth 302 |
| Billing tests (T112) | **25 tests** in 3 parked files (`BillingInvoiceTest`, `BillingPaymentTest`, `BillingPastDueJobTest`) — fold + `--filter=Billing` verification pending, then included in the final full suite |
| Independent E2E (qa-e2e) | **PENDING report** — `storage/temporary/inc10-e2e.js` (~85 checks) is readiness-gated (165/9/39/2/1) and has not yet produced a result file |
| Security / tenant checks | Platform-area gate + exact billing permissions (estate users 403), composite tenant FKs `(invoice_id, estate_id)` / `(subscription_id, estate_id)`, CSRF on all POSTs, XSS-escaped fields, no hard deletes (void/failed/refunded only), audit metadata PII-free, no new APIs |
| Full test suite | **PENDING final suite** (pre-Inc-10 baseline = **710/710 PASS**); this record flips to ACCEPTED only after it is green |

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

`POST /deploy-sync.php?action=install` with `X-Deploy-Token` is **required** (migration 10) — after
the pull, expect `/health` → **applied=9 / pending=0**, **39 tables / 217 role links / 52
permissions**, demo seed **2 invoices + 1 payment**, routes **165**. Then smoke as Daniel
`+254700000020`: `/platform/billing` 200 (stats/table), `/platform/billing/invoices/new` 200,
create invoice → detail, `void` terminal 409, record payment → auto-paid/partial, payment
refund/failed, Grace/Jane/guard **403**, unauth **302**; on the server
`php scripts/billing-past-due.php --dry-run` then apply (idempotent second run = 0 new). Bundle
refresh + pin are produced after the final suite (Lead “bundle go”); the owner packet
[`deploy-now-inc6-inc7.md`](deploy-now-inc6-inc7.md) and [`deployment-cpanel.md`](deployment-cpanel.md)
§3d carry the runbook. Rollback/DB policy: [`deployment-rollback.md`](deployment-rollback.md).

## Next

- T111 billing UI/smoke report + T112 billing-test fold + qa-e2e report → **final full suite** →
  flip this record to **ACCEPTED**.
- **T113** release bundle refresh (secret screen, pin-able commit) + final Inc-10 deploy docs.
- **Increment 11** — estate billing read + reports/exports (see
  [`increment-11-brief.md`](increment-11-brief.md)).

## Evidence artifacts (local/gitignored unless noted)

`storage/temporary/inc10-gate-readiness.json` · `t77_out.txt` (55/55) · `t98_out.txt` (24/24) ·
`inc10-parking-regression-matrix.md` · `inc10-e2e.js` / `inc10-e2e-run.log` (pending) ·
`inc10-billing-e2e-skeleton.md` · `storage/temporary/pending-tests/Billing*Test.php` +
`BILLING-FOLD-CHECKLIST.md` · `storage/temporary/gates/GATES.md` · this doc + `docs/changelog.md`.
