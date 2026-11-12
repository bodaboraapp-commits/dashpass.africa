# Estate billing (read-only) + Reports & Exports — contract *(DRAFT — NOT BUILT, NOT RELEASED)*

**Status: DRAFT — Inc-11 design contract (paired with the Inc‑11 brief). Inc-11 was never built;
this contract is design-only and starts after Inc‑12/Inc‑13 complete.** Do **not** claim Inc‑11 as
released. No code/migration/route is created by this document. **Acceptance skeleton:**
[`increment-11-acceptance.md`](increment-11-acceptance.md) (DRAFT/planned).
**Base reality (2026-11-12, latest):** Inc-10 **ACCEPTED/DELIVERED** (bundle 409 entries, pin
`f11367c`, sha256 `18B6FB03…90E1`); Inc-13/Inc-12 built + E2E green, **GATED — pending final
suite**, **live preview applied=11 / pending=0** (**11 migrations / 42 tables / 84 FKs / 52
permissions / 13 roles / 217 links**), routes **183 (139 web + 44 API)**; migration
`20261111_000012_add_estate_notice_images.sql` (Inc-12) is applied on the preview. **In-flight
backend (modules, 2026-11-12):** estate **appearance default**, **admin billing settings wiring**,
**platform schedule routes**, and **Inc-11 reports/CSV** — these will grow the route table **beyond
183**; the **final count is reported by tests at the gate** (not frozen here).
**Brief source:** [`increment-11-brief.md`](increment-11-brief.md) (proposal; superseded where this
contract is more specific).**Product name:** config-driven (dev MtaaPass, prod DashPASS).

## 0. Freeze decisions to make at the Inc‑11 gate

1. **No new permissions** — reuse the existing catalogue (`billing.subscription.view` for estate
   billing read / finance reports; `reports.access.view` / `reports.parking.view` /
   `reports.security.view` for legacy areas if included) → **52 permissions / 13 roles / 217 links
   unchanged**; no seeder change.
2. **Read-only only** — no POST/PUT mutations in the estate portal; invoice/payment/refund
   management stays **platform finance** (Inc-10/Inc-13). No new tables/columns (aggregates over
   existing data); an **index-only** migration is allowed only if `EXPLAIN` shows scans (would be
   `000013`, since `000012` is Inc-12 images).
3. **Routes: current base 183 (139 web + 44 API) + Inc-11 additions; final count reported by tests
   at the gate (grows beyond 183 — in-flight appearance/settings/schedule + reports/CSV work also
   adds routes)** — see §8.
4. **Exports:** CSV download (streamed, never stored) + **print/PDF via the browser** (print CSS);
   no server PDF library, no async export jobs, no `data_export_requests` table.
5. **Audit:** read-only pages are not audited by default (avoid noise); **exports are audited**
   (`report.exported`, PII-free metadata). See §7.
6. No release claim until the Inc‑11 gate (build after Inc‑12/Inc‑13 complete).

## 1. Scope

Two linked estate-portal capabilities built on Inc‑10/Inc‑13 billing data:

1. **Estate billing read-only** — an estate admin/manager sees **their own estate's** subscription,
   invoices and payment ledger (read-only). No create/record/refund; mutations remain platform-only.
2. **Reports & exports (finance-focused)** — date-ranged, estate-scoped reports:
   **billing summary**, **outstanding / past-due**, **payments received**, and **utility bills**
   (Inc‑13 `utility_charges`). CSV export of each report + browser print/PDF. Read-only.

**Out of scope (v1):** any billing mutation; PSP/M‑Pesa STK/receipts/credit notes/dunning;
scheduled/emailed reports; charts/BI dashboards; server-side PDF; Excel/XLSX; custom report builder;
cross-estate or platform-wide reporting; data warehouse; async export jobs; resident-facing bills
(Inc‑13 already ships `/app/bills`); credential/PIN export (never); broad visitor/parking/
incident/staff/notice reports from the legacy brief — **decision Q1** (recommended: defer or a
follow-up increment; keep Inc‑11 finance/billing-focused).

