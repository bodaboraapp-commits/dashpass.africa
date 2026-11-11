# MtaaPass/DashPASS — Increment 7: Estate Notices / Announcements — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation/visitor/vehicles/parking/admin/staff-incident contracts; expected state after Inc-6: 121 routes, 7 migrations, 36 tables, role links 204.
**Product name:** config-driven (dev MtaaPass, prod DashPASS).
**Blueprint:** R14 Estate Notices / announcements, admin notices composer, home announcement feed.
**Goal:** replace the Home-screen demo announcements with **real estate notices**: admins compose/publish/archive notices per estate; residents see published notices on Home and a full Notices list/detail; guards can read published notices.

## 0. Scope and rules
In scope: migration 9 (`estate_notices`), permissions `notice.view`/`notice.manage`, admin notices CRUD + publish/archive, resident notices list/detail, real Home announcements (published, unexpired), guard notices list (read-only), demo seed, routes (121 → **131**), tests, README.

Out of scope: push notifications/email fan-out for notices (later; email infrastructure exists), rich text/attachments, per-household targeting, read receipts, scheduling (publish now / publish_at later deferred).

**No hard deletes:** notices have `status` draft|published|archived; archive only. Tenant-scoped via `Context`; foreign uuid → 404. Every POST CSRF+validation+audit (`notice.created`, `notice.updated`, `notice.published`, `notice.archived`); metadata uuids/keys only, no PII. No inline JS/CSS.

## 1. Database (T52 — migration `20261111_000009_create_estate_notices_table.sql`)
**`estate_notices`**: id, uuid UNIQUE, estate_id, title VARCHAR(160), body TEXT, category ENUM('general','security','maintenance','event','emergency') DEFAULT 'general', priority ENUM('normal','important','urgent') DEFAULT 'normal', status ENUM('draft','published','archived') DEFAULT 'draft', audience ENUM('all','residents','staff') DEFAULT 'all', published_at DATETIME(3) NULL, expires_at DATETIME(3) NULL, created_by_user_id, updated_by_user_id NULL, created_at, updated_at. UNIQUE(id,estate_id); INDEX(estate_id,status,published_at), INDEX(estate_id,category); FK estate, created_by/updated_by→users, composite tenant consistency.

**Permissions**: add `notice.view` ("View estate notices") and `notice.manage` ("Manage estate notices") to `PermissionsSeeder`; role grants: `notice.view` → resident, tenant, household_member, security_guard, security_supervisor, parking_manager, auditor, estate_admin, estate_manager (9 roles); `notice.manage` → estate_admin, estate_manager, platform_super_admin (3). **Role links 204 → 217.** No other role changes.

**Seed (idempotent, stable UUIDs)**: 4 Greenview notices — 2 published (`general` "Water supply maintenance on Saturday" normal, `security` "Main gate barrier upgrade" important), 1 published `emergency` "Lift maintenance — Block B" urgent with expires_at in 7 days, 1 draft "Upcoming community clean-up". No Riverside notices (empty-state demo). Re-run zero changes.

## 2. Routes (121 → **131**; 9 new web, no APIs this increment)
**Resident web** (`auth`,`estate`,`permission:notice.view`): GET `/app/notices` (list published, audience all/residents, unexpired), GET `/app/notices/{uuid}` (detail, foreign/unpublished → 404).
**Guard web** (`/gate`, same permission): GET `/gate/notices` (published list, audience all/staff) — read-only.
**Admin web** (`/admin` + permission:notice.manage; admin-area gate applies): GET `/admin/notices`, GET `/admin/notices/new`, POST `/admin/notices`, GET `/admin/notices/{uuid}`, POST `/admin/notices/{uuid}`, POST `/admin/notices/{uuid}/publish`, POST `/admin/notices/{uuid}/archive`. (7 routes.)

Total new = 2 + 1 + 7 = 10 → **131**? Recompute: resident 2, guard 1, admin 7 = 10 → 121+10 = **131**. **Binding target = 131 routes** (tests assert exact list). If a route is merged/omitted, update this contract first.

