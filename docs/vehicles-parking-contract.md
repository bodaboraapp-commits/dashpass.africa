# MtaaPass/DashPASS — Vehicles & Parking Module (Increment 3) — binding spec

**Status:** binding. Read fully before writing code.
**Base:** `docs/foundation-contract.md` (security rules, conventions), `docs/visitor-access-module-contract.md` (visitor flows, invite/pass/guard patterns). This increment extends the same codebase; the product name is configuration-driven (`APP_NAME`, prod = **DashPASS**).
**Blueprint references:** §27 (Workflow F parking), §15 vehicles/parking tables, §49 (allocation algorithm), §50 (parking reports later), Task 002 admin later.
**Goal:** residents register household vehicles, see their allocated parking, and reserve visitor bays as part of the invitation; guards see/complete parking sessions at check-in/checkout. Replace the Bookings *preview* with real parking data.

## 0. Scope

In scope: vehicles (estate-scoped), parking zones/bays, resident allocations (seeded/admin-later), visitor reservations with transactional conflict prevention, parking sessions tied to visitor check-in/out, resident Vehicles + My Parking screens, real Bookings screen (bays + reservations), invite integration (optional vehicle + parking required), guard check-in/out parking side effects, audit + tests + README.

Out of scope: admin bay/allocation CRUD UI (Increment 4), ANPR/barriers, overstay enforcement job (stub/audit only), payments, facility (non-parking) bookings.

## 1. Database (T19 — migration `20261111_000007_create_vehicles_parking_tables.sql`)

Follow foundation conventions (InnoDB/utf8mb4, DATETIME(3), uuid public ids, composite tenant FKs).

**`vehicles`** — id, uuid UNIQUE, estate_id, household_id, plate_number VARCHAR(20), vehicle_type ENUM('car','motorcycle','van','truck','other') DEFAULT 'car', colour VARCHAR(40) NULL, status ENUM('active','inactive','blacklisted') DEFAULT 'active', created_at, updated_at. UNIQUE(estate_id, plate_number); UNIQUE(id, estate_id); FK estate, (household_id, estate_id)→households(id,estate_id); INDEX(household_id, status).
Store plate normalized: uppercase, no spaces/hyphens (app-level normalization for matching; keep a display copy unnecessary).

**`parking_zones`** — id, uuid UNIQUE, estate_id, name, zone_type ENUM('resident','visitor','mixed','service'), created_at. UNIQUE(estate_id,name); UNIQUE(id,estate_id); FK estate.

**`parking_bays`** — id, uuid UNIQUE, estate_id, zone_id, bay_code VARCHAR(30), bay_type ENUM('resident','visitor','accessible','service'), status ENUM('available','reserved','occupied','maintenance') DEFAULT 'available', created_at, updated_at. UNIQUE(estate_id,bay_code); UNIQUE(id,estate_id); FK estate, (zone_id,estate_id)→parking_zones(id,estate_id); INDEX(estate_id,status,bay_type).

**`parking_allocations`** — id, uuid UNIQUE, estate_id, bay_id, household_id, vehicle_id NULL, starts_at, ends_at NULL, status ENUM('active','suspended','ended') DEFAULT 'active', created_at, updated_at. FK estate, (bay_id,estate_id)→parking_bays(id,estate_id), (household_id,estate_id)→households(id,estate_id), vehicle_id→vehicles; INDEX(bay_id,status), INDEX(household_id,status).

**`parking_reservations`** — id, uuid UNIQUE, estate_id, invitation_id, bay_id, vehicle_id NULL, plate_number VARCHAR(20) NULL, starts_at, ends_at, status ENUM('reserved','checked_in','completed','cancelled','expired') DEFAULT 'reserved', created_at, updated_at. FK estate, (invitation_id,estate_id)→visitor_invitations(id,estate_id), (bay_id,estate_id)→parking_bays(id,estate_id); INDEX(bay_id,starts_at,ends_at,status), INDEX(invitation_id).

