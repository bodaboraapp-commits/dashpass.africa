# MtaaPass — Visitor & Access Module v1 (Increment 2) — binding spec

**Status:** binding for this increment. Read fully before writing code.
**Base:** [`docs/foundation-contract.md`](foundation-contract.md) (security rules, conventions) and [`docs/resident-pwa-layout-addendum.md`](resident-pwa-layout-addendum.md) (resident PWA design). This spec supersedes the "preview" behaviour of the Visitors screen; Bookings/announcements preview stays until their modules land.
**Blueprint references:** §22 (invitation workflow), §23 (gate check-in), §24 (checkout), §25 (walk-in), §28–§31 (SMS/QR/notifications), §33–§35 (API), §41–§43 (security), §45 (audit), §58 (vertical workflow).
**Goal:** build the complete vertical visitor workflow end to end: resident creates an invitation → visitor receives PIN + pass link (dev SMS) → guard verifies at gate → check-in → checkout → audit + notifications. Replace the Visitors preview with real data, and add the guard PWA needed to operate it.

## 0. Scope

In scope: visitor records (estate-scoped), invitations, PIN + QR credentials, resident Visitors/Invite/Pass screens, public visitor pass page, guard PWA (gate selection, verify, check-in, checkout, active list, walk-in creation), resident walk-in approvals, dev SMS notifications, audit trail, tests, README.

Out of scope (next increments): vehicles/parking, payments, notices module, incidents module, camera QR scanning (guard enters PIN or pastes the pass code in v1), offline queue, real SMS provider, estate/household admin management (Task 002), manual override.

## 1. Lead-owned additions (already in tree)

- `App\Core\Crypto::encrypt($plaintext, $context)` / `Crypto::decrypt($payload, $context)` — AES-256-GCM, context-bound; used only for the QR token (needs re-display), never for the PIN.
- `config/visitors.php` — invitation/verification/pass/walk-in defaults and limits.
- Boolean env flags now use `Env::bool()`; `UI_PREVIEW_CONTENT=false` truly disables preview rows.

## 2. Database (T11 — migration `20261111_000006_create_visitor_access_tables.sql`)

Conventions from the foundation contract §4 apply (InnoDB, utf8mb4, BIGINT ids, uuid public ids, DATETIME(3) UTC, composite tenant FKs, indexed FKs). Exact tables:

**`visitors`** — id, uuid UNIQUE, estate_id, name VARCHAR(180), phone VARCHAR(20), photo_path VARCHAR(500) NULL, created_at. UNIQUE(id, estate_id); FK estate_id→estates; INDEX(estate_id, phone). Visitors are estate-scoped (no cross-estate identity correlation).

**`visitor_invitations`** — id, uuid UNIQUE, estate_id, household_id, host_user_id, visitor_id, purpose VARCHAR(120) NULL, visitor_count SMALLINT UNSIGNED DEFAULT 1, valid_from DATETIME(3), valid_until DATETIME(3), status ENUM('pending','approved','checked_in','checked_out','revoked','expired','denied') DEFAULT 'approved', created_at, updated_at. UNIQUE(id, estate_id); FK estate, host_user_id→users, visitor_id→visitors, (household_id,estate_id)→households(id,estate_id); INDEX(estate_id,status,valid_from,valid_until), INDEX(host_user_id,created_at), INDEX(household_id,created_at).

**`access_credentials`** — id, uuid UNIQUE, estate_id, invitation_id, credential_hash CHAR(64) UNIQUE, credential_type ENUM('pin','qr'), encrypted_secret TEXT NULL, expires_at DATETIME(3), max_uses SMALLINT UNSIGNED DEFAULT 1, used_count SMALLINT UNSIGNED DEFAULT 0, status ENUM('active','used','revoked','expired') DEFAULT 'active', created_at, revoked_at NULL. FK (invitation_id,estate_id)→visitor_invitations(id,estate_id); INDEX(invitation_id,status,credential_type).

