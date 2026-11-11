# Increment 11 — Estate billing (read-only) + Reports & Exports — brief

**Status:** proposed brief (not a binding contract yet). Written by `schema-seed` for the
Lead to decide and turn into `docs/increment-11-contract.md`.
**No code/schema/DB changes** are made by this brief; it proposes scope, routes,
permissions, audit and an optional additive migration 11 (not staged).

## 0. Base state (what Inc-11 starts from)

| | After Inc-10 DB gate (expected) | Current tree at brief time (pre-Inc-10) |
|---|---|---|
| Migrations | **9** (`000001`–`000010`) | 8 (`000001`–`000009`) |
| Tables | **39** (+ billing_invoices/billing_payments) | 37 |
| Permissions / roles / links | **52 / 13 / 217** | 52 / 13 / 217 |
| Routes | **165** (platform billing adds 7) | 131 |
| Inc-8/Inc-9 | no tables; Inc-8 adds the parking grace setting | — |

> Note: an earlier handoff mentioned "147 routes / 8 migrations" — that was the Inc-8
> target. Routes were 131 after Inc-7 and become **165 after Inc-10** (per
> `billing-invoices-contract.md`). Inc-11 proposals below are counted from **165**.
>
> Already shipped and relevant here: Inc-6 incidents/staff, Inc-7 notices, Inc-8 parking
> admin + overstay, Inc-9 platform portal, Inc-10 platform-finance invoices/payments.
> Existing report permissions: `reports.access.view`, `reports.parking.view`,
> `reports.security.view`, `audit.view`, and `billing.subscription.view` (estate
> billing read).

## 1. Goal

Two linked capabilities on top of Inc-10:
1. **Estate-side billing read-only** — estate admins/managers see their own estate's
   subscription + invoices (and payment ledger read-only) without touching platform
   finance. Mutations remain platform-only.
2. **Operational reporting + CSV export** — estate staff with the existing report
   permissions can view filtered, date-ranged reports for visitors/access, parking,
   incidents, staff, notices and finance summaries, and download the same data as CSV.
   **No mutations, no new persistent data stores.**

Design rule: **prefer zero new tables** — SQL aggregates over existing tables. A small
**additive index-only migration 11** is proposed only if query plans demand it (§7).

## 2. Estate billing read-only (admin portal)

| Aspect | Proposal |
|---|---|
| Permission | reuse **`billing.subscription.view`** (already held by estate_admin + estate_manager; also platform roles but they use the platform portal) — **no seeder change** |
| Visibility | current estate only: subscription (plan/status/cycle/starts/ends/trial), invoices (number, status, amount, currency, period, due_at, paid_at), payment ledger read-only (method/reference/amount/status/paid_at). No create/record/refund actions |
| Tenant scope | `estate_id = Context` on every query; foreign invoice uuid → **404**; no cross-estate ids |
| Routes | `GET /admin/billing` (subscription card + invoices table + filters) · `GET /admin/billing/invoices/{uuid}` (detail + payment ledger) → **2 routes** |
| Data source | Inc-10 `subscriptions`, `billing_invoices`, `billing_payments` (read-only) |
| Audit | no mutation; optional `billing.viewed` is **not** proposed (noisy) — export/report auditing covers data leaving the system |
| Empty states | estate without subscription/invoices renders honest empty state |

## 3. Reports & exports (admin portal)

### 3.1 Areas, data and existing indexes

| Area | Primary tables | Date field | Existing index coverage (estate-scoped) |
|---|---|---|---|
| Visitors & access | `visitor_invitations`, `visitors`, `visitor_access_events`, `walk_in_requests` | invitations `valid_from`/`created_at`; events `occurred_at` | invitations `(estate_id,status,valid_from,valid_until)`, events `(estate_id,occurred_at)`, `(invitation_id,occurred_at)` |
| Parking | `parking_sessions`, `parking_reservations`, `parking_allocations`, `parking_bays`, `parking_violations` | sessions `checkin_at`; reservations `starts_at`; violations `created_at` | sessions `(estate_id,status,bay_id)` (no `estate_id,checkin_at`); reservations `(bay_id,starts_at,ends_at,status)`, `(invitation_id,…)`, `(bay_id,estate_id)` (no estate+date index); violations `(estate_id,status,created_at)` |
| Incidents | `access_incidents` | `occurred_at`/`created_at` | `(estate_id,status,created_at)`, `(estate_id,severity)`, `(gate_id,created_at)` |
| Staff | `household_staff`, `households` | `created_at` | `(estate_id,status)`, `(household_id,status)` — no `(estate_id,created_at)` |
| Notices | `estate_notices` | `published_at`/`created_at` | `(estate_id,status,published_at)`, `(estate_id,category)` |
| Finance (summary) | `billing_invoices`, `billing_payments` (Inc-10) | `created_at`/`due_at`/`paid_at` | invoices `(estate_id,status,due_at)`, `(account_id,created_at)`; payments `(estate_id,created_at)` |