**`parking_sessions`** — id, uuid UNIQUE, estate_id, bay_id, vehicle_id NULL, invitation_id NULL, checkin_at, checkout_at NULL, status ENUM('active','completed','overstay','void') DEFAULT 'active', created_at, updated_at. FK estate, (bay_id,estate_id)→parking_bays(id,estate_id), (invitation_id,estate_id)→visitor_invitations(id,estate_id); INDEX(estate_id,status,bay_id), INDEX(invitation_id,status).

**`parking_violations`** — id, uuid UNIQUE, estate_id, bay_id, vehicle_id NULL, plate_number NULL, violation_type ENUM('overstay','unauthorised_bay','unregistered_vehicle','other'), status ENUM('open','resolved','dismissed') DEFAULT 'open', notes VARCHAR(500) NULL, reported_by_user_id NULL, created_at, resolved_at NULL. FK estate, (bay_id,estate_id); INDEX(estate_id,status,created_at).

**Seed (idempotent, stable UUID constants, clearly demo):** Greenview zones `Resident Zone A` (resident) and `Visitor Zone V` (visitor); 8 resident bays `R-01`…`R-08`; 6 visitor bays `V-01`…`V-06` (one `V-06` maintenance, one accessible `V-05` bay_type accessible); allocations R-01→A-001 (Jane), R-02→A-002 (Brian Otieno), R-03→B-001 (Amina); vehicles: `KDA123A` car white (Jane, A-001, allocation R-01), `KCA456B` van grey (Brian, A-002, R-02), `KDD789C` car black (Amina, B-001, R-03). One demo visitor reservation for the seeded upcoming invitation "Demo Visitor Three" (tomorrow 10:00–14:00) on `V-01` reserved; one completed history session for a history invitation on `V-02`. Re-running `php scripts/install.php` makes no changes.

## 2. Business rules (T20 — exact)

**Plate normalization:** `strtoupper(preg_replace('/[^A-Za-z0-9]/','', $input))`; validate 4–12 chars; store normalized; display as stored. Unique per estate (409 `PLATE_ALREADY_REGISTERED`).

**Vehicles (resident, own household only):** create/update/deactivate limited to vehicles of households where the user is an active member in the active estate (404 otherwise, no enumeration); `vehicle.manage_own` permission; admin (`vehicle.manage_all`) can list all in estate and change status. Default `active`. Deactivate sets `inactive`.

**Parking availability:** `GET /api/v1/parking/availability?from&to&type=visitor` — visitor bays in the active estate, excluding `maintenance`; a bay is available when no overlap with reservations `reserved|checked_in` and `[starts_at,ends_at)` and no active session. Return `{bays:[{uuid,bay_code,zone_name,bay_type,status,available}], from, to}`.

**Reservation (transactional):** create only through the invitation flow (InvitationService::createWithParking or ParkingService::reserveForInvitation inside the same DB transaction as the invitation + credentials):
1. lock candidate bays `SELECT ... FOR UPDATE` (order by bay_code to avoid deadlocks);
2. re-check availability (overlap query);
3. if none available → 409 `NO_PARKING_AVAILABLE` and **do not create the invitation** (explicit user-facing error; no silent promise);
4. insert reservation `reserved`, plate/vehicle optional;
5. bay status stays `available` until check-in (reservations create the overlap constraint).
Update bay status: `reserved` is derived, not stored per reservation; keep bay.status for physical states only (`available|occupied|maintenance`).

**Guard integration:** on visitor check-in, if the invitation has a `reserved` reservation → set reservation `checked_in`, bay `occupied`, create `parking_sessions` row (active, checkin_at=now) in the same transaction; on checkout → close session (`completed`, bay `available`), reservation `completed`. No reservation → no parking side effects. Never fail the visitor check-in because of parking (parking failure logs an audit `parking.session_failed` and continues; document this).

**Sessions:** one active session per bay / per invitation (enforced by status transitions + row locks + tests). Plateau: `GET /api/v1/parking/sessions/active` (guard/admin).

**Allocations:** resident sees own household allocations (bay/zone/vehicle/times); admin later manages. Cannot reserve a resident bay for visitors (`RESIDENT_BAY_NOT_ALLOWED`).

