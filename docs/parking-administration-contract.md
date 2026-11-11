# MtaaPass/DashPASS — Increment 8: Parking Administration + Overstay Handling — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation/vehicles-parking contract; expected state after Inc-7: 131 routes, 8 migrations, 37 tables, role links 217.
**Product name:** config-driven. **No schema changes** — parking tables already exist (zones/bays/allocations/reservations/sessions/violations) plus settings keys; this increment adds management surfaces + overstay detection.

## 0. Scope
In scope: admin management of parking zones, bays and resident allocations (create/edit/archive/end), violations queue (create/list/resolve/dismiss), parking overview stats, guard violation reporting, CLI overstay job (`scripts/parking-overstay.php`) that flags overstayed sessions as violations (dry-run + apply), settings expose `visitor_parking_grace_minutes`/`parking_overstay_grace_minutes` via existing estate settings allowlist, tests/README.

Out of scope: ANPR/barriers/hardware, payments for parking, resident self-service bay booking beyond existing visitor reservations, guard mobile scanning of bay codes.

Hard rules: no hard deletes (bays/zones/allocations status semantics; violations status transitions only), tenant isolation, no schema changes/migrations, all admin routes behind admin-area gate + exact permissions, audit actions PII-free, CSRF.

## 1. Routes (131 → **143**; 12 new web, no new APIs)
Admin (`/admin` + permission):
| Method | Path | Permission |
|---|---|---|
| GET | `/admin/parking` (overview: zones/bays/allocations/violations stats) | `parking.view` |
| GET | `/admin/parking/zones` | `parking.view` |
| POST | `/admin/parking/zones` | `parking.bay.manage` |
| POST | `/admin/parking/zones/{uuid}` | `parking.bay.manage` |
| GET | `/admin/parking/bays` | `parking.view` |
| POST | `/admin/parking/bays` | `parking.bay.manage` |
| POST | `/admin/parking/bays/{uuid}` | `parking.bay.manage` |
| POST | `/admin/parking/bays/{uuid}/archive` | `parking.bay.manage` |
| GET | `/admin/parking/allocations` | `parking.view` |
| POST | `/admin/parking/allocations` | `parking.allocation.manage` |
| POST | `/admin/parking/allocations/{uuid}/end` | `parking.allocation.manage` |
| GET | `/admin/parking/violations` | `parking.violation.manage` |
| POST | `/admin/parking/violations` | `parking.violation.manage` |
| POST | `/admin/parking/violations/{uuid}/resolve` | `parking.violation.manage` |
Guard web: GET `/gate/parking/violations` + POST `/gate/parking/violations` (`parking.violation.manage`? guards lack it; use `parking.session.checkin` for report? **decision:** guard violation reporting uses existing `parking.session.checkin` permission and is logged as `parking.violation.reported`; guard sees only own reports).
Total new: 14 admin + 2 guard = 16 → **131 + 16 = 147**. **Binding target = 147.** (If merged/omitted, update contract first.)

## 2. Rules
- **Zones**: create/edit name (unique per estate → 422 `ZONE_EXISTS`) + zone_type; cannot archive zones (no status column) — document.
- **Bays**: create bay_code unique per estate (422 `BAY_EXISTS`), zone, bay_type; edit zone/type; archive sets status maintenance (`BAY_ARCHIVED`? use `maintenance` semantics) — archived bay excluded from availability/assignment; cannot archive a bay with an active allocation/reservation/session (422 `BAY_IN_USE`).
- **Allocations**: assign bay to household (+ optional vehicle) for a window; one active allocation per bay and per household at a time (422 `BAY_ALLOCATED`/`HOUSEHOLD_ALLOCATED`); end sets status ended + ends_at; archived/inactive household 422 `HOUSEHOLD_INACTIVE`; resident-type bay only for resident households.
- **Violations**: create types overstay/unauthorised_bay/unregistered_vehicle/other, severity? use existing type + status open/resolved/dismissed; resolve requires notes (422), terminal 409 `VIOLATION_CLOSED`; list filters status/type; admin sees estate, guard sees own reports.
- **Overstay job** `scripts/parking-overstay.php [--dry-run]`: for active parking_sessions with checkout_at null where now > invitation valid_until + grace (`parking_overstay_grace_minutes` setting, fallback config 30) → update session status='overstay', create violation (overstay) if none open for session/bay, audit `parking.overstay_flagged`; idempotent; prints counts; no HTTP. Add to README + optional cPanel cron instructions.
- **Settings**: add `parking_overstay_grace_minutes` (0–120) to `SettingsAdminService` allowlist (and seed default 30 for Greenview); no schema change (estate_settings rows).
- Audit: `parking.zone_created/updated`, `parking.bay_created/updated/archived`, `parking.allocation_created/ended`, `parking.violation_created/resolved`, `parking.violation.reported`, `parking.overstay_flagged` — metadata uuids/status only.

## 3. Views (pwa-shell)
Admin: `admin/parking.php` (stats + links), `admin/parking-zones.php`, `admin/parking-bays.php`, `admin/parking-allocations.php`, `admin/parking-violations.php` (+ inline forms/actions; errors/old/CSRF; empty states; filters).
Guard: `guard/parking-violations.php` (report form + own list; gate from session).
Admin nav: "Parking" with sub-nav; permission-filtered (estate_manager: parking.view/allocation/reservation manage; estate_admin all).
No resident changes this increment.

## 4. Tests
Routes 147 exact; zones/bays/allocations/violations CRUD validation codes, archive/in-use semantics, tenant isolation, permissions matrix, no hard deletes, audit PII-free, CSRF/XSS; overstay job: dry-run vs apply, grace setting, idempotent flagging, no duplicate violations, session status overstay; settings allowlist update for parking grace; Incident/parking interaction unchanged.
README Inc-8 section: parking admin, overstay job/cron, demo bays/allocations, routes 147, limitations (no barriers/ANPR/payments, no zone archive).

## 5. Tasks
| Task | Agent | Scope |
|---|---|---|
| T60 backend phase 1 | modules | `app/Modules/Parking/**` additive admin services/controllers + `scripts/parking-overstay.php`; no routes until gate |
| T61 views phase 1 | pwa-shell | admin/guard parking screens + CSS; no routes/nav until gate |
| T62 tests pre-written | qa-inc6 | new tests for parking admin + overstay; no suites until gate |
| T63 Lead gate | Lead | migration-free; full suite + live acceptance + acceptance doc |

## 6. Acceptance
1. No migration added (still 8 migrations; 37 tables; role links 217). Full suite green; routes exactly 147.
2. Live: Grace manages zones/bays/allocations/violations (create/edit/archive/end/resolve) with correct validation; David scoped; guard reports violation and sees own; archived bay excluded; overstay CLI dry-run then apply flags an overdue session + creates one violation (idempotent second run 0 new); audit actions present PII-free; no hard deletes.
3. README/docs updated; next increment recommendation (platform super-admin/billing/reports).
