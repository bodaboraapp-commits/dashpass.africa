# MtaaPass/DashPASS — Increment 6: Household Staff Directory + Visitor Incidents — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation/visitor/vehicles/parking/admin contracts; current state: 99 routes, 6 migrations, 34 tables, 400+ tests.
**Product name:** configuration-driven (dev MtaaPass, prod DashPASS).
**Blueprint references:** §15 tables (`household_staff`, `access_incidents`), resident R14–R15 (staff/emergency surfaces), guard G14 incident reporting, permissions `household.staff.manage`, `visitor.incident.create`, `visitor.incident.manage`.
**Goal:** deliver the two operational modules already present in the permission catalogue but missing from the product: **household staff directory** (residents register domestic staff; guards can see verified staff; admins can audit) and **visitor/security incident reporting** (guards and residents record incidents; admins triage/resolve).

## 0. Scope and hard rules

In scope: migration 8 (`household_staff`, `access_incidents`), demo seed, staff CRUD (resident own household + admin view), guard staff lookup, incident create/list/detail/status workflow (open → investigating → resolved/dismissed), resident incident report for own household/visit, guard incident report at gate, admin incident queue, routes/views/tests/README.

Out of scope: staff invitations/OTP accounts (directory only this increment), photo evidence uploads (defer; text only), notifications/SMS on incidents (audit + email alert optional later), offline incident queue, incident PDF export.

Hard rules:
1. **No changes to existing tables**; migration 8 adds only the two tables below. Existing checksums untouched; 6 → 7 migrations.
2. No hard deletes: staff status active/inactive (record retained); incidents never deleted — status transitions only.
3. Tenant isolation: every lookup estate-scoped via `Context`; foreign uuids → 404.
4. Permissions: reuse existing catalogue, **plus the approved Option-B role grant** (found during T45 staging: residential roles lacked the needed permissions): `household.staff.manage` and `visitor.incident.create` are added to **resident, tenant and household_member** in `RolesSeeder` → role links **198 → 204** (resident 14→16, tenant 13→15, household_member 8→10). Other roles unchanged. `household.staff.manage` gates staff mutations; `visitor.incident.create` gates reporting; `visitor.incident.manage` gates admin triage/queue/all-incident views. Tests/README must use the 204/16/15/10 counts.
5. All POSTs CSRF+validation+audit; audit metadata uuids/keys only (no phone/name/PII/description). Actions: `staff.created`, `staff.updated`, `staff.deactivated`, `incident.reported`, `incident.updated`, `incident.status_changed`.
6. PII: staff phone stored but displayed masked outside their household admin/manager view; incident descriptions escaped everywhere.

## 1. Database (T37 — migration `20261111_000008_create_staff_and_incident_tables.sql`)

**`household_staff`** (blueprint §15): id, uuid UNIQUE, estate_id, household_id, user_id NULL, full_name VARCHAR(180), phone VARCHAR(20), staff_type ENUM('domestic','driver','gardener','nanny','cook','security','other') DEFAULT 'domestic', notes VARCHAR(500) NULL, status ENUM('active','inactive') DEFAULT 'active', created_at, updated_at. UNIQUE(id,estate_id); INDEX(estate_id,status); INDEX(household_id,status); FK estate, composite (household_id,estate_id)→households(id,estate_id); user_id FK→users NULL. (Staff access/invitation flows come later.)

**`access_incidents`** (blueprint §15): id, uuid UNIQUE, estate_id, gate_id NULL, household_id NULL, invitation_id NULL, reported_by_user_id, incident_type ENUM('unauthorised_access','tailgating','lost_pass','suspicious_activity','property_damage','medical','other'), severity ENUM('low','medium','high','critical') DEFAULT 'medium', status ENUM('open','investigating','resolved','dismissed') DEFAULT 'open', summary VARCHAR(180), description TEXT, occurred_at DATETIME(3), resolved_at NULL, resolved_by_user_id NULL, resolution_notes VARCHAR(500) NULL, created_at, updated_at. UNIQUE(id,estate_id); INDEX(estate_id,status,created_at); INDEX(estate_id,severity); INDEX(reported_by_user_id,created_at); INDEX(gate_id,created_at); FK estate, (gate_id,estate_id)→gates nullable, (household_id,estate_id)→households nullable, (invitation_id,estate_id)→visitor_invitations nullable, reported_by/resolved_by→users. All optional FKs nullable and estate-composite where applicable.