**`visitor_access_events`** — id, uuid UNIQUE, event_uuid CHAR(36) UNIQUE, estate_id, invitation_id, gate_id, event_type ENUM('check_in','check_out','denied','manual_override'), actor_id, occurred_at DATETIME(3), source ENUM('online','offline_sync') DEFAULT 'online', metadata JSON NULL, created_at. FK estate, actor_id→users, (invitation_id,estate_id)→visitor_invitations, (gate_id,estate_id)→gates; INDEX(estate_id,occurred_at), INDEX(invitation_id,occurred_at).

**`walk_in_requests`** — id, uuid UNIQUE, estate_id, household_id, gate_id, requested_by_user_id, visitor_name VARCHAR(180), visitor_phone VARCHAR(20), purpose VARCHAR(120) NULL, status ENUM('pending','approved','rejected','expired') DEFAULT 'pending', invitation_id NULL, responded_by_user_id NULL, responded_at NULL, created_at, updated_at. FK estate, (household_id,estate_id)→households(id,estate_id), (gate_id,estate_id)→gates, invitation_id→visitor_invitations; INDEX(estate_id,status,created_at), INDEX(household_id,status).

**`access_verification_attempts`** — id, estate_id, gate_id NULL, credential_type ENUM('pin','qr'), credential_hash CHAR(64) NULL, success TINYINT(1), ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at. INDEX(estate_id,created_at), INDEX(gate_id,created_at).

**Seed demo data (T11, idempotent, clearly demo):**
For Jane Smith (+254700000010, household A-001, Greenview): 2 history invitations (`checked_out`, occurred 3 days and 9 days ago, visitors "Demo Visitor One"/"Demo Visitor Two"), 1 upcoming approved invitation valid tomorrow 10:00–14:00 for visitor "Demo Visitor Three" (+254700090003) with a PIN credential whose plaintext demo PIN is documented in the README, and 1 pending walk-in request from gate Main Gate for visitor "Demo Walk-in Four" (+254700090004). No live credential secrets in code comments/logs beyond the documented demo PIN. Also seed one checked-in-visit fixture for guard dashboard screenshots (status checked_in, checked in today). Keep all seed values stable and idempotent (lookup by uuid in seeder constants).

## 2.1 Seed role amendment (approved)

Walk-in approvals are a resident action (Blueprint §25: the resident approves or rejects). The foundation role mapping predates this module, so the seeded `resident`, `tenant` and `household_member` roles gain two permissions in this increment:

- `resident` → **+** `visitor.walkin.approve`, `visitor.walkin.reject`
- `tenant` → **+** `visitor.walkin.approve`, `visitor.walkin.reject`
- `household_member` → **+** `visitor.walkin.approve`, `visitor.walkin.reject`

No other role mapping changes. `security_supervisor` keeps `visitor.walkin.approve` (and does not gain `reject`, which stays a household decision). `RoleSeeder` must apply these additions idempotently; role→permission counts change accordingly (tests and README must reflect the new counts). All other seeded role mappings stay exactly as in the foundation contract.

## 3. Credential design (security-critical)

- **PIN** (6 digits, `config('visitors.invitation.default_pin_length')`): `credential_hash = Crypto::hmac('access-pin:' . $credentialUuid, $pin)`. Never stored in plaintext; returned to the resident **once** at creation and on PIN rotation only (`data.pin`), plus shown in a dev-only flash after web creation. No endpoint ever re-reveals an existing PIN; residents rotate to get a new one.
- **QR pass token**: 32 random bytes hex (64 chars). `credential_hash = Crypto::hmac('access-qr:' . $credentialUuid, $token)`; `encrypted_secret = Crypto::encrypt($token, 'access-qr:' . $credentialUuid)` so the resident can re-display the QR/pass link later. Pass URL = `absolute_url('/v/' . $token)`.
- **QR image**: server-rendered PNG at `GET /app/passes/{uuid}/qr.png` (resident-authenticated, `Cache-Control: no-store`), generated by a small **pure-PHP** QR encoder in `App\Modules\Visitors\QrCode` (byte mode, EC level M, auto version, smallest penalty mask) → GD PNG. No third-party library shipped. Validate the encoder against reference matrices produced with a temporary, unshipped JS reference during development; add a unit test with fixed vectors.
- Credential rows: `expires_at = invitation.valid_until`; `max_uses` default 1; on successful check-in `used_count` is incremented and status becomes `used` when `used_count >= max_uses` (atomic, locked).
- QR/pass public page never shows the PIN, the resident's phone, email or user id.

