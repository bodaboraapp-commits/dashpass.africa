# Increment 11 — Acceptance record (estate billing read-only + reports/exports) — **GATED**

**Date:** 2026-11-12 · **Status: GATED — PENDING FINAL SUITE** (backend landed; E2E/suite evidence to
be filled at the gate). **Do not claim Inc-11 as released.**
**Contract:** [`estate-billing-reports-contract.md`](estate-billing-reports-contract.md) (DRAFT) ·
**Base:** current tree **routes 194 (150 web + 44 API)** / **11 migrations / 42 tables / 84 FKs /
52 permissions / 13 roles / 217 links**; live preview **applied=11 / pending=0** (Inc-13/Inc-12 WIP,
**not accepted**) · **Product:** config-driven (dev MtaaPass, prod DashPASS)

> **Gate status:** read-only billing/reports/CSV backend **landed** (`EstateBillingController`,
> `EstateBillingSettingsService`); the **4 Inc-11 routes are registered** and the route table is now
> **194** (the Inc-13 schedule batch added 7 platform schedule routes; appearance default + admin
> billing settings wiring are folded into existing settings routes). **Route/permission gates and the
> final combined baseline suite are pending** (baseline3 was killed mid-run; **baseline4** planned) — this record
> flips to ACCEPTED only when green. **No release claim.**

## Delivered scope (landed; acceptance pending suite)

- **Estate billing read-only** — `GET /admin/billing` (overview: subscription, invoices, balances),
  `GET /admin/billing/invoices/{uuid}` (read-only invoice + payment ledger; foreign uuid **404**),
  estate-scoped by `Context`; **no POST mutations** under `/admin/billing*` (platform finance keeps
  invoice/payment/refund mutations).
- **Reports & export** — `GET /admin/billing/reports` (billing summary, outstanding/past-due,
  payments received, utility bills; date-range filters) and `GET /admin/billing/export.csv`
  (same filters; **streamed CSV (final contract)**: UTF-8 **BOM** always first, `\r\n`, RFC 4180
  quoting, formula-injection prefix, masked PII, **static per-report header row always emitted**
  (empty report = headers only), **row cap 50,000 → HTTP 422 `EXPORT_TOO_LARGE` when 50,001+**;
  **no temp files**; `Cache-Control: private, no-store`).
- **Permissions reuse (no new nodes)** — `billing.subscription.view` (+ existing `reports.*` where
  relevant) → **52 permissions / 13 roles / 217 links unchanged**; platform roles stay on the
  platform portal; guards/residents/tenants/household_members → **403**; unauth → **302**.
- **Audit** — read-only views are not audited (noise); **`report.exported`** is audited with
  PII-free metadata: `report`, `format=csv`, `row_count`, `estate_id`, `from`, `to`, `from_utc`,
  `to_utc`, `timezone` (no row contents/PII).
- **Settings/appearance additions (folded, no new routes)** — estate **appearance default** and
  **admin billing settings wiring** land in the existing `/admin/settings` + platform settings
  surfaces (`EstateBillingSettingsService`), gated by existing `estate.settings.manage` /
  platform gates.
- **Platform schedule routes (Inc-13 batch, current tree)** — 7 routes
  (`GET /platform/billing/schedules`, `/new`, `POST /platform/billing/schedules`,
  `GET|POST /{uuid}`, `/edit`, `/toggle`, `/run-now`) — counted in the **194** route table
  (Inc-13 section, not Inc-11-specific).

## Route evidence (current tree: 194 = 150 web + 44 API)

| # | Method | Path | Handler | Permission |
|---|---|---|---|---|
| 1 | GET | `/admin/billing` | `EstateBillingController::overview` | `billing.subscription.view` |
| 2 | GET | `/admin/billing/invoices/{uuid}` | `EstateBillingController::invoice` | `billing.subscription.view` |
| 3 | GET | `/admin/billing/reports` | `EstateBillingController::reports` | `billing.subscription.view` |
| 4 | GET | `/admin/billing/export.csv` | `EstateBillingController::export` | `billing.subscription.view` |

`GET /admin/billing/reports/utility` (contract’s optional page) **was not added** — utility report
data is served within `/admin/billing/reports` (and the existing Inc-13 `/admin/utility-bills`).
Final count/list **frozen at the gate** (current expectation: **194**; no APIs, no POSTs).

### Report/billing payload & filter keys (post-polish 2026-11-12)

