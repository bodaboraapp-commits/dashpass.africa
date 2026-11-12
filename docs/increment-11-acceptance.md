# Increment 11 — Acceptance record (estate billing read-only + reports/exports) — **DRAFT / PLANNED**

**Date:** 2026-11-12 · **Status: DRAFT — PLANNED, NOT BUILT / NOT GATED** (skeleton only; fill at the
Inc-11 gate). **Do not claim Inc-11 as released.**
**Contract:** [`estate-billing-reports-contract.md`](estate-billing-reports-contract.md) (DRAFT,
design-only) · **Base:** current tree **routes 183 (139 web + 44 API)** / **11 migrations / 42 tables
/ 84 FKs / 52 permissions / 13 roles / 217 links**; live preview **applied=11** (Inc-13/Inc-12 WIP,
not accepted). · **Product:** config-driven (dev MtaaPass, prod DashPASS)

> **Status note:** Inc-11 is **not implemented yet**. This skeleton captures the acceptance shape +
> route-count expectation so the record can be filled quickly at the gate. **Route count will grow
> beyond 183** — in-flight backend also adds **estate appearance default**, **admin billing settings
> wiring**, **platform schedule routes**, and **Inc-11 reports/CSV**; the **final count is reported
> by tests at the gate** and must be frozen in the contract before acceptance. No release claim.

## Planned scope (from the contract)

1. **Estate billing read-only** — estate admin/manager sees **their estate's** subscription,
   invoices and payment ledger read-only; no mutations (invoice/payment/refund stays platform-only).
2. **Reports & exports (finance-focused)** — billing summary, outstanding/past-due, payments
   received, utility bills; date-range filters (inclusive, estate TZ, span cap); **streamed CSV**
   export (UTF-8 BOM, RFC 4180, formula-injection prefix, masked PII, row cap/rate limit);
   **browser print/PDF**; exports audited (`report.exported`, PII-free).
3. **No new permissions / tables** — reuse existing `billing.subscription.view` (+`reports.*` if
   legacy areas included) → **52/13/217**; aggregates over existing data; index-only migration only
   if `EXPLAIN` demands (would be `000013`).

## Planned routes (final freeze + count TBD at gate; grows beyond 183)

| Candidate | Purpose |
|---|---|
| `GET /admin/billing` | Estate subscription + invoices + balances (read-only) |
| `GET /admin/billing/invoices/{uuid}` | Read-only invoice + payment ledger (foreign uuid → 404) |
| `GET /admin/billing/reports` | Finance reports hub (summary/outstanding/payments/utility + date filters) |
| `GET /admin/billing/export.csv` | Same filters, streamed CSV, audited |
| *(optional)* `GET /admin/billing/reports/utility` | Utility-bills report page (else reuse Inc-13 page) |
| *in-flight additions* | estate appearance default, admin billing settings wiring, platform schedule routes — fold into `/admin/settings`/existing pages where possible; **extra routes + final total reported at gate** |

Expected final count: **>183** (contract previously estimated ~185–186 before the in-flight
additions); **to be confirmed/frozen with tests at the Inc-11 gate**.

## Acceptance criteria (planned)

1. Routes frozen (exact list + count) and exactly matched by tests; no APIs; GET-only under
   `/admin/billing*`.
2. Permissions matrix: estate_admin/manager 200; guards/residents/tenants/household_members 403;
   unauth 302; platform roles excluded from the estate portal (unless owner decides otherwise);
   **52/13/217 unchanged**.
3. Tenant isolation: foreign-estate ids/uuid → 404; exports/list never cross-estate.
4. Reports: default last-30-days, inclusive from/to in estate TZ, `from<=to`, span ≤366d, invalid →
   422; each KPI reconciles with SQL fixtures; honest empty states (Riverside).
5. CSV: correct headers/filename/BOM/CRLF/escaping; injection prefix; masks; row-cap/rate-limit;
   `report.exported` audit row PII-free; no temp files; print/PDF via browser prints cleanly.
6. Read-only guarantee: no POST routes under `/admin/billing*`; platform billing mutations untouched.
7. No migration (unless Q10 index-only `000013` approved: additive indexes only; counts unchanged
   except migration count 11→12).
8. Full suite + Inc-10/12/13 regressions green; evidence recorded (E2E, SQL fixtures, CSV samples).

## Test / E2E hooks (planned)

Route table/count freeze, permission matrix, tenant isolation, report KPIs vs fixtures, date-range
validation 422, CSV format/injection/mask/audit, read-only absence of POSTs, print stylesheet,
optional legacy report areas, regressions. (See contract §11 for the detailed list.)

## Known constraints / dependencies

- Depends on **Inc-12/Inc-13 acceptance** (live preview currently applied=11 but **not accepted**;
  release bundle not rebuilt yet — wait for “bundle go”).
- Backend work in flight (appearance default, admin billing settings wiring, platform schedules
  routes, reports/CSV) → route count still growing; acceptance must not freeze a number until tests
  report it.
- Live rollback reference stays the Inc-10 bundle pin **`f11367c`**; migrations forward-only.
- Optional decisions (report scope, CSV/PII, export limits, index-only migration, legacy report
  areas) remain open in contract §12 — resolve before build.

## Next

- Wait for the Inc-11 build gate; then fill gates/E2E/route counts here → **ACCEPTED** at the Lead's
  gate; no release claim until then.

## Evidence artifacts (to fill at gate)

TBD — route/permission gate report · E2E result JSON + log · SQL fixture reconciliations · CSV sample
+ audit rows · suite log · contract + this record + `docs/changelog.md`.