## 4. Business behaviour (T12 — exact)

### 4.1 Invitation creation (resident)
Input (web form + API): `visitor_name` (required, 2–180), `visitor_phone` (required, normalised E.164), `household_uuid` (required; must be a household where the caller has an active `household_members` row in the active estate), `purpose` (optional, ≤120), `valid_from` (optional ISO-8601 or `Y-m-d\TH:i` form value; default now), `valid_until` (optional; default valid_from + estate setting `visitor_validity_minutes` (fallback `config('visitors.invitation.default_validity_minutes')`)), `visitor_count` (optional 1–10).

Rules: `valid_from < valid_until`; `valid_until` in the future; window ≤ `visitors.invitation.max_validity_hours` (422 `VALIDITY_INVALID`); household not accessible → 404 `HOUSEHOLD_NOT_FOUND` (no enumeration); rate limit create per user `visitor:create:user:{id}` 10/600s → 429.

Transaction: find-or-create `visitors` by (estate_id, phone) [update name if changed] → insert invitation (status `approved`) → insert PIN credential → insert QR credential → audit `visitor.invitation.created` → commit. **After commit** send dev SMS to visitor:
`MtaaPass: You are invited to visit {estate_name}, {household_label}. Your access PIN is {pin}. Valid {date} {start}-{end}. Show this code at the gate. Pass: {pass_url}`
and log to `notification_logs` (template `visitor_invitation`). SMS failure logs and never rolls back.

Return data: `{invitation_uuid, visitor:{name, phone}, pin, pass_url, qr_url, valid_from, valid_until, status}`.

### 4.2 Resident lists
`upcoming`: invitations with status `approved` (and `checked_in` shown as "Inside") ordered by `valid_from ASC`, scoped to households the user belongs to in the active estate. `history`: `checked_out`, `revoked`, `expired`, `denied` ordered `valid_until DESC`, limit 50. Plus `pending_walkins` for the user's households (status pending, newest first). Cross-tenant/foreign-household rows are never returned (404 on direct uuid access).

### 4.3 Revoke / extend / rotate PIN
- Revoke: allowed for host or any active member of the invitation's household. Status `approved`→`revoked`; `checked_in`→ stays `checked_in` (visit remains manageable) but **all credentials are revoked** so re-entry is impossible; `checked_out`/`revoked`→ 409 `INVITATION_NOT_ACTIVE`. Audit `visitor.invitation.revoked`.
- Extend: `{minutes}` 15–1440; only `approved` or `checked_in`; new `valid_until = max(valid_until, now) + minutes`; must stay within max window from valid_from; update credential `expires_at` too. Audit `visitor.invitation.extended`.
- Rotate PIN: only `approved`; revoke existing PIN credentials (`status='revoked'`, `revoked_at=now`), issue a new PIN credential, return plaintext `pin` once. QR unchanged. Audit `visitor.invitation.pin_rotated`.

### 4.4 Verification (guard)
Guard must be authenticated, in an estate context, hold `visitor.checkin`, and have a **selected gate** (`Session::get('guard_gate_id')`) that is an active `guard_gate_assignments` row for them in this estate. No gate selected → 409 `GATE_CONTEXT_REQUIRED`; gate not assigned → 403 `GATE_NOT_ASSIGNED`.
- `POST verify-pin {pin}`: rate limits `visitor:verify:credential:{hash}` (5/300s) and `visitor:verify:ip:{ip}` (30/300s) → 429 with `retry_after`. Look up `access_credentials` by HMAC hash (join invitation). Record `access_verification_attempts` (success bool, hash, gate, ip, UA) in **both** success and failure cases.
- `POST verify-code {code}`: `code` may be the 64-char token or a full pass URL containing `/v/{token}`; validate HMAC hash.
- Validation: credential active + unexpired; invitation status `approved` (or `checked_in` → return `already_inside=true` for display, check-in still 409); estate matches guard estate; gate estate matches. Invalid → 422 `INVALID_CREDENTIAL` (generic message, never reveals which check failed). Success returns `{credential_uuid, invitation_uuid, visitor_name, household_label, purpose, valid_from, valid_until, status, already_inside}`.