- **Date filters:** `from` / `to` query params are calendar dates interpreted in the **estate
  timezone** (estate `estate_timezone`/`timezone`, fallback `Africa/Nairobi`); the controller derives
  **`from_utc`** / **`to_utc`** UTC instants (00:00:00 / 23:59:59) used in the SQL, and exposes
  `filters.timezone` to the view. Invalid/from-after-to → **422** field errors; the views show a hint
  “Dates use `<timezone>` estate time.”
- **Report keys:** `summary` | `outstanding` | `payments` | `utility` (unknown clamps to `summary`);
  **CSV** filename `estate-billing-<report>-YYYYMMDD.csv`, `Content-Type: text/csv; charset=utf-8`,
  `Content-Disposition: attachment`, `Cache-Control: private, no-store`; audit `report.exported`.
- **Billing 403 page:** users without the estate billing permission/context get a neutral **403 HTML
  page** (“Estate billing is available to estate administrators and managers with billing
  permission… Back to app”) — no data leak, no redirect loop.

## Acceptance criteria (to verify at the gate)

1. Routes exactly matched (current **194**; 4 Inc-11 GET-only routes + 7 Inc-13 schedule routes;
   no APIs; no POST under `/admin/billing*`), all with the frozen handlers/permissions.
2. Permissions: estate_admin/manager → 200; guards/residents/tenants/household_members → 403;
   unauth → 302; platform roles excluded from the estate portal; **52/13/217 unchanged**.
3. Tenant isolation: foreign-estate ids/uuid → 404; reports/export never cross-estate.
4. Reports: default last-30-days window, inclusive `from`/`to` in estate TZ → `from_utc`/`to_utc`
   UTC range, `from<=to`, span ≤366d, invalid → 422; report keys `summary/outstanding/payments/
   utility`; billing **403 page** for unauthorized users (neutral message, no leak); KPIs reconcile
   with SQL fixtures; honest empty states (Riverside).
5. CSV (final contract): static headers always emitted (empty = headers only), filename
   `estate-billing-<report>-YYYYMMDD.csv`, BOM/CRLF/RFC 4180/injection prefix, masks, **cap 50,000 →
   422 `EXPORT_TOO_LARGE` when 50,001+**, `report.exported` audit metadata (PII-free), no temp files.
6. Read-only guarantee: no POST routes under `/admin/billing*`; platform billing mutations unaffected.
7. No migration required for Inc-11 (no new tables/columns; existing 11 migrations/42 tables/84 FKs);
   optional index-only `000013` only if the gate decides.
8. Full suite + Inc-10/12/13 regressions green; final route count recorded; acceptance flipped.

## Known constraints

- **Tests pending** — baseline2 was 865/859 with 6 known reds (folds landed); **baseline3 was killed
  mid-run** (no exit marker, log ~54 KB); a **baseline4** exclusive suite is planned. Inc-11/12/13
  acceptance stay **GATED** until that suite is green.
- **Live preview not a release** — applied=11/pending=0; Inc-11 routes are in the WIP tree, not
  claimed live; **rollback pin `f11367c`** (Inc-10 bundle; additive schema/forward-only migrations).
- **Bundle not rebuilt** — wait for the Lead’s **“bundle go”**; the artifact remains the Inc-10 zip.
- **CSV/print decisions** — browser print/PDF only; no server PDF/Excel/async export jobs; PII masks
  by default; row cap/rate limits per contract (owner decisions in contract §12).
- Open contract decisions (legacy report areas, audit-log viewer, index-only migration, optional
  utility report page) remain owner decisions.

## Test / E2E hooks (to run at the gate)

Route table/count freeze (194), permission matrix, tenant isolation, report KPIs vs fixtures,
date-range validation 422, CSV format/escape/mask/audit, read-only absence of POSTs, print
stylesheet, empty states, regression suites (Inc-10/12/13), full baseline suite.

## Next

- **Baseline4 green** → fill E2E/route/permission/CSV evidence here → flip to **ACCEPTED** at the
  Lead’s gate; then Inc-13/Inc-12 acceptance flips + **bundle go** (rebuild Inc-12/13 bundle, secret
  screen, sha/size/entries/pin), deploy packet update.
- No release claim until the gate.

## Evidence artifacts (to fill at gate)

Route/permission gate report · E2E result JSON + log · SQL fixture reconciliations · CSV sample +
`report.exported` audit rows · route-count output (194) · `tests/_baseline3-full.log` (killed) / `tests/_baseline4-full.log` (planned) · contract +
this record + `docs/changelog.md`.