Reports are aggregate tables/cards + a paged detail table; default window **last 30 days**,
inclusive `from`/`to` dates interpreted in the **estate timezone**, `from <= to`, max span
**366 days** (open decision §9-Q6). Invalid ranges → 422 with field errors.

### 3.2 Permission mapping (proposal A — reuse catalogue, links stay 217)

| Report | Permission (proposed) | Current holders |
|---|---|---|
| Visitors & access | `reports.access.view` | auditor, estate_admin, estate_manager, platform_operations, platform_super_admin |
| Parking | `reports.parking.view` | auditor, estate_admin, estate_manager, parking_manager, platform_super_admin |
| Incidents | `reports.security.view` | auditor, estate_admin, estate_manager, security_supervisor, platform_super_admin |
| Staff directory (aggregate, masked phones) | `reports.security.view` (reuse — closest "people" report) | as above |
| Notices | `reports.access.view` (reuse — communications/access-adjacent) | as above |
| Finance summary | `billing.subscription.view` | estate_admin, estate_manager (+ platform roles) |

**Proposal B (alternative, requires permission-seeder change):** add
`reports.finance.view`, `reports.staff.view`, `reports.notices.view` (+ possible
`reports.export`) → role links **217 → 220+** and a T29/QA count update. Not recommended
for Inc-11 because it breaks the "no seeder change" pipeline and adds three very narrow
permissions; decide in §9-Q2.

Platform roles holding report permissions (`platform_operations`, `platform_super_admin`)
would **not** gain access to the estate admin portal in Inc-11 unless decided otherwise
(§9-Q5); platform-wide reporting stays out of scope.

### 3.3 Routes proposal (counted from 165)

Option A — one screen per area + a hub, CSV via `?format=csv` on the same route:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/admin/reports` | one of the report permissions (permission-filtered hub) | cards/links |
| GET | `/admin/reports/access` | `reports.access.view` | visitor/access summary + table |
| GET | `/admin/reports/parking` | `reports.parking.view` | sessions/occupancy/violations |
| GET | `/admin/reports/incidents` | `reports.security.view` | incidents by status/severity/type |
| GET | `/admin/reports/staff` | `reports.security.view` | staff directory aggregate (phones masked) |
| GET | `/admin/reports/notices` | `reports.access.view` | notices by status/category |
| GET | `/admin/reports/finance` | `billing.subscription.view` | invoices/payments summary |
| GET | `/admin/billing` | `billing.subscription.view` | estate billing read (§2) |
| GET | `/admin/billing/invoices/{uuid}` | `billing.subscription.view` | invoice + ledger read-only |

**Total new = 9 → 165 + 9 = 174 routes** (CSV reuses each report route with
`?format=csv`, dispatches to a CSV responder; still one route + one permission +
one audit action per area).

Option B — separate CSV endpoint per area (`/admin/reports/{area}.csv`, 6 extra
routes): **165 + 15 = 180**. Cost: more routes to freeze in tests for the same data.
**Recommendation: Option A (174).**

(If the Lead wants an admin audit-log viewer too — blueprint §20 mentions audit logs and
`audit.view` already exists — that would be +1 route `/admin/audit-logs`, 175 total.
Not proposed as core Inc-11; see §9-Q8.)

### 3.4 CSV export requirements

- Same filters as the screen; CSV reflects the **same estate-scoped query**; foreign
  estate data can never leak.
- Content-Type `text/csv; charset=utf-8`, `Content-Disposition: attachment;
  filename="<area>-<estate-slug>-<from>_<to>.csv"`, `Cache-Control: no-store`.
- UTF-8 with BOM for Excel compatibility (open decision §9-Q1), `\r\n` line endings.
- **CSV injection mitigation:** cells beginning `= + - @` (and tab/CR) are prefixed with
  `'`; all values escaped per RFC 4180 (quotes doubled).
- **PII policy (§9-Q3):** default **masked** columns (phone last-3, names as-is?) —
  proposal: phones masked (`+2547***4567`), no PIN/credential values ever exported; full
  PII only if Lead approves for `auditor`/`estate_admin`; every export audited.