### 4.5 Check-in / checkout (transactional)
`POST checkin {invitation_uuid}` (guard + gate as above): in one transaction, `SELECT ... FOR UPDATE` the invitation; require status `approved` (else 409 `ALREADY_INSIDE` if `checked_in`, 409 `INVITATION_NOT_ACTIVE` otherwise), `valid_from <= now <= valid_until` (else 422 `OUTSIDE_VALIDITY`), same estate, gate belongs to estate; lock the presented credential row (if supplied) and require `status='active'`, `used_count < max_uses`, increment `used_count`, set `used` when exhausted; set invitation `checked_in`; insert `visitor_access_events` (`check_in`, actor=guard, gate, occurred_at=now, source=online); audit `visitor.checked_in`. Commit. Then dev SMS to host: `{visitor_name} has entered {estate_name} at {time}.` Never SMS inside the transaction.
`POST checkout {invitation_uuid}`: lock; require `checked_in` (else 409 `NOT_INSIDE`); set `checked_out`; insert event; audit `visitor.checked_out`; commit; dev SMS to host `{visitor_name} departed {estate_name} at {time}.`
Concurrency invariant: two simultaneous check-ins for one invitation produce exactly one success; the other gets 409. Same for checkout. Credential replay after check-in → 409 `ALREADY_INSIDE` (not a second visit).

### 4.6 Walk-in flow
Guard `POST /api/v1/gate/walkins` `{visitor_name, visitor_phone, household_uuid, purpose?}` → creates `walk_in_requests` status `pending` (guard's selected gate; household must be in the guard's estate); dev SMS to all active household members of that household: `MtaaPass: {visitor_name} is at {gate_name} and requests access to {household_label}. Approve in MtaaPass.`; audit `visitor.walkin.created`. Never auto-admit.
Resident (member of that household) `POST approve`: create invitation + credentials exactly like §4.1 (validity 2h, purpose from request), link request `invitation_id`, status `approved`, `responded_by_user_id`, `responded_at`; dev SMS visitor with PIN + pass URL; audit `visitor.walkin.approved`. `POST reject`: status `rejected`; audit `visitor.walkin.rejected`; no credentials created.
Pending requests older than `WALKIN_APPROVAL_TIMEOUT_SECONDS` may be shown as "awaiting response" but must **not** expire into approval; expiry job is a later increment.

### 4.7 Public visitor pass page
`GET /v/{token}` (no auth): rate limit `pass:page:ip:{ip}` (60/300s) → 429 page; hash the token and find the QR credential; render a branded guest page showing estate name, visitor name, household label, validity window, status chip, and instructions. Invalid/unknown token → 404 page with a neutral message. Expired/revoked/checked-out → pass page shows the correct status (expired/used/revoked) without any operational data. Never show PIN, resident phone, email, user id, host user id, or other visitors. `Referrer-Policy` already set; add no-store caching (global security headers already do this).

## 5. Routes (T12)

Resident web (all `auth`, `estate`, CSRF on POST):
| Method | Path | Handler | Extra middleware |
|---|---|---|---|
| GET | `/app/visitors` | `ResidentController::webVisitors` | `permission:visitor.invitation.create` |
| GET | `/app/visitors/invite` | `ResidentController::webInvite` | `permission:visitor.invitation.create` |
| POST | `/app/visitors/invite` | `ResidentController::webStoreInvitation` | `permission:visitor.invitation.create`, `throttle:invite,20,600` |
| GET | `/app/passes/{uuid}` | `ResidentController::webPass` | `permission:visitor.invitation.view_own` |
| GET | `/app/passes/{uuid}/qr.png` | `ResidentController::webPassQr` | `permission:visitor.invitation.view_own` |
| POST | `/app/passes/{uuid}/revoke` | `ResidentController::webRevoke` | `permission:visitor.invitation.revoke` |
| POST | `/app/passes/{uuid}/extend` | `ResidentController::webExtend` | `permission:visitor.invitation.extend` |
| POST | `/app/passes/{uuid}/rotate-pin` | `ResidentController::webRotatePin` | `permission:visitor.invitation.create` |
| POST | `/app/walkins/{uuid}/approve` | `ResidentController::webApproveWalkIn` | `permission:visitor.walkin.approve` |
| POST | `/app/walkins/{uuid}/reject` | `ResidentController::webRejectWalkIn` | `permission:visitor.walkin.reject` |