## 2. Estate billing read-only

| Aspect | Specification |
|---|---|
| Permission | **`billing.subscription.view`** (already held by `estate_admin` + `estate_manager`; no seeder change) — platform finance keeps using `/platform/billing*` |
| Visibility | current estate only: subscription (plan/status/cycle/starts/ends/trial), invoices (number, status, amount/currency, period start/end, due_at, paid_at), payment ledger read-only (method, reference, amount, status, paid_at) from Inc‑10 tables |
| Tenant scope | `estate_id = Context` on every query; foreign invoice uuid → **404** (never 403 leak); no cross-estate ids in any link/param |
| Actions | **none** — GET only; no POST routes under `/admin/billing*`; no export from detail beyond CSV list |
| Empty states | estate with no subscription/invoices renders honest empty state (Riverside demo) |
| Audit | none for plain reads (read-only; avoid noise) — see Q7 |

## 3. Report set (finance-focused)

Reports are aggregate cards/tables + paged details, all **estate-scoped**, built from Inc‑10/Inc‑13
tables (`billing_invoices`, `billing_payments`, `utility_charges`, `billing_schedules`,
`subscriptions`, `estates`):

| Report | Contents | Default window |
|---|---|---|
| **Billing summary** | invoices by status (draft/open/past_due/paid/void) + counts/amounts; paid in range; outstanding balance (cents → display) | last 30 days |
| **Outstanding / past-due** | open + past_due invoices with due dates and days-overdue; aging buckets (0–30/31–60/61+ days, display) | all open, due-date filtered |
| **Payments received** | confirmed payments in range by method (mpesa/bank/card/cash/other) and status; totals; references (read-only) | last 30 days |
| **Utility bills** | Inc‑13 `utility_charges` by status/period/service (flat/metered/both), totals; links to `/admin/utility-bills` read-only | current period + selectable months |

Rules: default window **last 30 days**; `from`/`to` **inclusive** dates interpreted in the **estate
timezone** (Africa/Nairobi display; UTC storage as in Inc‑13); `from <= to`; **max span 366 days**;
invalid range → **422** with field errors; KPI numbers reconcile with direct SQL fixtures; negative/
zero amount rows excluded from "received" totals (refunds shown separately); no cross-estate data.

## 4. CSV export requirements

- **Same filters and the same estate-scoped query** as the screen; a foreign estate can never leak.
- `Content-Type: text/csv; charset=utf-8`; `Content-Disposition: attachment; filename="<report>-<estate-slug>-<from>_<to>.csv"`; `Cache-Control: no-store`.
- UTF‑8 **BOM** for Excel, `\r\n` line endings, RFC 4180 quoting (double quotes doubled).
- **CSV injection mitigation:** cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'`.
- **PII:** masks on by default (phones `+2547***4567`; references as-is; **never** PIN/credentials/
  secrets); optional unmask decision Q4; exports audited.
- **Streaming** (`php://output`), **row cap 50,000 / ~25 MB** with a clear message/422 when exceeded;
  no temp files; reuse the existing rate limiter (**10 exports/hour/user**, decision Q5).
- Available at a single export route per report family (see §8) — no per-area duplicate routes.

## 5. Permissions matrix (reuse existing, links stay 217)

| Role | Estate billing read | Finance reports | CSV export |
|---|---|---|---|
| `estate_admin` | ✅ (`billing.subscription.view`) | ✅ | ✅ |
| `estate_manager` | ✅ | ✅ | ✅ |
| `auditor` | ❌ (no billing read in estate portal; Q7) | via existing `reports.*` only if legacy areas included | ❌ for finance |
| `platform_finance` / super admin | ✅ via `/platform/billing*` (not the estate portal, Q6) | platform portal only | — |
| `guard`, resident, tenant, household_member | ❌ **403** | ❌ 403 | ❌ 403 |
| unauthenticated | `302 /login` | 302 | 302 |