- Streaming response (`php://output`) with a **row cap** (e.g. 50,000 rows / 25 MB) and
  a clear 422/notice when exceeded (open decision §9-Q4); no temp files on disk (the
  blueprint's `data_export_requests` async-job table is explicitly deferred — §8).
- Rate limit exports (e.g. 10/hour/user) using the existing rate limiter; repeated
  exports audited.
- **Audit:** action `report.exported`, metadata `{report, format, from, to, row_count,
  estate_id?}` only (no row contents/PII); optionally `report.viewed` for screen views
  (§9-Q7). Existing audit pattern: `audit_logs` with `estate_id` + PII-free metadata.

### 3.5 Aggregate contents (proposal)

- **Access:** invitations by status; visitors checked-in/out; walk-ins approved/rejected;
  events by type/gate; peak hours table (optional).
- **Parking:** sessions by status (incl. overstay), average duration, reservations by
  status, allocations active/ended, violations by status/type.
- **Incidents:** by status/severity/type, opened vs resolved in range, resolution time
  average (optional), gate/household breakdown.
- **Staff:** staff count by status/type per building (phones masked; no individual PII
  in aggregate).
- **Notices:** published/draft/archived by category, reach = audience all/residents/staff.
- **Finance:** invoices by status (open/paid/void/past_due), outstanding amount (cents →
  display), paid in range, payments by method/status; **read-only**, estate-scoped.

## 4. UI surfaces (pwa-shell)

Admin portal only: `/admin/reports` hub + six report pages (filters bar: from/to, status,
area-specific selects; KPI cards; table → cards on mobile; empty states; export button),
and `/admin/billing` + invoice detail under the existing admin shell/nav ("Billing",
"Reports" permission-filtered items). No platform UI changes (Inc-10 already ships
platform billing); no resident PWA changes.

## 5. Permissions / RBAC summary

- Proposal A: **no catalogue/role-seeder change** → 52 permissions / 13 roles /
  **217 links** unchanged.
- Permission matrix to add to tests: auditor (all 3 report perms, read-only, no
  billing), estate_admin/estate_manager (all reports + estate billing read),
  security_supervisor (incidents/staff reports only), parking_manager (parking only),
  platform_operations (reports.access.view but **no estate portal access** unless Q5
  decides), guards/residents/tenants/household_members → 403 on all Inc-11 routes,
  unauth → 302 login.

## 6. Audit actions proposed

| Action | When | Metadata |
|---|---|---|
| `report.exported` | CSV generated/served | report key, format, from, to, row_count (no PII) |
| `report.viewed` (optional) | screen opened | report key, from, to |

No mutations → no other audit actions. Estate billing read is not audited by default
(read-only; avoid noisy audit rows) — open decision §9-Q7.

## 7. Is an additive migration 11 needed? (proposal — NOT staged)

**Core Inc-11 needs no new tables/columns** — all reports are aggregates over existing
tables and the Inc-10 billing tables. Current index coverage is sufficient for small/medium
estates. Potential date-range gaps (measure with `EXPLAIN` before deciding):

| Candidate (only if plans show scans) | Index proposal |
|---|---|
| `parking_sessions` by check-in range | `KEY idx_parking_sessions_estate_checkin (estate_id, checkin_at)` |
| `visitor_invitations` created-in-range | `KEY idx_visitor_invitations_estate_created (estate_id, created_at)` |
| `household_staff` created-in-range | `KEY idx_household_staff_estate_created (estate_id, created_at)` |
| `parking_allocations` starts-in-range | `KEY idx_parking_allocations_estate_starts (estate_id, starts_at)` |

**Recommendation:** start with **no migration 11** (current data volumes are tiny; all
estate-scoped indexes have left-prefix `estate_id`). If the contract mandates a latency
budget (e.g. reports < 300 ms at 100k rows) or `EXPLAIN` at gate shows `ALL` scans, add a
**migration 11 with only the 1–4 additive indexes above** (no tables, no columns, no data
changes → 10 migrations, still 39 tables, links 217). Do not add `data_export_requests`
async jobs in Inc-11.

## 8. Seed/demo considerations

- **No seeder change proposed** for the report surfaces: the existing demos already cover
  every area (2 estates, 14 households, visitor history/events, parking sessions/
  reservations/violations, 3 staff, 3 incidents, 4 notices, and after Inc-10, 2 invoices +
  1 payment for Greenview). Riverside remains the empty-state example.
- CSV exports of the demo data are small and safe for acceptance.
- If finance demo feels thin (1 paid + 1 open invoice), an **optional** future demo seed
  extension could add 2–3 past-month paid invoices for report visuals — propose only if
  the Lead wants richer screenshots; it would change the 2-invoice expected count and
  require test updates (not recommended for Inc-11 unless requested).

## 9. Open decisions for the Lead

1. **CSV format:** UTF-8 with BOM (Excel-friendly) vs plain UTF-8; `;` vs `,` delimiter
   (recommend: UTF-8+BOM, comma).
2. **Permission strategy:** A = reuse `reports.access.view` / `reports.parking.view` /
   `reports.security.view` / `billing.subscription.view` (links stay 217, recommended) vs
   B = add `reports.finance.view` / `reports.staff.view` / `reports.notices.view` and bump
   role links (needs T29/QA updates).
3. **PII policy for exports/screens:** fully masked phones (default), masked by default
   with an "unmask" permission, or full PII for admin/auditor? (Affects views + CSV +
   tests; legal/retention considerations exist in the blueprint §45.)
4. **Export limits:** synchronous streaming only (recommended) vs async
   `data_export_requests` job table (out of scope, needs schema); row cap (propose 50k)
   and rate limit (propose 10/hour/user); behaviour when cap exceeded (422 vs truncate
   with notice).
5. **Platform roles:** should `platform_operations`/`platform_super_admin` be able to
   open estate-portal reports? (Recommend **no** in Inc-11 — platform has its own
   surfaces; avoids tenant-scoping ambiguities.)
6. **Date-range defaults/limits:** default last 30 days (recommended); max span 366 days
   vs 93 days (performance/large CSV); are null dates (e.g. unresolved incidents) included
   outside the range filter?
7. **Audit noise:** only `report.exported` (recommended) vs also `report.viewed`;
   should estate billing reads be audited at all?
8. **Audit-log viewer:** include an admin `/admin/audit-logs` screen (+1 route) in Inc-11
   (permission `audit.view` already exists) or defer? (Blueprint §20 lists it; Inc-11 is a
   natural home but it adds scope.)
9. **Route count:** Option A **174** (recommended) vs Option B **180**; exact list must be
   frozen in the contract for tests.
10. **Migration 11:** no migration (recommended) vs additive indexes only (§7); if
    indexes are wanted, contract must include the exact index list so it can be staged.
11. **Finance report scope:** read-only own-estate invoices/payments only (recommended) —
    confirm no platform-wide finance reports leak into the estate portal.
12. **Retention/exports storage:** confirm exports are **streamed and not stored** on the
    server (proposal). If stored, `storage/exports` retention policy + cleanup job needed.

## 10. Out of scope for Inc-11

- Any billing **mutations** in the estate portal (create invoice/payment/refund stays
  platform-finance only, Inc-10).
- Payments/PSP, M-Pesa STK push, receipts, credit notes, dunning.
- Scheduled/emailed reports, push notifications, dashboards with charts/BI,
  PDF/Excel exports (CSV only), custom report builder, cross-estate/platform-wide
  reporting, data warehouse.
- Async export jobs (`data_export_requests`), export file retention on disk.
- Resident-facing bills screen; platform usage/SMS analytics screens (blueprint S07/S08).
- Audit-log viewer unless Q8 says include it.
- Credential/PIN export (never).

## 11. Acceptance sketch (for the contract)

1. Route table frozen at the chosen option (174/175/180) and exactly matched by tests.
2. Permissions matrix: 403 for guards/residents/tenants/household_members; scoped 200s
   for estate_admin/manager/auditor/parking_manager/security_supervisor per above;
   unauth 302.
3. Tenant isolation: David (Riverside) sees no Greenview data; foreign invoice uuid 404.
4. Reports: default last-30-days window, from/to inclusive in estate TZ, invalid range
   422; every area's KPI numbers reconcile with direct SQL fixtures.
5. CSV: correct headers/filename/BOM/escaping/format, formula-injection mitigation,
   masks per decision, row cap/rate limit behaviour, `report.exported` audit row with
   PII-free metadata.
6. Estate billing read: subscription + invoices + ledger read-only; no POST routes;
   Grace can see, Jane/guard 403.
7. No migrations unless Q10 approves index-only migration 11; in that case: 10
   migrations, still 39 tables, 52 perms/13 roles/217 links, additive indexes only.
8. Full suite green; README Inc-11 section; acceptance doc.
9. Optional Q8: audit-log viewer with `audit.view`, PII-free metadata, tenant-scoped.

## 12. Task split proposal (for the Lead)

| Task | Agent | Scope |
|---|---|---|
| T101 backend | modules | `app/Modules/Reports/**` (services + CSV responder), `app/Modules/Billing/**` read-only estate controllers; no routes until gate |
| T102 views | pwa-shell | `app/Views/admin/reports*.php`, `admin/billing*.php`, nav/CSS; no routes until gate |
| T103 tests pre-written | qa-inc6/qa-mail | tests for routes/permissions/reports/CSV/audit; parked until fold |
| T104 DB (only if Q10 approves indexes) | schema-seed | stage migration 11 index-only + apply at gate |
| T105 Lead gate | Lead | full suite + live acceptance + `docs/increment-11-acceptance.md` |