Public: `GET /v/{token}` → `VisitorPassController::show` (no auth; throttle inside service).

Resident API (`/api/v1`, auth+estate, permission middleware as noted):
`GET /resident/invitations?tab=upcoming|history` (visitor.invitation.view_own), `POST /resident/invitations` (create), `GET /resident/invitations/{uuid}`, `POST /resident/invitations/{uuid}/revoke|extend|rotate-pin`, `POST /resident/walkins/{uuid}/approve|reject`.

Guard web (`auth`, `estate`):
`GET /gate` (gate.view), `POST /gate/select-gate` (gate.view), `GET /gate/verify` (visitor.checkin), `POST /gate/verify` (noscript form; visitor.checkin), `POST /gate/checkin` (noscript form; visitor.checkin), `POST /gate/checkout` (noscript form; visitor.checkout), `GET /gate/active` (visitor.checkin), `GET /gate/walkin` (visitor.walkin.create), `POST /gate/walkin` (noscript form; walkin.create).

Guard API: `GET /api/v1/gate/dashboard`, `POST /api/v1/gate/verify-pin`, `POST /api/v1/gate/verify-code`, `POST /api/v1/gate/visitors/checkin`, `POST /api/v1/gate/visitors/checkout`, `GET /api/v1/gate/active-visitors`, `GET /api/v1/gate/pending-approvals`, `POST /api/v1/gate/walkins` — all with the same permission middleware as the corresponding web screen.

Guard landing: after login, users whose permissions include `visitor.checkin` but not `estate.view` (i.e. pure guards) are redirected from `/app` to `/gate` (server-side redirect in `ResidentController::webHome` or auth redirect logic); guard PWA does not depend on the resident shell. Guard permission checks remain the source of truth.

## 6. Response payloads (T12 → T13/T14/T15)

`POST /api/v1/resident/invitations` data: `{invitation_uuid, pin, pass_url, qr_url, valid_from, valid_until, visitor:{name,phone}}`.
`GET /api/v1/resident/invitations` data: `{tab, upcoming:[Item], history:[Item], pending_walkins:[WalkIn]}` where
`Item = {uuid, visitor_name, purpose, valid_from, valid_until, status, status_label, status_tone, household_label, already_inside}` and `WalkIn = {uuid, visitor_name, purpose, requested_at, gate_name, household_label}`.
`POST rotate-pin` data: `{pin}`. `POST revoke` data: `{status:"revoked"}`. `POST extend {minutes}` data: `{valid_until}`.
`POST approve` data: `{status:"approved", invitation_uuid, pin?}` (PIN included only in dev/expose mode), `POST reject` data: `{status:"rejected"}`.
Guard `verify-pin`/`verify-code` data: `{credential_uuid, invitation_uuid, visitor_name, household_label, purpose, valid_from, valid_until, status, already_inside}`.
`checkin` data: `{status:"checked_in", occurred_at}`; `checkout`: `{status:"checked_out"}`. Guard walk-in create (`POST /api/v1/gate/walkins`) data: `{uuid, visitor_name, purpose, requested_at, gate_name, household_label, status:"pending"}` (the presented walk-in shape — use `uuid` as the request identifier; the resident approve/reject endpoints take that uuid in the path). Dashboard data: `{gates:[{uuid,name,assigned}], selected_gate:{uuid,name}|null, stats:{inside,expected,pending_walkins}, active:[Item], pending_walkins:[WalkIn]}`.
Error codes introduced: `VALIDITY_INVALID` (422), `HOUSEHOLD_NOT_FOUND` (404), `INVITATION_NOT_FOUND` (404), `INVITATION_NOT_ACTIVE` (409), `INVALID_CREDENTIAL` (422), `OUTSIDE_VALIDITY` (422), `ALREADY_INSIDE` (409), `NOT_INSIDE` (409), `GATE_NOT_ASSIGNED` (403), `GATE_CONTEXT_REQUIRED` (409), `PASS_INVALID` (404 page), `WALKIN_NOT_PENDING` (409), `RATE_LIMITED` (429).