**Permissions:** vehicles → `vehicle.view_own`, `vehicle.manage_own` (resident), `vehicle.view_all`/`manage_all` (staff); parking availability/view → `parking.view`; reservation create is part of invitation create (no extra permission); guard session start/stop is part of `visitor.checkin`/`visitor.checkout`; violations view → `parking.violation.manage`.

## 3. Routes (T20)

Resident web (`auth`,`estate`):
| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/app/vehicles` | `VehicleController::webIndex` | `permission:vehicle.view_own` |
| POST | `/app/vehicles` | `VehicleController::webStore` | `permission:vehicle.manage_own`, throttle |
| POST | `/app/vehicles/{uuid}/deactivate` | `VehicleController::webDeactivate` | `permission:vehicle.manage_own` |
| GET | `/app/parking` | `ParkingController::webIndex` | `permission:parking.view` |
| GET | `/app/bookings` | `ResidentController::webBookings` (update to real parking) | `auth`,`estate` |

Resident API (`/api/v1`, auth+estate): `GET/POST /resident/vehicles`, `POST /resident/vehicles/{uuid}/deactivate`, `GET /resident/parking` (allocations + reservations), `GET /parking/availability?from&to`.

Guard API: `POST /gate/parking/sessions/checkin`, `POST /gate/parking/sessions/checkout`, `GET /gate/parking/active` (all `visitor.checkin/checkout` perms as above) — plus automatic side effects inside existing `/gate/visitors/checkin|checkout`.

Invitation integration (extend existing, backwards compatible): `POST /api/v1/resident/invitations` and the web invite form accept optional `vehicle_id` (uuid, must belong to the host household) and `parking_required` (bool). Response adds `parking`: null or `{reservation_uuid, bay_code, zone_name, starts_at, ends_at}`. Pass screen/pass API shows the reserved bay when present. Availability endpoint feeds the invite form before submit (no fake confirmation).

Public pass page: when a reservation exists, show `Parking: bay {bay_code}` (no vehicle plate for privacy? show plate only to guard, not public page — keep public page bay code only).

## 3.1 Frozen implementation details (T20 acknowledged)

- Route table total after this increment: **73** (61 existing + 12): 4 resident web (`GET/POST /app/vehicles`, `POST /app/vehicles/{uuid}/deactivate`, `GET /app/parking`; `/app/bookings` updated in place), 5 resident API (`GET/POST /api/v1/resident/vehicles`, `POST /api/v1/resident/vehicles/{uuid}/deactivate`, `GET /api/v1/resident/parking`, `GET /api/v1/parking/availability`) and 3 guard API (`POST /api/v1/gate/parking/sessions/checkin`, `POST /api/v1/gate/parking/sessions/checkout`, `GET /api/v1/gate/parking/active`).
- `/app/bookings` window query: `?tab=facilities|bookings&from=&to=`; `availability_from`/`availability_to` are ISO-8601 UTC (`Y-m-d\TH:i:s.v\Z`); missing/invalid windows fall back to now→+24h and never 500.
- Extra safe view keys: `/app/vehicles` also passes `vehicle_types` = `[{value,label}]`; `/app/passes/{uuid}` parking object includes `status_label`/`status_tone` extras.
- Status labels/tones: vehicles active→Active/ok, inactive→Inactive/neutral, blacklisted→Blacklisted/danger; allocations active→Active/ok, suspended→Suspended/warn, ended→Ended/neutral; reservations reserved→Reserved/warn, checked_in→Checked in/ok, completed→Completed/neutral, cancelled→Cancelled/danger, expired→Expired/warn.
- New error codes: `PLATE_ALREADY_REGISTERED` (409), `VEHICLE_NOT_FOUND` (404), `NO_PARKING_AVAILABLE` (409, invitation transaction rolls back — no invitation row), `RESIDENT_BAY_NOT_ALLOWED` (409), `NO_PARKING_RESERVATION` (409), `NO_ACTIVE_SESSION` (409).
- Guard check-in/out parking side effects never fail the visitor operation; a parking failure audits `parking.session_failed` and continues.

## 4. Views (T21 — pwa-shell, resident only)

- `app/Views/app/vehicles.php`: list household vehicles (plate, type, colour, status), add form (plate/type/colour), deactivate; empty state; no fabricated data.
- `app/Views/app/parking.php`: My Parking — allocation card(s) (bay, zone, vehicle), upcoming reservations with validity/status, link to invite; empty states.
- `app/Views/app/bookings.php`: replace Facilities preview with real data — Facilities tab = visitor bay availability (bay code/zone/status, availability window selector), My Bookings tab = reservations with status; keep design/markup patterns and honest empty states. Remove "Layout preview" from Bookings.
- `app/Views/app/invite.php`: optional vehicle select (household vehicles) + "Visitor parking required" checkbox; when checked, show available visitor bays count fetched from the availability API via existing `MtaaPass.api` (progressive enhancement; server validates again).
- `app/Views/app/pass.php`: show reserved bay when present.
- Home: Vehicles tile → `/app/vehicles`; Bookings/Facilities tile → `/app/bookings`; keep design language, CSP-safe, `e()`, root-relative, single h1.

## 5. Tests (T22 — mandatory)

Unit: plate normalization/validation; bay overlap helper (date ranges). Integration: vehicle create/list/deactivate scoped to household; duplicate plate 409; cross-household 404; parking availability excludes maintenance/overlaps/active sessions; reservation inside invitation transaction (success + rollback when no bay → invitation not created); overlapping reservation rejected concurrency-style (two sequential attempts → one success one 409); check-in starts session + bay occupied + reservation checked_in; checkout completes session + bay available; check-in works with no parking; parking failure does not fail visitor check-in; tenant isolation for vehicles/reservations/sessions; guard permissions; route registration count/list updated (currently 61 → +N); Bookings screen real data no preview; XSS/CSRF on new forms/APIs; audit actions (`vehicle.created`, `vehicle.deactivated`, `parking.reserved`, `parking.session_started`, `parking.session_completed`).
README: Increment 3 section (demo vehicles/bays, routes, booking flow), test counts, limitations, next increment.

## 6. Audit + notifications

Audit actions exact: `vehicle.created`, `vehicle.updated`, `vehicle.deactivated`, `parking.reserved`, `parking.reservation_cancelled`, `parking.session_started`, `parking.session_completed`, `parking.session_failed`. Dev SMS on parking confirmed to visitor (template `parking_confirmation` with bay code + window) — reuse VisitorNotificationService; no plate in SMS. No reservation means no SMS.

## 7. Write scopes & tasks

| Agent | Task | Scope |
|---|---|---|
| schema-seed | T19 | `database/**` migration+seed |
| modules | T20 | `app/Modules/**`, `routes/**` |
| pwa-shell | T21 | `app/Views/app/{vehicles,parking,bookings,invite,pass}.php`, `layouts/app.php`, `partials/**`, `public/assets/css/app.css`, `public/assets/js/app.js` |
| tests | T22 | `tests/**`, `README.md` |
| git-release | T23 | verify auto-push pipeline covers the increment; no app-code writes |
| Lead | T24 | contract, integration, full suite + live acceptance, deployment docs consistency |

## 8. Acceptance

1. Migration 7 applies on fresh `_test` + dev DB idempotently; seed idempotent with demo zones/bays/vehicles/allocations/reservation.
2. Resident `0700000010` (Jane): `/app/vehicles` lists KDA123A; add vehicle persists; deactivate works; `/app/parking` shows R-01 allocation; `/app/bookings` shows real visitor bays (Facilities) and the seeded reservation (My Bookings); invite with `parking_required` succeeds when a bay is free and fails cleanly (`NO_PARKING_AVAILABLE`) when none (no invitation created).
3. Guard `0700000004`: verify/check-in the seeded demo session reservation → bay V-01 occupied, session active; check-out → bay available, reservation completed. Visitor check-in without parking still works.
4. Security: tenant isolation, permissions, CSRF, XSS, duplicate plate, reservation conflict, no fabricated data; PIN/credentials unaffected.
5. Full `php tests/run.php` green (existing 329 + new); `php -l` clean; `node --check` clean.
6. README + deployment docs consistent; demo data documented; live smoke on 8010.
