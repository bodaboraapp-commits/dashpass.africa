# MtaaPass/DashPASS — Increment 4: Estate/Admin Management Portal — binding spec

**Status:** binding. Read fully before writing code.
**Base:** `docs/foundation-contract.md` (security rules, conventions), `docs/visitor-access-module-contract.md` (patterns), `docs/vehicles-parking-contract.md` (parking data), `docs/increment-3-acceptance.md` (current state).
**Blueprint references:** Task 002 (estate admin management), §20–§23 admin flows, §70 acceptance.
**Product name:** configuration-driven — dev **MtaaPass**, production **DashPASS**. No hardcoded names.
**Goal:** a server-rendered **estate admin portal** where an `estate_admin` (or `estate_manager` within its permissions) manages buildings, households, residents, guards, gates and limited estate settings for **their active estate only**, using existing tables (no schema changes), with soft-archive semantics and full audit + tenant isolation.

## 0. Scope and decisions

In scope (web UI only, no new JSON APIs):
- `/admin` overview (estate summary + links).
- Buildings: list + add + edit + archive (soft).
- Households: list + add + edit + archive; household detail with members; add/link resident to household; remove member (soft).
- Residents: list of estate users/members; add resident (create or link existing user by phone); suspend/reactivate estate membership.
- Guards: list + add guard (user + `security_guard` membership) + gate assignments + archive.
- Gates: list + add + edit + archive (soft: status inactive).
- Estate settings: view + update an **allowlist** of keys only.
- Audit every mutation; tests; README.

Out of scope (later increments): platform super-admin estate provisioning; parking bay/allocation admin UI; staff directory; SMS invite tables; reports/billing screens; JSON admin APIs (web forms only this increment — document it).

