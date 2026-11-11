# MtaaPass/DashPASS — Increment 10: Billing / Invoices — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation + platform contract; expected after Inc-9: **158 routes**, 8 migrations, 37 tables, role links 217, platform portal live.
**Product name:** config-driven.
**Goal:** platform-finance billing: create invoices (from subscription plans), record payments (manual/M-Pesa reference), track statuses, and a past-due sweep. Estate billing read surfaces deferred to Inc-11.
**Design source:** `storage/temporary/inc10/BILLING-SCHEMA-OUTLINE.md` (approved decisions below resolve its open questions).

## 0. Scope and decisions (approved)
- **Two additive tables only** → migration 10: `billing_invoices`, `billing_payments`. After Inc-10: 9 migrations / 39 tables. (The earlier handoff "40" was off-by-one; 39 is binding.)
- **Additive index** on `subscriptions`: `UNIQUE (id, estate_id)` added by migration 10 to support a composite tenant FK from invoices (`subscription_id, estate_id`). Non-destructive; no data change.
- **Invoice numbering:** `INV-<slugUpper? no>` → format `INV-{YYYYMM}-{0001}` per estate, sequence = max existing invoice_number for that estate+month + 1 computed inside the create transaction; `UNIQUE (estate_id, invoice_number)`.
- **Amounts:** integer cents, currency CHAR(3) default KES. Create form prefills amount from active plan `monthly_price_cents` (annual ×12) but allows manual override; no tax/VAT/line items this increment (deferred).
- **`past_due`:** CLI `scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]` sets `open` → `past_due` for invoices with `due_at < now`, audits `billing.invoice_status_changed`, idempotent; no scheduler daemon.
- **Payments:** append-only ledger; statuses `pending→confirmed|failed`, `confirmed→refunded`; refund = status change + audit (no negative rows). `billing_payments.updated_at` included.
- **Surface:** platform-finance only this increment (`platform_finance` + `platform_super_admin` hold `billing.invoice.view`, `billing.payment.manage`; no seeder change, links stay 217). Estate read-only billing for `billing.subscription.view` holders is Inc-11.
- No hard deletes: invoices `void`, payments `failed/refunded` only; all mutations audited (`billing.invoice_created`, `billing.invoice_status_changed`, `billing.payment_recorded`, `billing.payment_status_changed`), metadata uuids/amounts/currency/status only (no PII).

## 1. Migration 10 (T76 — `20261111_000010_create_billing_tables.sql`)
**`billing_invoices`**: id, uuid UNIQUE, estate_id, account_id, subscription_id NULL, invoice_number VARCHAR(40), status ENUM('draft','open','paid','void','past_due') DEFAULT 'open', period_start DATE NULL, period_end DATE NULL, amount_cents INT UNSIGNED, currency CHAR(3) DEFAULT 'KES', due_at DATETIME(3) NULL, paid_at DATETIME(3) NULL, notes VARCHAR(500) NULL, created_by_user_id, updated_by_user_id NULL, created_at, updated_at. UNIQUE(uuid), UNIQUE(estate_id, invoice_number), UNIQUE(id, estate_id), INDEX(estate_id,status,due_at), INDEX(account_id,created_at), INDEX(subscription_id) ; FKs: estate→estates, account_id→saas_accounts, composite (subscription_id,estate_id)→subscriptions(id,estate_id) (nullable).
**`billing_payments`**: id, uuid UNIQUE, estate_id, invoice_id, method ENUM('mpesa','bank','card','cash','other'), reference VARCHAR(80) NULL, amount_cents INT UNSIGNED, currency CHAR(3) DEFAULT 'KES', status ENUM('pending','confirmed','failed','refunded') DEFAULT 'confirmed', paid_at DATETIME(3), recorded_by_user_id, metadata JSON NULL, created_at, updated_at. UNIQUE(uuid), UNIQUE(id, estate_id), INDEX(invoice_id,status), INDEX(estate_id,created_at); composite FK (invoice_id,estate_id)→billing_invoices(id,estate_id), estate FK, recorded_by→users.
**Index change**: `ALTER TABLE subscriptions ADD UNIQUE KEY uq_subscriptions_id_estate (id, estate_id);` (additive).
**Seed (idempotent)**: Greenview growth plan (existing KES 5,000): 1 **paid** invoice (last month, invoice `INV-202610-0001`, paid_at set, 1 confirmed mpesa payment REF DEMO-MPESA-001), 1 **open** invoice (current month, due +14d). Stable UUIDs `5a…`/`5b…`; no Riverside rows; second run zero changes.

