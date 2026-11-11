# Increment 4 — Estate/Admin Management Portal — Acceptance Record

**Date:** 2026-11-11 (Africa/Nairobi)
**Contract:** `docs/estate-admin-management-contract.md` (§1–§8, incl. admin-area gate + frozen §7 keys)
**Product name:** config-driven (dev MtaaPass, prod DashPASS)
**Scope delivered:** estate-admin portal for buildings, households + members, residents (create/link/suspend/reactivate), guards + gate assignments, gates, allowlisted estate settings — **no schema changes** (existing tables; soft archive only).

## 1. Evidence

### 1.1 Application layer (T27)
- New `app/Modules/Admin/` (18 files): `AdminBaseController`/`AdminShell` (admin-area gate + shell keys), `AdminAccess`, `AdminNotifier`, Building/Household(+Member)/Resident/Guard/Gate/Settings services + controllers, `AdminController` dashboard.
- `routes/web.php`: **26 new routes**, total **99** (web 63 + API 36), each `auth`+`estate`+`permission:<slug>`; admin-area gate requires one of household.manage/estate.user.manage/guard.manage/gate.manage/estate.settings.manage on every `/admin*` handler.
- Fixes: trait helper visibility, dashboard audit closure capture, `visitorReservationsToday($context, …)`; guard archive maps to `estate_memberships.status='suspended'` (enum has no inactive); archived household excluded from invite selectors and rejected with 404 HOUSEHOLD_NOT_FOUND in invitation/walk-in creation.
- Verification: 150/150 in-process checks; `php -l` 134 files; live smoke 15/15; filtered Increment-3 invite/walk-in suites green.

### 1.2 Views (T28)
- `layouts/admin.php` + 10 admin screens built against the frozen §7 keys (dashboard with real stats/audit, buildings, building detail, households + filter, household detail + member add (`mode`), residents + suspend/reactivate, guards + gate assignment, gates, settings), account "Admin console" link gated to admin-manage permissions, `public/assets/css/admin.css`.
- Verification: `php -l` clean; render harness **145/145** (full/minimal/absent, XSS, permissions matrix, CSP/markup); live 8010 matrix green (estate_admin all pages, estate_manager bounded, resident/guard 403, David Riverside scoped).

### 1.3 Data integrity / no schema change (T30)
- **No migration 8 / no ALTER**; 6 migrations with unchanged checksums; 34 tables; schema supports all 28 admin write paths (**141 assertions / 0 failures**).
- No hard deletes in data tables (baseline row ids preserved; only status changes; `guard_gate_assignments` is a contract-allowed replaceable link table). Demo fixtures intact (V-01 reserved, V-02 completed, settings 240/30).
- Audit: all 18 `admin.*` actions present, metadata free of phone/name/PIN/OTP.
- Clean-room gate: fresh rebuild = 6 migrations / 34 tables / **198** role links (pre–Increment 6), 5 seed sections exact, second run no-op (**36 assertions / 0 failures**).

### 1.4 Tests (T29)
- RouteRegistrationTest: exact **99** routes (46 GET / 53 POST) with middleware per route.
- Admin suites: access matrix (estate_admin; estate_manager bounded; resident/tenant/guard 403 on every admin route incl. read-only; unauth 302; suspended admin loses access), tenant isolation (Riverside↔Greenview 404s/no leak), buildings/households/members/residents/guards/gates/settings CRUD + validation (`BUILDING_EXISTS`, `UNIT_EXISTS`, `MEMBER_EXISTS`, `PRIMARY_MEMBER_REQUIRED`, `RESIDENT_EXISTS`, `RESIDENT_NOT_ACTIVE`, `HOUSEHOLD_INACTIVE`, `GUARD_EXISTS`, `GATE_EXISTS`, `SETTING_NOT_ALLOWED`, `CANNOT_SUSPEND_SELF`), soft archive/no-hard-delete, archived-household invite exclusion, archived-gate rejection, dev SMS invite with config product name, CSRF on 17 admin POSTs, XSS on every new field, audit actions PII-free.
- Full-suite result: **recorded in T29 completion / task board** (see run log `tests/_t29-final.log`).

### 1.5 Lead live acceptance (T32) — dev server `127.0.0.1:8010`, clean reset + seed
Independent HTTP harness (real sessions/CSRF/dev-OTP): **57/57 PASS**
- Grace: all 7 admin pages 200 clean + real dashboard stats; create building/household/member/resident/gate/guard; duplicate building/unit/resident rejected with error flashes (no duplicates created); created resident logs in, sees `/app`, blocked from `/admin`; suspend → loses estate context (`/app/no-estate` redirect), reactivate restores; create gate → archive (inactive, still managed); create guard with gate → logs in → `/gate` 200 → archive → gate access lost; settings update persisted (`visitor_validity_minutes=300`), unknown key rejected with flash.
- Estate_manager (Peter): overview/buildings/households/guards/gates 200; residents/settings 403.
- Resident (Jane): 403 on every admin route; account has no admin link.
- Riverside admin (David): `/admin` 200 scoped; households page contains no Greenview units/buildings; foreign Greenview household → 404.
- Archived gate excluded from guard assignment options.
- DB postconditions after run: gate inactive=1, suspended membership=1, settings=300, 11 `admin.*` audit actions, no hard deletes.

## 2. Known limitations (documented)
- Buildings create/edit only (no archive — table has no status column), stated in UI.
- Guard archive is a membership suspension (enum), no hard delete; guard can be re-created/re-added later.
- Web-form-only admin (no JSON admin API yet); platform super-admin provisioning, parking bay/allocation admin UI, and staff directory are later increments.
- Admin area is permission-derived: `estate_manager` is bounded (residents/settings 403, gates read-only); `security_supervisor` can reach `/admin` via gate.manage but only its permitted sections.
- No browser automation in the sandbox; responsive/a11y verified by render harness + live HTTP.

## 3. Next increment
**Increment 6 — Household Staff Directory + Visitor Incidents** (`docs/staff-incidents-contract.md`), migration 8 (2 tables) + 22 routes (total 121), role links 198 → 204 (residents/tenant/household_member get `household.staff.manage` + `visitor.incident.create`, approved Option B).