**Hard rules:**
1. **No database migrations.** Existing tables already support this (T25 Part B decision). No `ALTER`s.
2. **Never hard-delete.** Archive/suspend via existing status columns (`buildings` has no status: archiving a building is blocked if households reference it; if no households, archive by... **decision:** buildings cannot be archived while active households exist; because `buildings` table has no status column, "archive" = rename? **No** — do not mutate names. Instead: buildings are **immutable + creatable**; no archive action for buildings (document limitation), and households carry status. Gates: `status` enum active/inactive = archive. Households: status active/inactive. Estate memberships: status (inspect enum; suspend/reactivate). Guard users: membership status; gate assignments deactivate/delete? `guard_gate_assignments` — inspect columns and use existing status/delete safely (assignments are links; removal is allowed and audited, no data loss of the user).
3. All estate/user ids derive from `Context` (`$context->tenant`, `$context->auth`) — never from request input. Route middleware `auth`, `estate`, `permission:<slug>`.
4. Every POST: CSRF (global middleware) + server-side validation + audit action; generic 404 for foreign-estate uuids; 422 with field errors otherwise; rate-limit? no (admin trusted) but keep CSRF.
5. Phone/plate normalization reuse existing helpers (`Phone`, vehicle normalizer); no fabricated data; honest empty states.
6. UI: reuse the design system tokens (`app.css`), separate `admin.css` for layout tables/nav; dark default parity, single h1, e() escaping, root-relative URLs, no inline JS/CSS, forms work without JS, responsive (tables → cards under 720px).

## 1. Routes (73 → 99; web only)

All routes: middleware `['auth','estate']` + the permission listed. Grouped under `/admin`.

| # | Method | Path | Handler | Permission |
|---|---|---|---|---|
| 1 | GET | `/admin` | AdminController::dashboard | `estate.view` |
| 2 | GET | `/admin/buildings` | BuildingController::index | `household.view` |
| 3 | POST | `/admin/buildings` | BuildingController::store | `household.manage` |
| 4 | GET | `/admin/buildings/{uuid}` | BuildingController::show | `household.view` |
| 5 | POST | `/admin/buildings/{uuid}` | BuildingController::update | `household.manage` |
| 6 | GET | `/admin/households` | HouseholdController::index | `household.view` |
| 7 | POST | `/admin/households` | HouseholdController::store | `household.manage` |
| 8 | GET | `/admin/households/{uuid}` | HouseholdController::show | `household.view` |
| 9 | POST | `/admin/households/{uuid}` | HouseholdController::update | `household.manage` |
| 10 | POST | `/admin/households/{uuid}/archive` | HouseholdController::archive | `household.manage` |
| 11 | POST | `/admin/households/{uuid}/members` | HouseholdMemberController::store | `household.member.manage` |
| 12 | POST | `/admin/households/{uuid}/members/{memberUuid}/remove` | HouseholdMemberController::remove | `household.member.manage` |
| 13 | GET | `/admin/residents` | ResidentAdminController::index | `estate.user.manage` |
| 14 | POST | `/admin/residents` | ResidentAdminController::store | `estate.user.manage` |
| 15 | POST | `/admin/residents/{uuid}/suspend` | ResidentAdminController::suspend | `estate.user.manage` |
| 16 | POST | `/admin/residents/{uuid}/reactivate` | ResidentAdminController::reactivate | `estate.user.manage` |
| 17 | GET | `/admin/guards` | GuardAdminController::index | `guard.manage` |
| 18 | POST | `/admin/guards` | GuardAdminController::store | `guard.manage` |
| 19 | POST | `/admin/guards/{uuid}/gates` | GuardAdminController::assignGates | `guard.manage` |
| 20 | POST | `/admin/guards/{uuid}/archive` | GuardAdminController::archive | `guard.manage` |
| 21 | GET | `/admin/gates` | GateAdminController::index | `gate.view` |
| 22 | POST | `/admin/gates` | GateAdminController::store | `gate.manage` |
| 23 | POST | `/admin/gates/{uuid}` | GateAdminController::update | `gate.manage` |
| 24 | POST | `/admin/gates/{uuid}/archive` | GateAdminController::archive | `gate.manage` |
| 25 | GET | `/admin/settings` | SettingsController::show | `estate.settings.manage` |
| 26 | POST | `/admin/settings` | SettingsController::update | `estate.settings.manage` |

Also: add a discreet **Admin** link in the resident app account screen for users holding `estate.user.manage` (or `estate.view`), and `/admin` must be reachable at desktop widths within the existing layout shell (dedicated `layouts/admin.php`).

## 2. Business rules (exact)

**Buildings:** create requires `name` (2–120, unique per estate → 422 `BUILDING_EXISTS`) + `building_type` in enum. Edit name/type allowed (unique check). **No archive** (no status column; document). Foreign-estate uuid → 404. Show household counts per building in list/detail.
**Households:** create requires `unit_number` (1–60, unique per estate → 422 `UNIT_EXISTS`), `building_uuid` (optional, must belong to estate), `household_type` enum. Edit unit/building/type. Archive → `status='inactive'` (households list defaults to active with an "archived" filter `?status=inactive`); archived households are excluded from resident invite household selectors (verify InvitationService already filters active — if not, report to Lead, do not change modules) and cannot receive new members. Unarchive action? No route — keep archived (document limitation) OR include toggle via same update form (`status` field with active/inactive, permission household.manage) — **decision: update form includes status active/inactive toggle** (covers archive/unarchive; route #9 handles it; route #10 is a convenience that sets inactive).
**Household members:** add requires existing `user` or new resident creation fields; relationship (string ≤60, e.g. owner/tenant/family/member), is_primary flag (only one primary per household — service enforces; setting a new primary demotes old one). Unique (household,user) conflict → 422 `MEMBER_EXISTS`. Only active memberships in the active estate can be added. Remove → `status='inactive'` (soft), cannot remove the last active primary member (422 `PRIMARY_MEMBER_REQUIRED`).
**Residents:** list = estate memberships of the active estate with user phone/name/roles/status. Add resident: fields `full_name` (2–180), `phone` (valid, normalized), `role_slug` in allowlist [`resident`,`tenant`,`household_member`], optional `household_uuid` + relationship → creates/link user + membership (`active`) + household member. If the phone already has an **active membership in this estate** → 422 `RESIDENT_EXISTS`. If user exists globally → link, don't duplicate. Send dev SMS invite ("You've been added to {estate} on {product}. Sign in with this phone number.") — reuse SMS manager; dev adapter logs; never leak secrets. Suspend/reactivate membership status `suspended`/`active` (inspect enum; if enum lacks suspended use `inactive` and document). Suspended residents must lose access immediately (Context already checks membership status — verify in tests).
**Guards:** add guard: full_name, phone, gate_uuids[] (≥1 required), creates user + `security_guard` membership + `guard_gate_assignments` rows. Assign gates: replace the guard's assignments (add/remove) transactionally; cannot remove a guard's last gate if active? (allow, but guard dashboard will show no gate; warn in UI). Archive guard → membership inactive (guard loses gate access; sessions handled by auth middleware).
**Gates:** create name (unique per estate → 422 `GATE_EXISTS`), location optional. Update name/location. Archive → `status='inactive'` (gate selectors/guard assignment UI exclude inactive; new assignments blocked; existing visitor check-in with archived gate must reject — verify AccessService handles; if not, report and add guard in controller only).
**Settings:** GET lists allowlisted keys with current values + labels/limits. Update accepts only `visitor_validity_minutes` (15–1440) and `visitor_parking_grace_minutes` (0–120) — reject unknown keys with 422 `SETTING_NOT_ALLOWED`; store JSON-encoded values in `estate_settings` (match seeder format), audit `admin.settings_updated` (old/new).

**Audit actions:** `admin.building_created`, `admin.building_updated`, `admin.household_created`, `admin.household_updated`, `admin.household_archived`, `admin.member_added`, `admin.member_removed`, `admin.resident_created`, `admin.resident_linked`, `admin.resident_suspended`, `admin.resident_reactivated`, `admin.guard_created`, `admin.guard_gates_updated`, `admin.guard_archived`, `admin.gate_created`, `admin.gate_updated`, `admin.gate_archived`, `admin.settings_updated`. Metadata: uuids/keys only, never phone/name/PIN/OTP.

## 3. Views (pwa-shell)

- `app/Views/layouts/admin.php` — admin shell: top bar (product name, estate name, user menu/sign-out, back-to-app link), side/tab nav (Overview, Buildings, Households, Residents, Guards, Gates, Settings), flash messages, responsive; reuse head partial + theme tokens; `data-theme-default` consistent.
- `app/Views/admin/dashboard.php` — counts (buildings, active households, residents, guards, gates, active visitor reservations today) from real data; quick links; honest empty states; no fake numbers.
- `app/Views/admin/buildings.php` (list + add form), `admin/building.php` (detail: edit form + household list + counts).
- `app/Views/admin/households.php` (list + status filter + add form), `admin/household.php` (detail: edit form incl. status toggle, members table, add-member form with "new resident" vs "existing resident by phone" modes).
- `app/Views/admin/residents.php` (list + add form + suspend/reactivate actions).
- `app/Views/admin/guards.php` (list + add form with gate checkboxes + assign-gates form per guard + archive).
- `app/Views/admin/gates.php` (list + add/edit forms + archive).
- `app/Views/admin/settings.php` (allowlisted settings only + description of impact).
- `public/assets/css/admin.css` (new; table/list styles, admin nav; reuse tokens from app.css; no inline styles), small `public/assets/js/admin.js` only if needed (optional; prefer none — forms are plain POSTs).
- Add "Admin console" link in `app/Views/app/account.php` when the user can access it (permission check via existing shell data; do not add new unguarded links).

## 4. Services (modules)

`app/Modules/Admin/`: `AdminAccess` (estate-scoped lookups + permission helpers), `BuildingAdminService`, `HouseholdAdminService`, `ResidentAdminService`, `GuardAdminService`, `GateAdminService`, `SettingsAdminService`, and controllers `AdminController`, `BuildingController`, `HouseholdController`, `HouseholdMemberController`, `ResidentAdminController`, `GuardAdminController`, `GateAdminController`, `SettingsController`. Repositories may live per service or in `Admin/Repositories`. All writes in transactions where multi-row (resident create = user+membership+household member; guard create = user+membership+assignments). Use existing `Database`, `Uuid`, `Clock`, `Phone`, `AuditLogger`/`$context->audit`, `Validator`. Reuse `RolesSeeder` slugs from DB (`roles.slug`), never hardcode role ids.

## 5. Tests (tests)

- Route registration: **99** routes total, exact list (73 + 26 admin), middleware assertions on every admin route.
- `/admin` access: estate_admin 200; estate_manager allowed per permissions (document actual matrix); resident/tenant/guard 403; unauthenticated 302 `/login`; suspended admin loses access.
- Tenant isolation: Riverside admin (David / +2547000000014) cannot see or mutate Greenview (lists scoped; foreign uuid create/update/archive → 404; no data leak in errors).
- Buildings: create/list/edit; duplicate name 422; foreign uuid 404; no archive route (document).
- Households: create (unit unique), edit, archive/unarchive status, status filter; archived households excluded from resident invite selector (assert via InvitationService/API or page absence).
- Members: add new resident, add existing user by phone, duplicate member 422, primary uniqueness, last-primary-removal 422, removal soft-inactive.
- Residents: create (new phone) + link (existing phone), duplicate active membership 422, invalid phone 422, dev SMS logged with config product name, suspend/reactivate changes access immediately (suspended resident gets 401/403 after session invalidation per existing auth rules).
- Guards: create with gates → assignments rows, login works, gate app shows assigned gate; assign gates replaces set; archive removes access; guard cannot access /admin.
- Gates: create/edit, duplicate name 422, archive hides from guard assignment selectors and blocks new assignments; archived gate rejected by visitor check-in path (report if product change needed).
- Settings: GET shows allowlist; update valid keys persists JSON + audit; unknown key 422; out-of-range 422.
- Security: CSRF on all admin POSTs (missing token 403), XSS on every new field (escaped in list/detail/flash), no PII/paths in errors, no hard deletes (row counts unchanged after archive/remove; statuses changed), audit actions present and free of phone/name/OTP/PIN.
- Existing 400+ tests (Increment 3 final count) stay green; README: Increment 4 section (admin flows, permissions, demo admin accounts, limitations, next increment).

## 6. Tasks, scopes, sequencing

| Agent | Task | Scope |
|---|---|---|
| modules | T27 | `app/Modules/Admin/**`, `routes/web.php`, and minimal additive changes to existing controllers only if unavoidable (report first) |
| pwa-shell | T28 | `app/Views/admin/**`, `app/Views/layouts/admin.php`, `app/Views/app/account.php` (admin link only), `public/assets/css/admin.css`, `public/assets/js/admin.js` (optional) |
| tests | T29 | `tests/**`, `README.md` |
| schema-seed | T30 | verification support only (no migrations/seeds unless a real fixture gap is found and Lead approves) |
| git-release | T31 | pipeline health + docs, no app writes |
| Lead | T32 | contract, integration, full suite + live admin acceptance gate, docs |

Sequencing: modules (T27) may start immediately against this contract; pwa-shell (T28) builds against the frozen keys in §3 and can start in parallel; tests (T29) writes tests in parallel; full suite only after T27/T28 report complete. Route count changes 73 → 99, so tests must not run the full suite mid-flight without updating RouteRegistrationTest (T29 owns it).

## 7. Frozen view-data contract (T27 emits exactly; T28 consumes)

- `/admin`: `stats` {buildings:int, households_active:int, residents_active:int, guards_active:int, gates_active:int, visitor_reservations_today:int}, `recent_audit` [{action, summary, occurred_at}], `estate` shell data.
- `/admin/buildings`: `buildings` [{uuid,name,building_type,household_count,created_at}], `building_types` [{value,label}], `errors`, `old`.
- `/admin/buildings/{uuid}`: `building` {uuid,name,building_type,created_at}, `households` [{uuid,unit_number,household_type,status,status_label,status_tone,member_count}], `building_types`, `errors`, `old`.
- `/admin/households`: `households` [{uuid,unit_number,building_name,household_type,status,status_label,status_tone,member_count}], `buildings` [{uuid,name}], `household_types` [{value,label}], `status_filter` ('active'|'inactive'|'all'), `errors`, `old`.
- `/admin/households/{uuid}`: `household` {uuid,unit_number,building_uuid,building_name,household_type,status,status_label,status_tone}, `members` [{uuid,user_uuid,full_name,phone_masked,relationship,is_primary,status,status_label,status_tone}], `buildings`, `household_types`, `errors`, `old`.
- `/admin/residents`: `residents` [{uuid,full_name,phone_masked,role_slugs:[],household_unit,status,status_label,status_tone,created_at}], `roles` [{value,label}] (allowlisted resident roles only), `households` [{uuid,unit_number,label}], `errors`, `old`.
- `/admin/guards`: `guards` [{uuid,full_name,phone_masked,gate_uuids:[],gate_names:[],status,status_label,status_tone}], `gates` [{uuid,name,status}], `errors`, `old`.
- `/admin/gates`: `gates` [{uuid,name,location,status,status_label,status_tone,guard_count}], `errors`, `old`.
- `/admin/settings`: `settings` [{key,label,value,min,max,type}], `errors`.
- Flash: `flash_success` / `flash_error`; all admin forms include `_token`.

## 8. Acceptance (T32)

1. `estate_admin` Grace (+254700000001) can: create building/building edit; create household with unit + building; add resident (new phone) → dev SMS + appears in list; link existing resident to household; add/remove member with primary rules; create guard with gate assignment → guard can log in and sees assigned gate in `/gate`; create/edit/archive gate; suspend/reactivate resident (suspended loses access); update allowlisted settings; every mutation audits.
2. Riverside-only admin David sees only Riverside data and 404s on Greenview uuids; resident/guard get 403 on /admin.
3. No hard deletes (row counts stable; statuses changed); archived households excluded from invite selector; archived gate not offered to guards.
4. Full `php tests/run.php` green (Increment-3 final count + new admin tests); exact 99-route list; README updated (admin accounts, flows, limitations).
5. Live smoke on 127.0.0.1:8010 as Grace and David; CSRF/XSS spot checks; docs/increment-4-acceptance.md recorded.