## 2. Routes (158 → **165**; 7 new, platform-only web)
All under `/platform/billing` with `['auth']` middleware + controller platform gates (platform area + exact permission):
| Method | Path | Permission |
|---|---|---|
| GET | `/platform/billing` | `billing.invoice.view` |
| GET | `/platform/billing/invoices/new` | `billing.payment.manage` |
| POST | `/platform/billing/invoices` | `billing.payment.manage` |
| GET | `/platform/billing/invoices/{uuid}` | `billing.invoice.view` |
| POST | `/platform/billing/invoices/{uuid}/status` | `billing.payment.manage` |
| POST | `/platform/billing/invoices/{uuid}/payments` | `billing.payment.manage` |
| POST | `/platform/billing/payments/{uuid}/status` | `billing.payment.manage` |
Navigation: "Billing" in platform nav (permission-filtered); dashboard quick-link.
Platform area gate: any `platform.*` permission; estate-only users 403; unauthenticated 302 login.

## 3. Business rules
- Create invoice: estate (active/suspended allowed; archived 422 `ESTATE_ARCHIVED`), optional subscription (must belong to estate), amount_cents >0, currency 3 letters, `due_at` future-optional, period dates valid (start≤end), notes ≤500; status `open` (or `draft` when `save_draft=1`); audit; numbering transactional (lock/unique retry).
- Status transitions: `draft→open`, `open→paid|void|past_due`, `past_due→paid|void`, `paid→void` (correction, allowed only `billing.payment.manage`); terminal `void` → 409 `INVOICE_CLOSED`; marking `paid` requires `paid_at` and an existing confirmed payment total ≥ amount? **Decision:** `paid` requires at least one confirmed payment covering amount (422 `PAYMENT_REQUIRED`/`PAYMENT_AMOUNT_MISMATCH`); payments may be partial (status remains open/past_due, UI shows balance).
- Record payment: invoice must be open/past_due (422 `INVOICE_CLOSED`), amount >0, method enum, reference ≤80 optional; status pending/confirmed (default confirmed for manual cash/mpesa reference), paid_at; audit; does not auto-mark invoice paid unless fully covered → sets paid + paid_at automatically and audits.
- Payment status: pending→confirmed|failed, confirmed→refunded; failed/refunded terminal → 409 `PAYMENT_CLOSED`; refund re-opens invoice? **Decision:** refund of the only covering payment sets invoice back to `past_due` (if due passed) or `open`, and clears paid_at (audited as invoice status change).
- List/filter: invoices by status/estate/month, payments by status/method; dashboard stats: open/past_due totals (cents), paid this month, total outstanding.
- Past-due sweep idempotent + audit only on changes; prints counts; no HTTP.
- Tenant/platform rules: only platform finance/super-admin; estate admins 403 on all billing routes; foreign invoice uuid → 404; no hard deletes; PII-free audit.

## 4. Views (T78 — platform shell)
`platform/billing.php` (dashboard: outstanding/paid stats + invoice table + filters), `platform/billing-invoice-form.php` (create from estate/search/plan prefill/manual amount/due/notes; errors/old/CSRF), `platform/billing-invoice.php` (detail: invoice meta, balance, payment ledger with record-payment form and payment status actions, invoice status actions). Add "Billing" platform nav item + dashboard quick-link (permission-filtered). Patterns per platform.css: single h1, chips, tables→cards, CSRF/errors/old, empty states, reduced-motion, dark/light.

## 5. Tests (T79)
Migration 10: **9 migrations / 39 tables**, `subscriptions` unique index exists, invoices 2/payments 1 seeded idempotent, cross-tenant composite FK rejection; routes exactly **165**; platform gate (finance/super admin 200; estate users 403; unauth 302); invoice create/validate/numbering sequence (two creates get different numbers), duplicate numbering protection, status transitions incl. terminal 409, paid requires covering payment (422), partial payments allowed, record payment transitions + auto-paid, payment terminal rules + refund re-opens invoice, past-due CLI dry-run/apply/idempotent, tenant isolation 404, CSRF/XSS, no hard deletes, audit PII-free; existing suite green; README Inc-10.

## 6. Tasks
| Task | Agent | Scope |
|---|---|---|
| T76 stage migration 10 + seed (apply on Lead DB gate) | schema-seed | `storage/temporary/inc10/` |
| T77 backend phase 1 (no routes until gate) | modules | `app/Modules/Billing/**`, `scripts/billing-past-due.php` |
| T78 platform billing views phase 1 | pwa-shell | `app/Views/platform/billing*.php`, nav/CSS |
| T79 tests pre-written + later fold | qa-mail | parked under `storage/temporary/pending-tests/` |
| T80 Lead gate | Lead | migration gate + full suite + live acceptance + `docs/increment-10-acceptance.md` |

## 7. Acceptance (T80)
1. Migration 10 applies clean (dev + fresh test: 9 migrations / 39 tables / 217 links / 2 invoices / 1 payment), second run no-op.
2. Full suite green; routes exactly 165.
3. Live: Daniel (platform finance) creates invoice from Greenview plan → number `INV-YYYYMM-0001` (next sequence), records confirmed M-Pesa payment → invoice auto-paid; partial payment stays open; refund flips invoice back; invalid transitions 409/422; past-due CLI dry-run then apply flips the seeded open invoice (if overdue) idempotently; Grace/Jane 403; audit PII-free; no hard deletes.
4. README/docs + acceptance doc; next increment = estate billing read + reports/exports.