## 7. Views (T13 resident / T14 guard)

T13 (pwa-shell, keep layout/design-system ownership):
- `app/Views/app/visitors.php` rebuilt with real data: tabs Upcoming/History (server-side links), pending-approvals section (approve/reject forms), visitor cards with status chip and actions (View pass, Revoke), empty states, CTA `+ Invite Visitor` → `/app/visitors/invite`. **Remove `Layout preview` chip/rows from Visitors** (no preview flag there anymore).
- `app/Views/app/invite.php`: quick invite form (visitor name, phone, household select, purpose optional, valid from/until datetime-local with sensible defaults, visitor count), inline errors, submit; shows dev SMS hint when `isDev`.
- `app/Views/app/pass.php`: pass screen with status, validity, visitor/host/household, PIN card (shows `pin_display` once when present, otherwise "PIN sent by SMS — rotate to reveal a new one" + rotate form), server-rendered QR `<img src="{qr_url}" alt="Access QR code">`, pass URL + copy button, revoke/extend forms.
- `app/Views/public/pass.php`: standalone guest-layout public pass page `/v/{token}` (no auth) with status/validity/instructions; neutral error states; no credentials.
- Update Home: `Access / My QR` tile → `/app/passes/latest`? Keep the QR sheet but populate it with the resident's next upcoming pass when one exists (`data-next-pass`), otherwise the existing "arrives with the Access module" note becomes "No active visitor pass". `Visitors / Pre-Register` tile → `/app/visitors/invite` (or `/app/visitors` with CTA). `Vehicles`, `Payments`, `Notices` stay Soon. Bottom-nav QR FAB opens the same sheet.
- CSS/JS additions for the above; keep CSP-safe, `e()` escaping, root-relative URLs, no inline script/style, 44px targets, single h1.

T14 (schema-seed, disjoint guard files):
- `app/Views/layouts/guard.php` — guard shell: dark high-contrast, gate name in header, nav (Check-in, Active, Walk-in, Logout), link to `public/assets/css/guard.css` + `public/assets/css/app.css` + `app/assets/js/guard.js` (base tokens reused, guard-specific styles separate).
- `app/Views/guard/dashboard.php` (gate selection when none, stats, active list, pending walk-ins), `guard/verify.php` (PIN keypad form + paste pass code, result panel with check-in button, noscript fallback POST), `guard/active.php` (list + checkout buttons), `guard/walkin.php` (create walk-in form + pending list).
- `public/assets/css/guard.css`, `public/assets/js/guard.js` — vanilla, CSP-safe, no inline handlers; JS uses `MtaaPass.api` from app.js if loaded (include app.js on guard layout; it is safe for any authenticated page).
- Must work at 360px width, large touch targets (guard-first: PIN keypad ≥ 56px buttons, minimal typing), clear status states (APPROVED/DENIED/PENDING/INSIDE/EXPIRED as text + colour).

## 7.1 Canonical view data (final — controllers and views must match exactly)

Resident Home (`app/Views/app/home.php`), in addition to the existing shell/dashboard keys:
`next_pass` = `null` | `{invitation_uuid, visitor_name, pass_url, valid_until, status, status_label, status_tone, household_label}` — populates the QR sheet / Access tile.
(`preview`, `announcements`, `dashboard` stay as today.)