`platform_operations`/`platform_super_admin` do **not** get the estate portal in Inc‑11 unless Q6
says otherwise. Resident "own bills" stays the Inc‑13 `/app/bills` read/pay surface.

## 6. UI surfaces

Admin shell only (`/admin`, permission-filtered nav items): **Billing** (overview + invoice detail,
read-only) and **Reports** (finance hub: four reports, date-range bar, KPI cards, paged tables,
"Download CSV" + "Print" buttons; print stylesheet for clean PDF via browser). Empty states and
mobile cards per existing admin UI patterns; no platform UI changes; no resident PWA changes.

## 7. Audit

| Action | When | Metadata (PII-free) |
|---|---|---|
| `report.exported` | CSV generated/served | report key, format=csv, from, to, row_count, estate_id — **no row contents/PII** |
| `report.viewed` *(optional, Q7)* | report screen opened | report key, from, to |

No audit rows for plain read-only billing views (noisy; decision Q7). Exports reviewable by the
platform `billing.*`/audit viewers as existing audit rows.

## 8. Routes (frozen at the Inc‑11 gate; final count reported by tests — grows beyond 183)

Candidate set (4–5 Inc-11 web routes, no APIs) — **exact freeze later**; current route base **183**
(139 web + 44 API, Inc-13/Inc-12 preview) and **in-flight additions** (estate appearance default,
admin billing settings wiring, platform schedule routes) will raise the final count. **Final total is
reported by tests at the gate and frozen in this contract**:

| # | Method | Path | Handler (proposed) | Permission |
|---|---|---|---|---|
| 1 | GET | `/admin/billing` | `EstateBillingController::index` (subscription + invoices + balances read-only) | `billing.subscription.view` |
| 2 | GET | `/admin/billing/invoices/{uuid}` | `EstateBillingController::show` (read-only detail + payment ledger; foreign uuid → 404) | `billing.subscription.view` |
| 3 | GET | `/admin/billing/reports` | `EstateBillingReportController::index` (finance reports hub + summary/outstanding/payments/utility, `?report=` + date filters) | `billing.subscription.view` |
| 4 | GET | `/admin/billing/export.csv` | `EstateBillingReportController::export` (same filters; streamed CSV; audited) | `billing.subscription.view` |
| 5 | GET | `/admin/billing/reports/utility` | *optional* utility-bills report page (else reuse Inc‑13 `/admin/utility-bills`) | `billing.subscription.view` |
| 6 | — | *in-flight (fold where possible)* | estate appearance default + admin billing settings wiring folded into `/admin/settings`/`/admin/billing`; **platform schedule routes** (e.g. `GET/POST /platform/billing/schedules`) — separate routes counted by tests; **TBD** | existing gates |

**Expected:** **current 183 + Inc-11 additions + in-flight settings/schedule routes → final count
TBD (reported by tests at the gate; previously estimated ~185–186 before the in-flight additions)**;
no POST routes under `/admin/billing*`; no new APIs; no route renames of existing
`/admin/utility-bills`. Exact count/list frozen in this contract at the Inc‑11 gate for tests.

## 9. Demo data

Reuse existing demo data — **no seeder change**: Greenview has 2 invoices + 1 payment (Inc‑10) and
Inc‑13 utility schedules/charges; Riverside is the honest empty state. CSV exports of demo data are
small and safe for acceptance. A richer finance demo (extra past-month paid invoices) is **optional
only if the owner asks** — it would change the 2-invoice expected count and test fixtures.

## 10. Acceptance criteria

1. **Routes frozen** at the chosen list and exactly matched by tests (final count reported by tests
   at the gate, grows beyond 183; no APIs).
2. **Permissions matrix:** estate_admin/manager 200; guards/residents/tenants/household_members 403;
   unauth 302; platform roles not in the estate portal (unless Q6); **52/13/217 unchanged**.
