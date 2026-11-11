# Increment 3 — Vehicles & Parking — Acceptance Record

**Date:** 2026-11-11 (Africa/Nairobi)
**Contracts:** `docs/foundation-contract.md`, `docs/visitor-access-module-contract.md`, `docs/vehicles-parking-contract.md` (§1–§8)
**Product name:** config-driven — development **MtaaPass**, production **DashPASS**

## 1. Delivered

| Layer | Deliverable |
|---|---|
| Database | Migration `20261111_000007_create_vehicles_parking_tables.sql` — 7 tables (vehicles, parking_zones, parking_bays, parking_allocations, parking_reservations, parking_sessions, parking_violations), 17 FKs incl. 9 composite tenant FKs; idempotent `DemoParkingSeeder` (2 zones, 14 bays R-01…R-08/V-01…V-06 incl. accessible V-05 + maintenance V-06, 3 vehicles, 3 allocations, 1 reserved reservation, 1 completed session) |
| Application | `Vehicles` module (household-scoped CRUD, plate normalization, duplicate 409, admin all-scope branch); `Parking` module (availability with overlap/active-session exclusion, transactional reservation inside the invitation+credentials transaction, guard check-in/out session side effects with savepoints, allocations/reservations/active sessions) |
| Integration | Invite accepts optional `vehicle_id` + `parking_required`; `NO_PARKING_AVAILABLE` rolls the whole invitation back; pass/presenter exposes `parking`; public pass shows bay+zone only (no plate/PII); `parking_confirmation` dev SMS; audit actions per §6 |
| Routes | 61 → **73** (4 resident web, 5 resident API, 3 guard API; `/app/bookings` updated in place) |
| Resident UI | Vehicles screen, My Parking, real Bookings (Facilities = visitor bays, My Bookings = reservations, no preview), invite vehicle select + parking toggle with availability count, pass parking card, public-pass parking line, Home tiles wired |
| Security | `estate_admin` granted `vehicle.view_own`/`manage_own` alongside `*_all` (196 → 198 role-permission links); tenant isolation, CSRF, permissions, XSS coverage extended |

## 2. Evidence

### 2.1 Database (T19 / T25)
- Fresh rebuild: **6 migrations**, **34 tables**, 52 FKs (9 parking composite), unique inventories verified; cross-estate inserts rejected; seed idempotent (second run = zero changes). Clean-room gate: **111/0 assertions**.
- Dev DB `install.php` twice: "nothing to apply (6 already applied)" / "no changes"; known state restored (V-01 reserved · V-02 completed · V-05 accessible · V-06 maintenance).

### 2.2 Application (T20)
- `php -l` 40/40 clean; install idempotent; route count 73.
- In-process proof: **114/114 PASS** — vehicle scoping/duplicates/permissions; availability filters; invite+parking success; 5 sequential reservations then 6th → `NO_PARKING_AVAILABLE` with **zero invitation rows added**; guard check-in starts session/occupies bay/checks reservation in; checkout completes/frees; no-parking check-in unchanged; tenant/permission/audit checks.

### 2.3 UI (T21)
- `php -l` 29 views clean; `node --check` clean; CSS audit 209/209; render harness **101/101**; absent-data sweep **31/31**; live smoke **46/46** (vehicles, parking R-01/V-01, real bookings, invite parking + availability API, pass/public pass, no absolute URLs, single h1).

### 2.4 Tests + README (T22)
- RouteRegistration exact **73**; new vehicle/parking suites; Bookings preview assertions replaced with real data; README Increment 3 section (demo plates/bays, routes, flow, counts, limitations, next increment).
- Full suite after Increment 3: **Total 400 / Passed 400 / Failed 0 / Duration 634s / RESULT: PASS** (Increment 3 added 71 tests to the previous 329).

### 2.5 Lead live acceptance gate (T24) — dev server `127.0.0.1:8010`
Independent HTTP harness (real sessions, CSRF, dev-OTP login, JSON APIs): **44/44 PASS**
- Health: `ok`, 6 migrations applied, 0 pending.
- Resident (Jane): home tiles; `/app/vehicles` shows KDA123A, hides KCA456B; `/app/parking` R-01 + Resident Zone A + seeded V-01 reservation; `/app/bookings` real bays V-01…V-05, no "Layout preview", My Bookings shows seeded reservation.
- Availability API: 5 visitor bays, V-06 maintenance excluded, all free before reservations.
- Invite+parking: 5 reservations on 5 distinct bays; 6th overlapping → clean `NO_PARKING_AVAILABLE`; **no invitation created** (rollback).
- Pass + public pass: reserved bay shown; public page leaks no plate/PIN.
- Admin (Grace): `/app/vehicles` 200 with all three estate plates (T26 gap closed).
- Guard (John): gate selected → verify new parking PIN 200 → check-in 200 → active session on reserved bay → check-out 200 → session cleared.
- DB postconditions: invitation `checked_out`, bay `available`, session `completed` (checkin/checkout timestamps), **0 active sessions**.

## 3. Known limitations (documented, deferred)

- No admin UI yet for bays/allocations/vehicles (Increment 4 = estate/admin management portal; no schema change needed).
- No overstay job/queue worker, no ANPR/barriers, no payments, no non-parking facility bookings.
- Parking failure path (`parking.session_failed`) is implemented/audited but not force-triggered by normal data; graceful no-reservation/no-session paths are covered.
- Browser automation unavailable in the sandbox (JS availability refresh verified via server-rendered count + live API + hooks; forms work without JS).
- Admin vehicle register reachable only via the own-scope route with the all-scope service branch; dedicated admin endpoints come with Increment 4.

## 4. Live deployment status (dashpass.africa)

- Document root fix applied by owner (Option B `.htaccess`): site now serves DashPASS at root; directory listing and log/doc exposure closed; `/health` 200 staging, migrations **6/6**; DB `tokeqvlx_dashpass_db` installed + seeded via `deploy-sync.php`.
- Recommended follow-ups: set `APP_VERSION=0.2.1-dashpass` on the server `.env`, delete previously exposed `storage/logs/*`, purge Cloudflare cache, switch `APP_ENV=production` + `SMS_DEV_EXPOSE_OTP=false` before real residents.

## 5. Next increment

**Increment 4 — Estate/Admin Management** (Blueprint Task 002): estate admin portal for buildings, households, household members/residents, guards + gate assignments, gates, and estate settings; soft-archive semantics via existing status columns; no database changes (see T25 Part B decisions). Platform super-admin provisioning, staff directory and SMS invitations are later increments.