## 3. Business rules
- **Create** (admin): title 3–160, body 10–2000, category/priority/audience enum, optional `expires_at` (future only, ≥ now+5 min), status draft by default; `publish` sets status published + published_at=now (idempotent: publishing published → 409 `NOTICE_ALREADY_PUBLISHED`; archived → 409 `NOTICE_CLOSED`). `archive` sets archived (published or draft allowed); archived notices cannot be edited/published (409).
- **Update**: only draft notices editable (409 `NOTICE_LOCKED` once published; allow editing published? **decision:** published notices are immutable except archive — safer for audit; admins create a new notice).
- **Resident visibility**: status published AND (audience all or residents) AND (expires_at null OR expires_at > now), ordered priority desc (urgent>important>normal) then published_at desc. Detail: same visibility, else 404.
- **Guard visibility**: same but audience all or staff.
- **Home feed**: resident Home `announcements` = first 3 visible notices (title, body excerpt ≤140, category/priority chip, published_at) replacing demo preview; keep the "Layout preview" removal/honest empty state; link CTA to `/app/notices`.
- **Admin queue**: filter by status/category/priority; show draft/published counts; real stats.
- **Audit**: exact actions above; no title/body in metadata (only uuid/category/priority/status).
- **Validation errors**: 422 `VALIDATION_FAILED` with field errors; web forms PRG 302 + flash; all POSTs CSRF.
- **XSS**: every rendered title/body escaped; body line breaks preserved via `nl2br(e(...))`.

## 4. Views (pwa-shell)
- Resident: `app/Views/app/notices.php` (list, priority/category chips, published date, excerpt, detail links, empty state), `app/Views/app/notice.php` (detail: title/body/category/priority/published/expiry, back link).
- Guard: `app/Views/guard/notices.php` (read-only list with chips, empty state, link from dashboard).
- Admin: `admin/notices.php` (queue + filters + add CTA), `admin/notice-form.php` (create/edit form: title/body/category/priority/audience/expires_at, errors/old, CSRF, actions), `admin/notice.php` (detail with publish/archive actions + status timeline).
- Home: real announcements section from `announcements` data (no preview chip when UI_PREVIEW_CONTENT=false), dots pagination optional (static if >1), empty state honest.
- Nav: resident Home/account link to Notices; guard dashboard link; admin nav "Notices" permission-filtered.
- Reuse design system, single h1, CSP-safe, root-relative, 44px, reduced motion, config product name.

## 5. Tests (T55 — qa-mail pre-writes; tests agent folds in)
- Permissions: notice.view roles can read; others 403; notice.manage admin only; resident cannot POST admin notices.
- Admin: create draft (audit), publish (published_at set, audit), duplicate publish 409 NOTICE_ALREADY_PUBLISHED, archive, edit draft, edit published 409 NOTICE_LOCKED, validation (title/body/category/priority/audience/expires_at past), filters, tenant isolation (David↔Greenview 404/no leak), CSRF, XSS, no hard deletes (row counts stable).
- Resident: list only published+audience+unexpired, priority ordering, draft/archived/foreign 404, guard sees audience staff/all only, expired hidden.
- Home: announcements feed shows real published notices (no demo preview when UI_PREVIEW_CONTENT=false), ≤3, empty state.
- Migration 9 expectations: **8 migrations / 37 tables**, role links **217**, seed 4 notices, idempotent; route count **131** exact.
- README Inc-7 section.

## 6. Tasks
| Task | Agent | Scope |
|---|---|---|
| T52 stage migration 9 + seed + permission/role patch | schema-seed | `storage/temporary/inc7/` (apply only on Lead DB gate) |
| T53 backend services/controllers (no routes until gate) | modules | `app/Modules/Notices/**` |
| T54 views phase 1 | pwa-shell | `app/Views/app/notices.php`, `notice.php`, `guard/notices.php`, `admin/notices*.php`, home announcements markup, CSS |
| T55 tests pre-written | qa-mail | new files under tests/ |
| T56 harness lock fix (T50) | qa-inc6 | `tests/run.php` stable lock |
| T57 E2E harness + production deploy verification | qa-e2e | `storage/temporary/` + report |
| T58 release/deploy bundle refresh | git-release | zip bundle + docs |
| T59 Lead gate | Lead | full suite + live acceptance + acceptance doc |

## 7. Acceptance (T59)
1. Migration 9 applies clean (fresh = 8 migrations/37 tables, role links 217, notices seed 4, idempotent).
2. Full suite green; routes exactly 131.
3. Live: Grace creates draft → publishes → resident Jane sees it on Home + /app/notices + detail; guard John sees it; draft hidden from residents; archive hides it; David sees no Greenview notices; expired notice hidden; CSRF/XSS spot checks; no hard deletes.
4. README/docs consistent; next increment = platform super-admin/billing/reports or parking admin (decide in gate).