3. **Tenant isolation:** Riverside admin sees no Greenview rows; foreign invoice uuid → 404; export
   never leaks cross-estate.
4. **Reports:** default last-30-days, inclusive from/to in estate TZ, `from<=to`, span ≤366d,
   invalid → 422; each KPI reconciles with direct SQL fixtures; empty states honest.
5. **Read-only:** no POST routes under `/admin/billing*`; screens show no mutation actions; invoice/
   payment/refund actions remain platform-only.
6. **CSV:** headers/filename/BOM/CRLF/RFC 4180/injection-prefix correct; masks applied; row-cap and
   rate-limit behaviour; `report.exported` audit row PII-free; print/PDF via browser prints cleanly.
7. **No migration** (unless Q10 index-only migration `000013` approved: additive indexes only,
   11→12 migrations, still 42 tables/84 FKs/52/13/217).
8. **Tests/E2E hook list** (§11) covered; full suite + Inc‑10/11/12/13 regressions green; acceptance
   doc `docs/increment-11-acceptance.md` written at the gate.

## 11. Test / E2E hooks (pre-write at gate)

- Route table: count + exact paths/methods/handlers/permissions (incl. no POST under `/admin/billing`).
- Permission matrix: estate_admin/manager 200; guard/resident/tenant/household_member 403; unauth 302;
  platform roles excluded (unless Q6).
- Tenant isolation: foreign-estate ids/uuid → 404; export/list never cross-estate.
- Reports: fixtures vs KPIs for each report; date default/limits/422; timezone inclusivity; aging
  buckets; utility report reads Inc‑13 charges; empty estate state.
- CSV: `text/csv`, attachment filename, BOM, CRLF, quoting, injection prefix, PII masks, row cap,
  rate limit, `report.exported` audit row (no PII), no temp files.
- Read-only: GET-only; no state-changing routes; document that platform billing mutations still work.
- Print/browser-PDF: print stylesheet exists, page renders without nav/sidebar artifacts.
- Regressions: Inc‑10 billing, Inc‑12 images, Inc‑13 automated billing suites; full suite green.
- Optional (Q8): `/admin/audit-logs` viewer + `audit.view`, tenant-scoped, PII-free.

## 12. Open owner decisions

1. **Scope of reports:** finance-only (recommended, as above) vs include the legacy brief's
   visitor/parking/incident/staff/notice reports (adds routes/screens; no new permissions needed for
   areas with existing `reports.*`).
2. **Route freeze:** option set above (5 routes → 185–186) vs merge utility report into
   `/admin/billing/reports` (4 routes → 185) vs extra CSV endpoints (more routes).
3. **CSV formatting:** UTF‑8 BOM + comma (recommended) vs plain UTF‑8/semicolon.
4. **PII policy:** masked phones by default (recommended) vs full PII for estate_admin/auditor.
5. **Export limits:** row cap 50k and 10/hour/user (recommended) — confirm or adjust.
6. **Platform roles in estate portal:** keep platform roles out (recommended) vs allow
   `platform_operations` read-only estate reports.
7. **Audit noise:** only `report.exported` (recommended) vs also `report.viewed`; audit estate
   billing reads at all?
8. **Audit-log viewer** `/admin/audit-logs` (+1 route) in Inc‑11 or defer?
9. **Date defaults/limits:** last 30 days default, max span 366 days (recommended) — confirm.
10. **Index-only migration `000013`:** none (recommended; measure EXPLAIN) vs add the brief's 1–4
    additive indexes at the gate.
11. **Async exports / stored exports:** streamed and not stored (recommended) — confirm.
12. **Print/PDF:** browser print CSS (recommended) vs server-side PDF library (out of scope).

---

*DRAFT Inc‑11 contract — design only; no build, routes, migration or release claim. Build after
Inc‑12/Inc‑13 complete and the Lead freezes the route/decision list. Update at the Inc‑11 gate.*