**Seed (idempotent, stable UUIDs):** 3 demo staff (Greenview A-001/A-002/B-001: "Demo Housekeeper" domestic, "Demo Gardener" gardener, "Demo Driver" driver, clearly demo phones +25470009xxxx, no user accounts); 3 demo incidents (one open medium suspicious_activity at Main Gate reported by guard John; one investigating high unauthorised_access linked to a history invitation; one resolved low lost_pass for A-001). Re-run zero changes.

## 2. Routes (99 → 118; 19 new, web + API)

**Resident web** (`auth`,`estate`):
| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/app/staff` | StaffController::webIndex | `household.staff.manage` |
| POST | `/app/staff` | StaffController::webStore | `household.staff.manage` |
| POST | `/app/staff/{uuid}/deactivate` | StaffController::webDeactivate | `household.staff.manage` |
| POST | `/app/staff/{uuid}/reactivate` | StaffController::webReactivate | `household.staff.manage` |
| GET | `/app/incidents` | IncidentController::webIndex | `visitor.incident.create` |
| GET | `/app/incidents/new` | IncidentController::webCreate | `visitor.incident.create` |
| POST | `/app/incidents` | IncidentController::webStore | `visitor.incident.create` |

**Guard web** (`/gate`, existing PWA): GET `/gate/staff` (verify staff by phone/name/plate? directory lookup, `household.staff.manage`? no — guard needs a read permission; **decision:** reuse `visitor.incident.create` for guard staff lookup is wrong; add guard staff lookup only if an existing permission fits — `household.view` is held by guards? security_guard holds `household.view`? verify; if not, **skip guard staff screen this increment** and document). Decision: **no guard staff screen in Increment 6** (no suitable seeded permission); staff lookup for guards deferred until permission catalogue update. Guard incidents:
| GET | `/gate/incidents` | GuardIncidentController::index | `visitor.incident.create` |
| GET | `/gate/incidents/new` | GuardIncidentController::create | `visitor.incident.create` |
| POST | `/gate/incidents` | GuardIncidentController::store | `visitor.incident.create` |

**Admin web** (`/admin`, existing shell): GET `/admin/staff` (estate staff directory, `household.staff.manage`); GET `/admin/incidents` (queue/filter `?status=&severity=`, `visitor.incident.manage`); GET `/admin/incidents/{uuid}` (detail, `visitor.incident.manage`); POST `/admin/incidents/{uuid}/status` (open/investigating/resolved/dismissed + resolution notes, `visitor.incident.manage`).

**Resident API** (`/api/v1`, auth+estate): GET/POST `/resident/staff`, POST `/resident/staff/{uuid}/deactivate|reactivate`, GET/POST `/resident/incidents`, GET `/resident/incidents/{uuid}` (own reports).
**Admin API:** GET `/api/v1/admin/incidents`, GET/PATCH `/api/v1/admin/incidents/{uuid}` (status), GET `/api/v1/admin/staff`.
(Count exactly in implementation; contract expects **118** total: 99 + 7 resident web + 3 guard web + 4 admin web + 5 resident API + 3 admin API = 121? Recompute: resident web 7, guard web 3, admin web 4 = 14 web; resident API 5, admin API 3 = 8 → 22 new → 121. **Final target = 121 routes**; tests assert exact list.)

> Route-count note is binding: implement exactly the above 22 routes (GET counts as listed) → total **121**; if any route is merged/omitted, update this contract first.

## 3. Business rules

**Staff (resident, own household only, `household.staff.manage`):** create for a household where caller has an active membership in the active estate; fields: `full_name` (2–180), `phone` (valid E.164/local), `staff_type` allowlist, `notes` ≤500; duplicate active staff by (household, phone) → 422 `STAFF_EXISTS`; deactivate/reactivate status; no hard delete; list shows masked phone; household scoping → foreign household 404; admin (`/admin/staff`) sees estate-wide with masked phones + household label; archived households cannot receive staff (422 `HOUSEHOLD_INACTIVE`); dev SMS/email invite to staff not sent this increment (documented).

**Incidents:** create fields `incident_type` allowlist, `severity` allowlist, `summary` (3–180), `description` (10–2000), `occurred_at` (optional, not future by >5 min, defaults now), optional `gate_uuid` (active estate gate), `household_uuid` (own households for residents; any for guards/admin?), `invitation_uuid` (own invitations for residents), `reported_by_user_id` from context. Guards: gate defaults to session gate; resident: gate optional (estate-level incident). Status workflow: open → investigating → resolved|dismissed; resolved/dismissed terminal (no reopen) unless `visitor.incident.manage` with explicit reopen? **decision:** terminal states cannot be changed (409 `INCIDENT_CLOSED`); resolved requires `resolution_notes` (10–500); all transitions audit `incident.status_changed` (old→new). List visibility: residents see only incidents they reported (or their household-linked); guards see estate incidents they reported + gate context? (keep simple: guards see own reports); admins/managers with `visitor.incident.manage` see all estate incidents filtered. Foreign uuid 404. Escaping everywhere.
**Audit:** exact actions above; no description/name/phone in metadata (only uuids/type/severity/status).
**Email alert (Increment-5 infra):** optional: on incident create with severity high|critical, send `incident.reported` security alert via `EmailAlertService` (post-commit, never blocks creation; audit failures). Add template; if T33 wiring pattern allows. Include estate, severity/type, summary (no PII) — recipients: `MAIL_ALERT_TO` + estate admins with email. Document.

## 4. Views (pwa-shell)

- Resident: `app/Views/app/staff.php` (list/add/deactivate/reactivate, masked phone, empty state), `app/Views/app/incidents.php` (list own reports + status chips, empty), `app/Views/app/incident.php`/form (report incident: type/severity/summary/description/occurred_at/gate/household optional).
- Guard: `app/Views/guard/incidents.php` (list own reports), `guard/incident.php` (report form; gate from session; no staff screen).
- Admin: `admin/staff.php` (estate directory + household links), `admin/incidents.php` (filters status/severity, table), `admin/incident.php` (detail + status form + resolution notes + audit trail snippet optional).
- Nav: resident Home quick tile "Staff" (or account section) + "Report incident" entry in account/visitors area; guard dashboard incident link; admin nav "Staff" and "Incidents" (permission-filtered). Update `layouts/app.php`, `layouts/guard.php`, `layouts/admin.php`, CSS.
- All screens: honest empty states, e() escaping, CSRF, errors/old, single h1, root-relative, CSP-safe, 44px, reduced-motion, dark/light parity, config product name.

## 5. Tests (T39)

Unit: staff phone/name validation; incident validation (occurred_at not future, summary/description lengths); status transition guard.
Integration: staff create/list/deactivate/reactivate own household; foreign household 404; duplicate 422 STAFF_EXISTS; archived household 422 HOUSEHOLD_INACTIVE; admin estate-wide staff list scoped (David↔Greenview 404); resident only own; guard has no staff access (403/404). Incidents: resident create own → list only own; guard create with session gate; admin list filters; status transitions incl. resolution notes required, terminal state 409 INCIDENT_CLOSED, foreign uuid 404, tenant isolation; audit actions exact + metadata PII-free; optional high/critical email alert via LogMailProvider (mail.log + audit) non-blocking; no hard deletes (row counts stable, statuses only); CSRF on all new POSTs; XSS on description/summary/staff name/notes rendered in resident, guard, admin screens; route table exact **121**; existing suite green; README Increment 6 section (staff directory, incidents, demo fixtures, routes, permissions, limitations: no staff accounts/invites, no photo evidence, no guard staff directory, platform/billing next).

## 6. Acceptance (T40 gate)

1. Migration 8 applies clean (fresh rebuild 7 migrations / 36 tables), idempotent seed; T30-style no-schema-inconsistency check; install twice.
2. Full suite green (current + new), routes exactly 121.
3. Live acceptance on 8010: Jane creates staff (masked phone, deactivate/reactivate); Jane reports incident → list shows own; guard John reports gate incident; Grace sees admin queue, filters, moves open→investigating→resolved with notes, terminal guard; David sees only Riverside; resident cannot see others' incidents; high-severity incident emits `mail.log` alert + audit when MAIL_ALERT_TO configured; CSRF/XSS spot checks; no hard deletes.
4. README + deployment doc consistent; acceptance doc recorded; next increment = notices/announcements + notification centre.