Resident Visitors (`app/Views/app/visitors.php`):
`visitors_tab` (`'upcoming'|'history'`), `upcoming` = `[Item]`, `history` = `[Item]`, `pending_walkins` = `[WalkIn]`. Views select the active list from `visitors_tab`; do **not** use `visitors_upcoming`/`visitors_history`/`visitors_rows` key names.
`Item = {uuid, visitor_name, purpose, valid_from, valid_until, status, status_label, status_tone, household_label, already_inside}` (ISO strings for dates; `status_tone` ∈ `ok|warn|danger|neutral`).
`WalkIn = {uuid, visitor_name, purpose, requested_at, gate_name, household_label}`.

Resident Invite (`app/Views/app/invite.php`): `households` = `[{uuid, label, unit_number, building_name, relationship, is_primary}]`, `default_valid_from`, `default_valid_until` (`'Y-m-d\TH:i'` estate-local), `max_visitor_count` (int), `estate_timezone` (string), `errors` (map field→[messages]), `old` (map), `isDev` (bool), `csrfToken` from shell.

Resident Pass (`app/Views/app/pass.php`): `pass` = `Item` + `{estate_name, visitor_count, host_label, created_at}`, `pin_display` (string|null — one-time dev/admin reveal after create or rotate), `qr_url` (root-relative), `pass_url` (absolute or root-relative), `isDev`, `errors` (map), shell keys.

Public pass (`app/Views/public/pass.php`): `pass` = `null` | `{estate_name, visitor_name, household_label, valid_from, valid_until, status, status_label, status_tone, instructions}`, `invalid` (bool — true renders the neutral 404 state, HTTP 404). No credentials, no resident PII.

Guard shell (`app/Views/layouts/guard.php`): `title`, `user` (`{full_name, phone}`), `gate` (selected gate name|null), `selected_gate` (`{uuid,name}|null`), `gates` (`[{uuid,name,assigned}]`), `csrfToken`, `activeNav` (`dashboard|verify|active|walkin`), `flashError`, `flashSuccess`, `appVersion`, `isDev`.
Guard views: `guard/dashboard.php` = shell + `stats` (`{inside, expected, pending_walkins}`), `active` `[Item]`, `pending_walkins` `[WalkIn]`. `guard/verify.php` = shell + `result` (null | §6 verify payload), `error` (string|null), `prefill_code` (string|null). `guard/active.php` = shell + `active` `[Item]`. `guard/walkin.php` = shell + `households` (`[{uuid,label,unit_number,building_name}]`), `pending_walkins` `[WalkIn]`, `errors`, `old`.

Web POST controllers redirect back with flash keys `flashError` / `flashSuccess` (rendered by the shells), and also accept plain noscript form posts.

QR endpoint: `GET /app/passes/{uuid}/qr.png` (invitation uuid) → `image/png`, `Cache-Control: no-store`, resident-authenticated. Route total after this increment: **60** (25 existing + 9 resident web + 1 public + 8 resident API + 9 guard web + 8 guard API; `/app/visitors` is updated in place, not new).

## 8. SMS + audit + notifications

Templates (dev adapter, `LogSmsProvider` redacts PIN/token in logs; the PIN reaches the resident UI via API/flash only): `visitor_invitation` (§4.1), `visitor_checked_in`, `visitor_checked_out`, `walkin_request` (to household members), `walkin_approved` (to visitor, with PIN + pass URL). Log every send to `notification_logs` with template name, recipient, provider message id, status.

Audit actions (exact): `visitor.invitation.created`, `visitor.invitation.revoked`, `visitor.invitation.extended`, `visitor.invitation.pin_rotated`, `visitor.walkin.created`, `visitor.walkin.approved`, `visitor.walkin.rejected`, `visitor.checked_in`, `visitor.checked_out`, `visitor.verification_failed`. Metadata must never contain PIN, QR token or resident phone; use ids/labels only.

## 9. Tests (T15 — mandatory)

Unit: `QrCode` fixed-vector matrix/PNG signature; credential hash/encrypt round-trip; phone normalisation reuse; date/window validation helper.
Integration: invitation create (allowed household; validation failures; cross-household 404; rate limit; dev SMS logged; audit); lists upcoming/history scoping; revoke/extend/rotate (state transitions, credential revocation); PIN verify success/failure/expiry/revocation; QR code verify parity; check-in then second check-in 409; checkout; credential single-use replay; walk-in create → resident approve → invitation+credentials created, reject path; guest pass page 200/404/expired states; guard dashboard/active lists; guard unassigned gate 403; no-gate 409; noscript web form flows; rate-limit PIN attempts 429.
Security: cross-tenant (Greenview invitation uuid via Riverside admin → 404; guard of other estate denied); no plaintext PIN in DB (query all columns for the plaintext) and none in `audit_logs`/`notification_logs`; QR token never in audit/logs; public pass page reveals no resident phone/email/user ids; XSS escaping of visitor name/purpose on resident + guard + public pass pages; CSRF on all POSTs; QR PNG endpoint requires auth + `Cache-Control: no-store`.
Update existing tests: Visitors screen no longer has preview rows/chip (replace those assertions with real-data fixtures); route table count grows (list new routes exactly); permission assertions for new routes.

## 10. Write scopes and tasks

| Agent | Task | Write scope |
|---|---|---|
| Lead | T16 | `app/Core/**`, `config/**`, `docs/**`, integration + final gate |
| schema-seed | T11 | `database/**` (migration + seed), then T14 guard views: `app/Views/guard/**`, `app/Views/layouts/guard.php`, `public/assets/css/guard.css`, `public/assets/js/guard.js` |
| modules | T12 | `app/Modules/**`, `routes/**` |
| pwa-shell | T13 | `app/Views/app/visitors.php`, `app/invite.php`, `app/pass.php`, `app/Views/public/pass.php`, `app/Views/layouts/app.php`, `app/Views/partials/**`, `public/assets/css/app.css`, `public/assets/js/app.js` |
| tests | T15 | `tests/**`, `README.md` |

Coordination notes:
- T11 must land the migration + seed before T12 integration runs; T12 writes code against the schema immediately.
- T14 depends on T12's data-variable shapes (§7) and T11's seed; disjoint files from pwa-shell (no touching `app.css`, `app.js`, resident views).
- T13 and T14 both need T12's routes/cameras; views may be written against §6/§7 first.
- Lead runs the full suite + live acceptance at T16 and owns integration fixes.

## 11. Acceptance

1. Migration applies cleanly on a fresh `_test` DB and on the dev DB (`php scripts/install.php` twice, idempotent); seed idempotent.
2. Resident: create invitation via `/app/visitors/invite` → redirect to pass screen with PIN shown once, QR image loads, pass URL works; Visitors list shows the new invitation in Upcoming; revoke removes it from Upcoming; rotate PIN reveals a new PIN; extend updates validity.
3. Visitor: open the pass URL (from another browser/incognito, no login) → pass page shows correct estate/visitor/validity/status; expired or revoked links show the neutral status page.
4. Guard: log in as `0700000004` (John Mutiso, assigned Main Gate) → `/gate` dashboard; select Main Gate; verify the resident's PIN → visitor details shown; check in → success; verify same PIN again → `ALREADY_INSIDE`; active list shows the visit; checkout → success; second checkout → 409.
5. Walk-in: guard creates walk-in for Jane's household → Jane sees pending approval → approve → visitor receives dev SMS with PIN; guard verifies that PIN and checks in.
6. Security: all §9 security invariants pass; PIN/token never in logs/audit; unassigned gate 403; cross-tenant 404; CSRF enforced; rate limits.
7. `php tests/run.php` green (existing 255+ plus new tests), `php -l` clean, `node --check` clean.
8. README updated: new module, routes, screens, demo flows (including demo PIN), credentials/QR model, limitations (no camera scanning, no real SMS, no offline, no vehicles/parking yet), next increment.
9. Live smoke on `127.0.0.1:8010` and through the ngrok tunnel for the resident + guard flows.

## 12. Demo credentials for acceptance (README)

- Guard: `0700000004` (John Mutiso, Main Gate) / `0700000005` (David Kiptoo, Service Gate).
- Resident: `0700000010` (Jane Smith, household A-001) / `0700000011` (Brian Otieno, A-002).
- Seeded upcoming demo invitation (Jane, visitor "Demo Visitor Three"): document its PIN in the README demo table so a guard can verify it immediately after `php scripts/install.php`.
