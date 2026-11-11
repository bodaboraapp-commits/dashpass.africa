# MtaaPass — Estate Access & Visitor Platform

MtaaPass is a multi-tenant estate operating platform: residents invite visitors,
guards verify them at the gate, and estate managers get a complete, auditable
record of who entered and left. This repository contains the **foundation
increment (Blueprint Task 001)** plus the **resident PWA layout refresh (Task
001.1)**: tenancy, authentication, RBAC, an audit trail, a health endpoint, an
installable PWA shell, and the refreshed resident Home/Visitors/Bookings screens.

> **Scope note.** Visitor, parking, billing and notification *workflows* are not
> part of this increment. The shell is real and runnable; the Visitors and
> Bookings screens are layouts backed by clearly-labelled preview data, and
> modules are marked `not_installed` in the dashboard payload until the next
> tasks land.

---

## 1. What is implemented

| Area | Status |
|---|---|
| Tenant model (SaaS account → estates → buildings/households/gates) | Implemented (schema + demo seed) |
| Phone + development OTP authentication (SMS adapter abstraction) | Implemented |
| Session hardening (rotation, fingerprint, idle/absolute lifetimes) | Implemented |
| RBAC: 50 permissions, 13 roles per estate/platform, 198 role-permission links, permission middleware | Implemented |
| Tenant context resolution (never trusts browser-supplied `estate_id`) | Implemented |
| Audit trail (append-only, estate-scoped reads, credential redaction) | Implemented |
| CSRF protection, security headers/CSP, rate limiting, central error handling | Implemented |
| Health endpoint (database, migrations, storage checks) | Implemented |
| PWA shell: responsive views, manifest, service worker, offline page, icons | Implemented |
| Resident PWA layout refresh (Task 001.1): Home hero + 6 quick tiles, Visitors, Bookings, QR sheet, dark-navy default theme | Implemented (Bookings remains clearly-labelled preview data) |
| **Visitor & Access module (Increment 2)**: invitations, PIN + QR credentials, public pass page, guard PWA (gate selection, verify, check-in/check-out), walk-in approvals, dev SMS + audit | Implemented |
| **Vehicles & Parking module (Increment 3)**: household vehicle register, parking zones/bays (resident allocations + visitor bays), visitor parking reservations inside the invitation transaction, guard check-in/out parking sessions, real Bookings screen | Implemented |
| **Estate/admin management portal (Increment 4)**: web-only admin portal for buildings, households + members, residents, guards, gates and allowlisted estate settings — soft-archive, audit, tenant-scoped | Implemented |
| **Email alerts, PWA install & greeting (Increment 5)**: Resend SMTP transactional alerts on security events (dev log adapter), hardened installable PWA with banner/iOS/in-app guidance + SW v4 headers, time-based Home greeting | Implemented |
| **Household staff & visitor incidents (Increment 6)**: resident household staff directory (admin audit view), guard/resident incident reporting, admin triage queue with status workflow, optional high/critical email alerts | Implemented |
| Billing module | **Not installed yet** (later increment) |

---

## 2. Requirements

### Production target

- PHP **8.3+** (this increment is written to run on PHP 8.2 as well; no 8.3-only syntax is used)
- MySQL **8.0+**
- PHP extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `gd` (icon generation only)
- A web server (Apache/Nginx) whose document root points at `public/`
- HTTPS in front of the app (secure cookies are automatic in production)

### Local development environment used by the team

- XAMPP with **PHP 8.2.12** CLI
- **MariaDB 10.4** listening on **`127.0.0.1:3307`** (XAMPP's default port in this setup; production MySQL uses 3306)
- No Composer, no Node.js build step, no PHP framework. Everything is plain PHP, PDO and hand-written CSS/JS.

> This XAMPP PHP build prints `Module "openssl" is already loaded` on stderr. It is
> harmless — no PHP code in this project depends on it.

---

## 3. Installation (Composer-free)

```bash
# 1. Configure the environment
cp .env.example .env

# 2. Generate APP_KEY (prints a base64: key; paste the line into .env)
php scripts/keygen.php

# 3. Make sure .env points at your database
#    DB_HOST=127.0.0.1, DB_PORT=3307 (XAMPP MariaDB), DB_DATABASE=mtaapass,
#    DB_USERNAME=root, DB_PASSWORD= (local defaults)

# 4. Install: run migrations and seed the demo estate (repeatable, never drops data)
php scripts/install.php
```

Useful individual commands:

```bash
php scripts/migrate.php            # apply pending migrations
php scripts/migrate.php --status   # show applied/pending migrations
php scripts/seed.php               # re-run the idempotent demo seeder
```

`php scripts/migrate.php --fresh` is deliberately restricted: it only rebuilds a
database whose name ends in `_test`. The development database is never dropped.

### Environment variables (`.env`)

| Variable | Purpose | Local default |
|---|---|---|
| `APP_ENV` | `local` / `testing` / `staging` / `production` | `local` |
| `APP_DEBUG` | Verbose debug payloads in 5xx errors (never enable in production) | `true` |
| `APP_URL` | Canonical origin used by `absolute_url()` (SMS/email links); `url()`/`asset()` are root-relative | `http://127.0.0.1:8010` |
| `APP_KEY` | Base64 32-byte key for OTP/PIN HMACs (generate with `scripts/keygen.php`) | — (required) |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | PDO connection | `127.0.0.1` / `3307` / `mtaapass` / `root` / empty |
| `DB_TEST_DATABASE` | Database dropped and rebuilt by the test runner | `mtaapass_test` |
| `SMS_DRIVER` | `log` (writes `storage/logs/sms.log`) or `mock` (tests) | `log` |
| `SMS_DEV_EXPOSE_OTP` | Expose the development OTP to the client outside production | `true` |
| `AUTH_OTP_*` | OTP length/TTL/attempts/cooldown and rate-limit windows | see `.env.example` |
| `SESSION_*` | Session cookie name, idle/absolute lifetimes, SameSite | see `.env.example` |
| `UI_PREVIEW_CONTENT` | Show the clearly-labelled `Layout preview` demo rows on the Bookings screen (Visitors uses real data since Increment 2). **Must be `false` for production pilots** so demo data is never mistaken for live operational data. | `true` (local/tests) |
| `VISITOR_DEFAULT_VALIDITY_MINUTES` | Fallback invitation validity when an estate has no `visitor_validity_minutes` setting | `240` |
| `VISITOR_MAX_VALIDITY_HOURS` | Hard ceiling for an invitation window (prevents near-permanent passes) | `168` |
| `VISITOR_PIN_MAX_ATTEMPTS` / `VISITOR_PIN_WINDOW_SECONDS` | Per-credential PIN verification budget (429 + retry_after) | `5` / `300` |
| `VISITOR_PIN_IP_MAX_ATTEMPTS` / `VISITOR_PIN_IP_WINDOW_SECONDS` | Per-IP PIN verification budget | `30` / `300` |
| `VISITOR_PASS_PAGE_LIMIT` / `VISITOR_PASS_PAGE_WINDOW` | Public pass page request budget | `60` / `300` |
| `WALKIN_APPROVAL_TIMEOUT_SECONDS` | Pending walk-in staleness hint (never auto-admits) | `300` |
| `LOG_LEVEL` | `debug`/`info`/`warning`/`error`/`critical` | `debug` |

`.env` is git-ignored. Never commit it, and never commit a real `APP_KEY` or SMS provider credentials.

---

## 4. Running the application

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open <http://127.0.0.1:8000>. Any port works: all application URLs
(`url()`, `asset()`, redirects) are root-relative, so the app is origin- and
proxy-independent. `APP_URL` is only used for fully qualified links
(`absolute_url()`, e.g. future SMS/email messages).

Key URLs:

| Path | What it is |
|---|---|
| `/` | Guest welcome page (redirects to `/app` when signed in) |
| `/login` | Phone → OTP sign-in flow |
| `/app` | Authenticated home dashboard |
| `/app/account` | Account page |
| `/app/estates` | Estate switcher (multi-estate users) |
| `/app/no-estate` | Shown when an account has no active estate membership |
| `/health` | JSON health check (database, migrations, storage) |
| `/manifest.json`, `/service-worker.js`, `/offline.html` | PWA assets |

### Development OTP flow

This increment uses **SMS OTP** as the only sign-in method. In development the SMS
driver is the `log` adapter and the code is exposed on screen, clearly labelled:

1. Open `/login`, enter one of the demo phone numbers below, submit.
2. The next screen shows a **development OTP panel** with the 6-digit code (the
   same message is appended to `storage/logs/sms.log`).
3. Enter the code. You are signed in and redirected to `/app`.
4. Codes are single-use, expire after 5 minutes and lock after 5 wrong attempts.
   Requesting a new code invalidates the previous one.

The code is **never** exposed when `APP_ENV=production`, and `SmsManager` refuses
to boot with the `log`/`mock` driver in production — configure a real provider
adapter before deploying. See `app/Integrations/Sms/`.

### Demo accounts

Every demo user is `active`. Sign in with any of these phone numbers:

| Phone | Name | Role | Estate(s) |
|---|---|---|---|
| `+254700000001` | Grace Wanjiku | `estate_admin` | Greenview Residences **and** Riverside Court (estate switcher demo) |
| `+254700000002` | Peter Ochieng | `estate_manager` | Greenview Residences |
| `+254700000003` | Samuel Kariuki | `security_supervisor` | Greenview Residences |
| `+254700000004` | John Mutiso | `security_guard` | Greenview Residences (Main Gate) |
| `+254700000005` | David Kiptoo | `security_guard` | Greenview Residences (Service Gate) |
| `+254700000006` | Faith Achieng | `security_guard` | Greenview Residences (Main Gate) |
| `+254700000007` | Brian Ochieng | `security_guard` | Greenview Residences (Service Gate) |
| `+254700000008` | Mary Atieno | `parking_manager` | Greenview Residences |
| `+254700000009` | Lucy Wambui | `auditor` | Greenview Residences |
| `+254700000010` | Jane Smith | `resident` | Greenview Residences (household A-001) |
| `+254700000011` | Brian Otieno | `tenant` | Greenview Residences (household A-002) |
| `+254700000012` | Lydia Njeri | `household_member` | Greenview Residences (household A-002) |
| `+254700000013` | Amina Hassan | `resident` | Greenview Residences (household B-001) |
| `+254700000014` | David Njoroge | `estate_admin` | Riverside Court **only** — use this account for cross-tenant checks |
| `+254700000020` | Daniel Mwangi | `platform_super_admin` | Platform membership (no estate) |

Demo estate: **Greenview Residences** (`greenview-residences`, Nairobi, UTC+3),
with a second estate **Riverside Court** (`riverside-court`) for tenant-isolation
testing. All demo data is clearly labelled and idempotently seeded.

### Resident PWA screens (Task 001.1 layout refresh)

The resident PWA follows the product-owner mockup: dark navy surfaces with a
royal-blue accent, a hero estate card over a locally generated image, a 3×2
quick-action grid, announcements with pagination dots, and a bottom navigation
bar with a raised centre QR button.

| Screen | Route | What is on it |
|---|---|---|
| Home | `GET /app` | `Hello,` + `Welcome Home` (`h1`), hero estate card (`/assets/img/estate-hero.png`) with estate switcher, six quick tiles (Access/My QR, Visitors/Pre-Register, Vehicles/My Vehicles, Bookings/Facilities, Payments/& Bills, Notices/& Updates), announcements with dots, household/gates details, five-slot bottom nav with QR FAB |
| Visitors | `GET /app/visitors` + `?tab=history` | Server-side `Upcoming` / `History` segmented tabs (real links, work without JavaScript), visitor cards, `+ Invite Visitor` CTA, empty state |
| Bookings | `GET /app/bookings` + `?tab=bookings` | `Facilities` (default) / `My Bookings` tabs, four facility cards with text+colour status (`Available`, `Few Slots Left`), `+ New Booking` CTA, honest empty My Bookings state |
| QR sheet | shared dialog on Home | Accessible `role="dialog"` sheet (`My access pass`) opened by the Access tile and centre FAB; Escape/backdrop close, focus move/return, placeholder QR only — **no real credential or token is rendered** |

> **Approved palette deviation:** Blueprint §17 specifies forest/emerald/sunshine
> for the whole product. The product owner directed the resident PWA to use the
> mockup's dark-navy/royal-blue language instead, so dark is the **default** theme
> for the resident PWA **and its guest/sign-in/error surfaces** (the light theme and
> the manual theme toggle remain available, and the blueprint palette still applies
> to the guard/admin/platform portals in later increments). This deviation is
> recorded in `docs/resident-pwa-layout-addendum.md` — it is not a silent change.

**Preview content (`UI_PREVIEW_CONTENT`).** Bookings is still a layout screen in
this increment: its data module is not built yet. When `UI_PREVIEW_CONTENT=true`
(default local/tests) it renders clearly-labelled `Layout preview` demo rows; when
`false` (required for production pilots) the controller passes no preview data and
the screen shows honest empty states. **Visitors uses real data since Increment 2**
and no longer shows preview rows. Preview content lives in one place:
`App\Modules\Residents\PreviewContent`.

### Visitor & Access module (Increment 2)

The complete vertical visitor workflow is implemented end to end:
resident creates an invitation → visitor receives a PIN + pass link (dev SMS) →
guard verifies at the gate → check-in → checkout, with audit + notifications.

| Screen | Route | Purpose |
|---|---|---|
| Visitor list | `GET /app/visitors` (`?tab=upcoming|history`) | Real invitations for the resident's households + pending walk-in approvals |
| Invite visitor | `GET/POST /app/visitors/invite` | Create an invitation (name, phone, household, purpose, validity window, visitor count) |
| Pass | `GET /app/passes/{uuid}` | One-time PIN reveal (create/rotate only), server-rendered QR PNG, pass URL, revoke/extend |
| Public pass | `GET /v/{token}` | Branded, no-auth pass page for the visitor; neutral states for invalid/expired/revoked/checked-out; no resident PII |
| Guard dashboard | `GET /gate` | Gate selection (assigned gates only), inside/expected/pending stats, active visitors |
| Guard verify | `GET/POST /gate/verify` | PIN keypad / pass-code paste; check-in action; JS API + noscript form |
| Guard active | `GET /gate/active` | Active visitors + checkout |
| Guard walk-in | `GET/POST /gate/walkin` | Create a walk-in request + pending list |

Credential model: the **PIN** (6 digits) is stored only as a keyed HMAC
(`Crypto::hmac('access-pin:{credential_uuid}', $pin)`) and is shown to the resident
**once** at creation and again only after a PIN rotation; it is never recoverable
from the database. The **QR/pass token** is 32 random bytes (hex) stored as
`Crypto::hmac('access-qr:{credential_uuid}', $token)` plus
`Crypto::encrypt($token, 'access-qr:{credential_uuid}')` so the resident can
re-display the pass link later. QR images are generated server-side by a pure-PHP
encoder (`App\Modules\Visitors\QrCode` → GD PNG, PNG endpoint is authenticated and
`Cache-Control: no-store`). **There is no camera scanning in v1** — guards enter
the PIN or paste the pass code. Credentials are single-use (`max_uses = 1`),
expire with the invitation, and are revoked by revoke/rotate flows.

Demo flow (after `php scripts/install.php`) — the seeded visitor fixtures are
idempotent and Greenview-only:

| Demo item | Value | Use it to |
|---|---|---|
| Upcoming pass | **Demo Visitor Three**, Jane's household **A-001**, Main Gate | Verify any time; check-in only inside its seeded window (**tomorrow 10:00–14:00** estate time — a demo check-in today correctly returns `422 OUTSIDE_VALIDITY`) |
| **Demo PIN** | **`246810`** | Guard `0700000004` enters it at `/gate/verify` |
| Public pass | `/v/{token}` link created with the invitation | Open the visitor pass page without login |
| Pending walk-in | **Demo Walk-in Four** at Main Gate, household A-001 | Approve/reject from `/app/visitors` |
| History | Demo Visitor One / Demo Visitor Two (checked out) | Residents `History` tab + guard active/history |
| Guard (Main Gate) | `+254700000004` John Mutiso | Verify/check-in/check-out, walk-in create |
| Guard (Service Gate) | `+254700000005` David Kiptoo | Gate selection / unassigned-gate checks |
| Resident host | `+254700000010` Jane Smith (A-001) | Create/revoke/extend/rotate invitations |
| Resident (second household) | `+254700000011` Brian Otieno (A-002) | Household isolation + seeded checked-in visitor |

Demo codes are development-only — never enable a development SMS adapter in
production. `php scripts/install.php` can be re-run safely: the visitor seeder
matches its rows by stable UUIDs.

### Vehicles & Parking module (Increment 3)

Residents register their household vehicles, see their allocated bay, and reserve a
visitor bay as part of an invitation; guards start/complete parking sessions when
the visitor checks in/out. The Bookings screen is now real parking data (no preview).

| Screen | Route | Purpose |
|---|---|---|
| My vehicles | `GET/POST /app/vehicles`, `POST /app/vehicles/{uuid}/deactivate` | Household vehicle register (plate, type, colour, status) — plates normalized (uppercase, alphanumeric) and unique per estate |
| My parking | `GET /app/parking` | Resident allocation card (bay/zone/vehicle) + upcoming visitor reservations |
| Bookings | `GET /app/bookings?tab=facilities\|bookings&from=&to=` | Facilities tab = real visitor-bay availability for a window; My Bookings = real reservations |
| Invite (extended) | `GET/POST /app/visitors/invite` | Optional household vehicle select + "Visitor parking required" checkbox (availability count is progressive enhancement; server re-validates) |
| Pass (extended) | `GET /app/passes/{uuid}` | Shows the reserved visitor bay/zone/window when parking is attached |

**Parking flow.** Invitation creation and the reservation happen in **one DB
transaction**: candidate visitor bays are locked `FOR UPDATE` in deterministic
`bay_code` order, availability is re-checked (half-open `[starts_at, ends_at)`
overlap against `reserved|checked_in` reservations and active sessions), and if no
bay is free the whole invitation is rolled back with **409 `NO_PARKING_AVAILABLE`**
(no invitation row, no orphan visitor, no fake confirmation). Bay `status` is
physical only (`available|occupied|maintenance`); a reserved bay stays `available`
until check-in and is protected by the reservation overlap instead. Maintenance bays
are never offered. On guard check-in a reserved invitation moves the reservation to
`checked_in`, occupies the bay and opens a `parking_sessions` row; checkout completes
the session, frees the bay and completes the reservation. Visitors without parking
are unaffected, and a parking side-effect failure never fails the visitor check-in.
Dev SMS sends `parking_confirmation` (bay + window, **never the plate**).

Demo fixtures (Greenview, after `php scripts/install.php`; idempotent, stable UUIDs):

| Demo item | Value |
|---|---|
| Vehicles | `KDA123A` White car (Jane/A-001), `KCA456B` Grey van (Brian/A-002), `KDD789C` Black car (Amina/B-001) — all active |
| Resident bays / allocations | `R-01`…`R-08`; allocations R-01→A-001, R-02→A-002, R-03→B-001 (active) |
| Visitor bays | `V-01`…`V-04` available, `V-05` accessible, **`V-06` maintenance** (never offered) |
| Reserved visitor bay | **V-01** for **Demo Visitor Three** (upcoming invitation, tomorrow 10:00–14:00 Nairobi) — reservation `2e000001-…-0001`, status `reserved` |
| History session | completed session on **V-02** for a checked-out history visit |
| Guard parking API | `POST /api/v1/gate/parking/sessions/checkin|checkout`, `GET /api/v1/gate/parking/active` |

Demo flow: Jane opens `/app/vehicles` (sees KDA123A), `/app/parking` (R-01 + the V-01
reservation) and `/app/bookings` (real facilities/bays + My Bookings); a new invite
with "Visitor parking required" reserves a free visitor bay or fails cleanly with
`NO_PARKING_AVAILABLE`. Guard `0700000004` can start/stop a parking session for an
in-window parking reservation and see it on `/api/v1/gate/parking/active`.

Role note: `estate_admin`/`estate_manager`/`parking_manager` hold the all-estate
vehicle permissions (`vehicle.view_all`/`manage_all`); the register route keeps the
contract's own-scope middleware, so estate admins are also granted
`vehicle.view_own` + `manage_own` and `VehicleService` returns the estate-wide list
through its `canViewAll()` branch (role-permission links 198, estate_admin 31).
Public pass parking shows the bay code/zone only — never the vehicle plate.

### Estate/admin management portal (Increment 4)

A server-rendered admin portal for `estate_admin` (and `estate_manager` within its
permissions) covering their **active estate only**. Web forms only — **no JSON admin
APIs** in this increment — and **no schema changes**: every mutation uses the existing
tables with soft-archive semantics (never hard-deletes) plus an audit row.

| Screen | Routes | Permission |
|---|---|---|
| Overview | `GET /admin` | `estate.view` |
| Buildings | `GET/POST /admin/buildings`, `GET/POST /admin/buildings/{uuid}` | `household.view` / `household.manage` |
| Households | `GET/POST /admin/households`, `GET/POST /admin/households/{uuid}`, `POST .../archive` | `household.view` / `household.manage` |
| Household members | `POST /admin/households/{uuid}/members`, `POST .../members/{memberUuid}/remove` | `household.member.manage` + controller-level `household.manage\|estate.user.manage` |
| Residents | `GET/POST /admin/residents`, `POST .../{uuid}/suspend`, `POST .../{uuid}/reactivate` | `estate.user.manage` |
| Guards | `GET/POST /admin/guards`, `POST .../{uuid}/gates`, `POST .../{uuid}/archive` | `guard.manage` |
| Gates | `GET/POST /admin/gates`, `POST /admin/gates/{uuid}`, `POST .../archive` | `gate.view` / `gate.manage` |
| Settings | `GET/POST /admin/settings` | `estate.settings.manage` |

All `/admin*` routes require `auth` + `estate` + the listed permission **and** a shared
admin-area gate (at least one of `household.manage`, `estate.user.manage`,
`guard.manage`, `gate.manage`, `estate.settings.manage`); residents/tenants/guards get
**403 on every admin route** (no estate-wide data exposure). Access matrix:
`estate_admin` has everything; `estate_manager` passes the gate but is bounded by
route permissions (households/buildings/guards manageable; **no** residents or
settings; gates read-only); unauthenticated → 302 `/login`; a suspended admin loses
access immediately.

Business rules: building names and household units are unique per estate
(`BUILDING_EXISTS` / `UNIT_EXISTS`); buildings are create+edit only (no status column
→ **no archive**, documented); households archive/unarchive via status
(`active|inactive`, archived excluded from the invite household selector); household
members are soft-removed, exactly one primary per household, and removing the last
primary is rejected (`PRIMARY_MEMBER_REQUIRED`); residents are created or linked by
phone with allowlisted roles only (`resident|tenant|household_member`), duplicates
rejected (`RESIDENT_EXISTS`), dev SMS invite uses the configured product name,
suspension is immediate; guards are created with ≥1 gate (assignment rows),
assign-gates replaces the set transactionally, archive deactivates the membership;
gates archive to `inactive` (`GATE_EXISTS` duplicate), archived gates leave guard
assignment selectors and are rejected by the visitor check-in path; settings expose
an allowlist only (`visitor_validity_minutes` 15–1440,
`visitor_parking_grace_minutes` 0–120), unknown keys `SETTING_NOT_ALLOWED`, values
stored JSON-encoded like the seeder. Audit actions are exactly
`admin.building_created/updated`, `admin.household_created/updated/archived`,
`admin.member_added/removed`, `admin.resident_created/linked/suspended/reactivated`,
`admin.guard_created/gates_updated/archived`, `admin.gate_created/updated/archived`,
`admin.settings_updated` — metadata carries uuids/keys only, never phone/name/OTP/PIN.

Demo admins: **Grace Wanjiku** `+254700000001` (`estate_admin` for Greenview and
Riverside — full portal) and **David Njoroge** `+254700000014` (`estate_admin`,
**Riverside only** — tenant-isolation checks). `estate_manager` `+254700000002` is
the bounded-manager demo account. The admin shell reuses the product name from
configuration (MtaaPass in development, DashPASS in production) and its "Admin
console" link appears in `/app/account` only for users who can access it.

### Email alerts, PWA install & time-based greeting (Increment 5)

**Transactional security email (Resend SMTP).** The product sends an email alert on
security events ? `visitor.checked_in`, `visitor.checked_out`, `walkin.created` ? to
the host's `users.email` when present, plus the configured fallback inboxes, deduped.
Email is emitted **after commit** from the existing notification points; any provider
failure is swallowed and audited (`email.alert_failed`) so a mail problem can never
block or 500 a visitor flow. Bodies never contain PINs, OTPs, QR/pass tokens or phone
numbers, and audit metadata carries masked recipients only.

| Config (`.env`) | Purpose |
|---|---|
| `MAIL_DRIVER` | `log` (dev/test, writes redacted `storage/logs/mail.log`), `smtp` (production Resend SMTP), or `resend` (HTTP API) ? unknown values fall back to `log` |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Verified sender address/name (`onboarding@resend.dev` is Resend's test sender) |
| `MAIL_ALERT_TO` | Optional comma-separated fallback pilot inboxes (invalid addresses dropped) |
| `MAIL_SMTP_HOST` / `MAIL_SMTP_PORT` / `MAIL_SMTP_ENCRYPTION` | Resend SMTP: `smtp.resend.com` / `465` / `ssl` (implicit TLS; `tls` = STARTTLS on 587, `none` plain for local testing only) |
| `MAIL_SMTP_USERNAME` / `MAIL_SMTP_PASSWORD` | SMTP username `resend` and the **API key as password** (secret, `.env` only) |
| `MAIL_TIMEOUT_SECONDS` | Connection/read timeout (default 10s) |
| `RESEND_API_KEY` / `RESEND_API_URL` | Only for the optional HTTP-API driver |

The SMTP adapter is dependency-free (PHP streams): implicit TLS/STARTTLS, multiline
EHLO parsing, AUTH LOGIN with AUTH PLAIN fallback, MAIL FROM/RCPT TO/DATA with
dot-stuffing, and generic `MAIL_SEND_FAILED` errors that never leak credentials or
server responses. `php scripts/mail-test.php --check` tests connection + auth only;
`--to=you@example.com` sends one explicit test message (no implicit recipients).
**Resend requires a verified sender domain; the provided API key must be
replaced/verified by the owner before production alerts work.**

**PWA install enforcement.** `/manifest.json` is configuration-driven and hardened
(`lang`/`dir`, `display_override`, `orientation`, `categories`,
`prefer_related_applications:false`, `launch_handler`, `handle_links`, two shortcuts
for Invite visitor and My parking, 192/512/maskable icons, `start_url`/`scope` `/`).
App pages include a dismissible install banner (`data-install-bar`; dismissed state in
`localStorage`), Chromium `beforeinstallprompt` ? native prompt, iOS "Add to Home
Screen" instructions, in-app-browser guidance, installed-state detection
(`display-mode: standalone` / `navigator.standalone`), an account-screen self-check,
and `appinstalled` feedback. The service worker is **v4** (`mtaapass-shell-v4` /
`mtaapass-static-v4`) with a precached offline shell/manifest/icons, network-first
navigations + offline fallback, `SKIP_WAITING`/`LOGOUT` handling and safe cache
cleanup; `public/.htaccess` serves `/service-worker.js` with
`Cache-Control: no-cache, max-age=0` and `Service-Worker-Allowed: /`.
Installability criteria: HTTPS, valid manifest+icons, registered SW with scope `/`.
Browser prompts cannot be automated in CI; the automated suite proves the static
criteria and hooks, and the owner must **redeploy** for the production server to pick
up the new manifest/SW/headers.

**Time-based greeting.** The Home greeting is server-rendered in estate-local time:
05:00?11:59 "Good morning,", 12:00?16:59 "Good afternoon,", 17:00?21:59
"Good evening,", 22:00?04:59 "Hello," (the neutral night fallback; an invalid
timezone/config also renders "Hello,"). It is escaped, requires no JavaScript, and
uses no database writes.

**Demo/preview.** No real email leaves the machine: tests and local development use
`MAIL_DRIVER=log`, which writes masked recipients and a redacted subject to
`storage/logs/mail.log` (never the body). To receive pilot alerts without a verified
domain, set `MAIL_ALERT_TO` and use the log driver; production uses `MAIL_DRIVER=smtp`
with a verified Resend sender and the API key as the SMTP password.

### Household staff & visitor incidents (Increment 6)

Two operational modules complete the resident/guard/admin workflow: a **household
staff directory** residents maintain (admins get an estate-wide audit view, guards
verify staff at the gate by name/type without a staff login) and **visitor/security
incident reporting** with a triage workflow.

| Area | Screens / routes | Permission |
|---|---|---|
| Resident staff directory | `GET/POST /app/staff`, `POST /app/staff/{uuid}/deactivate`, `POST .../reactivate`; API `GET/POST /api/v1/resident/staff` | `household.staff.manage` |
| Resident incidents | `GET /app/incidents`, `GET /app/incidents/new`, `POST /app/incidents`; API `GET/POST /api/v1/resident/incidents`, `GET .../{uuid}` | `visitor.incident.create` |
| Guard incidents | `GET/POST /gate/incidents`, `GET /gate/incidents/new` (gate fixed from the guard session) | `visitor.incident.create` |
| Admin staff audit | `GET /admin/staff` (estate-wide, household labels, masked phones) | `household.staff.manage` |
| Admin incident queue | `GET /admin/incidents` (status/severity filters), `GET /admin/incidents/{uuid}` (detail), `POST .../{uuid}/status`; API `GET /api/v1/admin/incidents`, `GET .../{uuid}`, `PATCH .../{uuid}` | `visitor.incident.manage` |

Staff records carry full name, phone (stored normalized, **masked outside the
owning-household/admin views**), staff type (`domestic|driver|gardener|nanny|cook|security|other`),
notes, and a soft `active|inactive` status ? no hard deletes, and no staff accounts or
invitations this increment. Staff creation is scoped to the caller's active
households: duplicates in the same household return 422 `STAFF_EXISTS`, archived
households 422 `HOUSEHOLD_INACTIVE`, and foreign/cross-tenant households 404.
Audit actions: `staff.created`, `staff.updated`, `staff.deactivated`.

Incidents carry type (`unauthorised_access|tailgating|lost_pass|suspicious_activity|property_damage|medical|other`),
severity (`low|medium|high|critical`), summary/description, occurred-at, optional
gate/household/invitation references and a status workflow
**open ? investigating ? resolved|dismissed** (terminal states return 409
`INCIDENT_CLOSED`; resolving requires resolution notes). Residents see only their own
reports, guards report at their session gate and see their own reports, admins get
the estate queue/detail and triage. Descriptions are escaped everywhere and audit
metadata is PII-free (`incident.reported`, `incident.updated`,
`incident.status_changed`). High/critical incidents optionally emit a security email
alert through the Increment-5 mail path (post-commit, non-blocking, audited).

Role amendment (approved Option B): `household.staff.manage` and
`visitor.incident.create` are added to **resident, tenant and household_member**
(role-permission links **198 ? 204**; resident 16, tenant 15, household_member 10).
Demo fixtures (Greenview): staff **Demo Housekeeper** (A-001, domestic),
**Demo Gardener** (A-002, gardener), **Demo Driver** (B-001, driver); incidents
open/medium suspicious activity at Main Gate (reported by guard John),
investigating/high unauthorised access linked to the seeded history visit, and
resolved/low lost pass for A-001 (resolved by the estate admin). Re-running
`php scripts/install.php` makes no changes.

Route table grows **99 ? 121** (22 new routes: resident web 7, guard web 3, admin web
4, resident API 5, admin API 3, including one PATCH). Migration 8 adds the two new
tables ? **7 migrations / 36 tables**; no existing table changes.

---

## 5. Architecture map

```
public/index.php          Front controller (PHP built-in server static-file shortcut)
app/bootstrap.php         Env + Config + PSR-4 autoloader + App kernel, returns App
app/Core/                 Framework-free kernel:
  Env Config Clock Uuid Crypto Phone Database Request Response Session Csrf
  Router MiddlewareResolver Context Auth TenantContext Permission Validator
  RateLimiter AuditLogger Logger ErrorHandler View HttpException ValidationException
  Migrator
app/Middleware/           SecurityHeaders, CsrfProtection, Authenticate, GuestOnly,
                          EnsureEstate, RequirePermission, Throttle
app/Modules/              Authentication, Estates, Households, Residents,
                          Visitors (invitations, access credentials, walk-ins,
                          QrCode encoder, public pass), Vehicles (register +
                          plate normalization), Parking (availability,
                          reservations, sessions, presenter), Admin (estate
                          management portal services + controllers), Staff
                          (household staff directory), Incidents (reporting +
                          triage), Accounts, Platform, Notifications
app/Integrations/Sms/     SmsProvider interface, Log/Mock adapters, SmsManager
app/Integrations/Mail/    MailProvider interface, Log/Resend(SMTP)/Resend(HTTP) adapters,
                          MailManager, MailMessage, MailSendException
app/Modules/Notifications/EmailAlertService: post-commit security event alerts
app/Views/                Server-rendered PHP templates + layouts + partials
  app/home.php            Resident Home (hero, quick tiles, announcements)
  app/visitors.php        Real visitor list + pending walk-in approvals
  app/invite.php          Invite-visitor form (vehicle + visitor-parking options)
  app/pass.php            Resident pass (PIN reveal, QR, parking bay, revoke/extend)
  app/vehicles.php        Household vehicle register + add/deactivate forms
  app/parking.php         My parking (allocation + visitor reservations)
  app/bookings.php        Real visitor-bay availability + reservations
  public/pass.php         Public /v/{token} pass page (no auth; bay code only)
  guard/                  Guard PWA screens (dashboard, verify, active, walk-in)
  admin/                  Admin portal screens (overview, buildings, households,
                          residents, guards, gates, settings, staff, incidents)
  app/staff.php           Household staff directory (resident)
  app/incidents.php       Resident incident list + report form
  guard/incidents.php     Guard incident list + report form
  layouts/app.php         Resident shell (navy, bottom nav + QR FAB)
  layouts/guard.php       Guard shell (high-contrast)
  layouts/admin.php       Admin shell (nav, tables, flash messages)
  partials/qr-sheet.php   Shared accessible QR sheet dialog
routes/web.php            HTML routes (resident + guard + public pass + manifest + admin)
routes/api.php            JSON API v1 routes
routes/platform.php       Placeholder for the SaaS platform portal (no routes yet)
config/                   app, database, security, sms, mail, logging, ui, visitors
database/migrations/      Seven plain-SQL migrations applied in filename order
database/seeds/           Idempotent permission, role, demo, visitor and parking seeders
                          (36 tables and 204 role-permission links after Increment 6)
scripts/                  install / migrate / seed / keygen / generate-icons /
                          generate-estate-hero CLIs
public/assets/css/        app.css (resident design system) + guard.css + admin.css
public/service-worker.js   PWA service worker (v4 caches, offline shell, update flow)
public/.htaccess           Front controller + security headers (SW no-cache/allowed)
public/assets/img/        Locally generated estate hero image (GD, no network)
tests/                    Plain-PHP test harness: Unit, Integration, Security suites
storage/                  logs, temporary, uploads, exports, cache (git-ignored contents)
```

### Request lifecycle

1. `public/index.php` boots the kernel and dispatches one `App\Core\Request`.
2. Global **CSRF protection** wraps the router (safe methods + `/health` exempt).
3. The router matches the path/method, runs route middleware in order — `auth`,
   `estate`, `permission:<slug>`, `throttle:<name>,<max>,<window>` — then the controller.
4. The controller builds a per-request `App\Core\Context` (`$context->db`, `auth`,
   `tenant`, `permission`, `audit`, `rateLimiter`) and returns an
   `App\Core\Response`.
5. Every response gets security headers and an `X-Correlation-ID`; exceptions are
   rendered by the central `ErrorHandler` (JSON for `/api/*`, HTML otherwise).

### API conventions

All API responses are enveloped:

```json
{"success": true,  "message": "...", "data": { ... }}
{"success": false, "error": {"code": "PERMISSION_DENIED", "message": "...", "errors": {}}}
```

Authenticated state-changing requests must send `X-CSRF-Token` (or the `_token`
form field). Public identifiers are UUIDs; internal integer ids never appear in
URLs or payloads. `/health` is the only endpoint that returns a bare JSON document.

---

## 6. Routes

### Web (`routes/web.php`)

| Method | Path | Middleware |
|---|---|---|
| GET | `/` | — |
| GET | `/login` | `guest` |
| POST | `/login/otp` | `guest`, `throttle:web-login,20,60` |
| POST | `/login/verify` | `guest`, `throttle:web-verify,30,60` |
| POST | `/logout` | `auth` |
| GET | `/app` | `auth`, `estate` |
| GET | `/app/visitors` | `auth`, `estate`, `permission:visitor.invitation.create` |
| GET | `/app/visitors/invite` | `auth`, `estate`, `permission:visitor.invitation.create` |
| POST | `/app/visitors/invite` | `auth`, `estate`, `permission:visitor.invitation.create`, `throttle:invite,20,600` |
| GET | `/app/passes/{uuid}` | `auth`, `estate`, `permission:visitor.invitation.view_own` |
| GET | `/app/passes/{uuid}/qr.png` | `auth`, `estate`, `permission:visitor.invitation.view_own` |
| POST | `/app/passes/{uuid}/revoke` | `auth`, `estate`, `permission:visitor.invitation.revoke` |
| POST | `/app/passes/{uuid}/extend` | `auth`, `estate`, `permission:visitor.invitation.extend` |
| POST | `/app/passes/{uuid}/rotate-pin` | `auth`, `estate`, `permission:visitor.invitation.create` |
| POST | `/app/walkins/{uuid}/approve` | `auth`, `estate`, `permission:visitor.walkin.approve` |
| POST | `/app/walkins/{uuid}/reject` | `auth`, `estate`, `permission:visitor.walkin.reject` |
| GET | `/app/bookings` | `auth`, `estate` |
| GET | `/app/vehicles` | `auth`, `estate`, `permission:vehicle.view_own` |
| POST | `/app/vehicles` | `auth`, `estate`, `permission:vehicle.manage_own`, `throttle:vehicle,20,600` |
| POST | `/app/vehicles/{uuid}/deactivate` | `auth`, `estate`, `permission:vehicle.manage_own` |
| GET | `/app/parking` | `auth`, `estate`, `permission:parking.view` |
| GET | `/app/account` | `auth` |
| GET | `/app/estates` | `auth` |
| POST | `/app/estates/{uuid}/switch` | `auth` |
| GET | `/app/no-estate` | `auth` |
| GET | `/manifest.json` | — (dynamic product-name PWA manifest) |
| GET | `/health` | — |

### API (`routes/api.php`, prefix `/api/v1`)

| Method | Path | Middleware |
|---|---|---|
| GET | `/api/v1/health` | — |
| GET | `/api/v1/auth/csrf` | — |
| POST | `/api/v1/auth/request-otp` | `throttle:otp-request,20,60` |
| POST | `/api/v1/auth/verify-otp` | `throttle:otp-verify,30,60` |
| POST | `/api/v1/auth/logout` | `auth` |
| GET | `/api/v1/auth/me` | `auth` |
| GET | `/api/v1/estates` | `auth` |
| POST | `/api/v1/estates/{uuid}/switch` | `auth` |
| GET | `/api/v1/estate` | `auth`, `estate`, `permission:estate.view` |
| GET | `/api/v1/estate/settings` | `auth`, `estate`, `permission:estate.settings.manage` |
| GET | `/api/v1/estate/audit-logs` | `auth`, `estate`, `permission:audit.view` |
| GET | `/api/v1/resident/dashboard` | `auth`, `estate`, `permission:estate.view` |
| GET | `/api/v1/resident/invitations` | `auth`, `estate`, `permission:visitor.invitation.view_own` |
| POST | `/api/v1/resident/invitations` | `auth`, `estate`, `permission:visitor.invitation.create` |
| GET | `/api/v1/resident/invitations/{uuid}` | `auth`, `estate`, `permission:visitor.invitation.view_own` |
| POST | `/api/v1/resident/invitations/{uuid}/revoke` | `auth`, `estate`, `permission:visitor.invitation.revoke` |
| POST | `/api/v1/resident/invitations/{uuid}/extend` | `auth`, `estate`, `permission:visitor.invitation.extend` |
| POST | `/api/v1/resident/invitations/{uuid}/rotate-pin` | `auth`, `estate`, `permission:visitor.invitation.create` |
| POST | `/api/v1/resident/walkins/{uuid}/approve` | `auth`, `estate`, `permission:visitor.walkin.approve` |
| POST | `/api/v1/resident/walkins/{uuid}/reject` | `auth`, `estate`, `permission:visitor.walkin.reject` |
| GET | `/api/v1/resident/vehicles` | `auth`, `estate`, `permission:vehicle.view_own` |
| POST | `/api/v1/resident/vehicles` | `auth`, `estate`, `permission:vehicle.manage_own`, `throttle:vehicle,20,600` |
| POST | `/api/v1/resident/vehicles/{uuid}/deactivate` | `auth`, `estate`, `permission:vehicle.manage_own` |
| GET | `/api/v1/resident/parking` | `auth`, `estate`, `permission:parking.view` |
| GET | `/api/v1/parking/availability` | `auth`, `estate`, `permission:parking.view` |
| POST | `/api/v1/gate/parking/sessions/checkin` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/api/v1/gate/parking/sessions/checkout` | `auth`, `estate`, `permission:visitor.checkout` |
| GET | `/api/v1/gate/parking/active` | `auth`, `estate`, `permission:visitor.checkin` |
| GET | `/api/v1/gate/dashboard` | `auth`, `estate`, `permission:gate.view` |
| POST | `/api/v1/gate/verify-pin` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/api/v1/gate/verify-code` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/api/v1/gate/visitors/checkin` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/api/v1/gate/visitors/checkout` | `auth`, `estate`, `permission:visitor.checkout` |
| GET | `/api/v1/gate/active-visitors` | `auth`, `estate`, `permission:visitor.checkin` |
| GET | `/api/v1/gate/pending-approvals` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/api/v1/gate/walkins` | `auth`, `estate`, `permission:visitor.walkin.create` |

### Public pass + guard web

| Method | Path | Middleware |
|---|---|---|
| GET | `/v/{token}` | — (rate-limited in the service) |
| GET | `/gate` | `auth`, `estate`, `permission:gate.view` |
| POST | `/gate/select-gate` | `auth`, `estate`, `permission:gate.view` |
| GET/POST | `/gate/verify` | `auth`, `estate`, `permission:visitor.checkin` |
| POST | `/gate/checkin` | `auth`, `estate`, `permission:visitor.checkin` (noscript form) |
| POST | `/gate/checkout` | `auth`, `estate`, `permission:visitor.checkout` (noscript form) |
| GET | `/gate/active` | `auth`, `estate`, `permission:visitor.checkin` |
| GET/POST | `/gate/walkin` | `auth`, `estate`, `permission:visitor.walkin.create` |

### Staff & incidents (web + API)

| Method | Path | Permission |
|---|---|---|
| GET/POST | `/app/staff`, `/api/v1/resident/staff` | `household.staff.manage` |
| POST | `/app/staff/{uuid}/deactivate`, `/app/staff/{uuid}/reactivate` | `household.staff.manage` |
| GET | `/admin/staff` | `household.staff.manage` |
| GET/POST | `/app/incidents`, `/api/v1/resident/incidents` | `visitor.incident.create` |
| GET | `/app/incidents/new`, `/api/v1/resident/incidents/{uuid}` | `visitor.incident.create` |
| GET/POST | `/gate/incidents`, `/gate/incidents/new` | `visitor.incident.create` |
| GET | `/admin/incidents`, `/admin/incidents/{uuid}` | `visitor.incident.manage` |
| POST | `/admin/incidents/{uuid}/status` | `visitor.incident.manage` |
| GET | `/api/v1/admin/incidents`, `/api/v1/admin/incidents/{uuid}` | `visitor.incident.manage` |
| PATCH | `/api/v1/admin/incidents/{uuid}` | `visitor.incident.manage` |

### Admin portal (web only)

| Method | Path | Permission |
|---|---|---|
| GET | `/admin` | `estate.view` |
| GET/POST | `/admin/buildings` | `household.view` / `household.manage` |
| GET/POST | `/admin/buildings/{uuid}` | `household.view` / `household.manage` |
| GET/POST | `/admin/households` | `household.view` / `household.manage` |
| GET/POST | `/admin/households/{uuid}` | `household.view` / `household.manage` |
| POST | `/admin/households/{uuid}/archive` | `household.manage` |
| POST | `/admin/households/{uuid}/members` | `household.member.manage` + controller guard |
| POST | `/admin/households/{uuid}/members/{memberUuid}/remove` | `household.member.manage` + controller guard |
| GET/POST | `/admin/residents` | `estate.user.manage` |
| POST | `/admin/residents/{uuid}/suspend` | `estate.user.manage` |
| POST | `/admin/residents/{uuid}/reactivate` | `estate.user.manage` |
| GET/POST | `/admin/guards` | `guard.manage` |
| POST | `/admin/guards/{uuid}/gates` | `guard.manage` |
| POST | `/admin/guards/{uuid}/archive` | `guard.manage` |
| GET/POST | `/admin/gates` | `gate.view` / `gate.manage` |
| POST | `/admin/gates/{uuid}` | `gate.manage` |
| POST | `/admin/gates/{uuid}/archive` | `gate.manage` |
| GET/POST | `/admin/settings` | `estate.settings.manage` |

All admin routes additionally carry the shared admin-area gate (403 without a manage
permission) and the `auth` + `estate` middleware.

Total route table: **121** routes (25 foundation + 35 Increment 2 + 12 Increment 3
vehicles/parking + 26 Increment 4 admin + 22 Increment 6 staff/incidents + 1 dynamic
`/manifest.json`). A pure guard
(holds `visitor.checkin` but not `estate.view`) is redirected from `/app` to
`/gate`.

Notes:

- `/api/v1/estates/{uuid}/switch` and `/api/v1/estate` only ever operate on estates
  the authenticated user is an active member of; an unknown/unauthorised UUID is
  reported as `404 ESTATE_NOT_FOUND` to prevent enumeration.
- `GET /api/v1/estate/audit-logs?limit=` defaults to 50 and is clamped to 1–200.
- Unauthenticated API calls return `401 UNAUTHENTICATED`; browser routes redirect to
  `/login` and remember the intended path.

---

## 7. Security controls

- **Authentication**: SMS OTP only; no passwords in this increment. OTPs are
  generated with `random_int`, stored only as `HMAC-SHA256(otp:{challenge_uuid}, code)`
  keyed by `APP_KEY`, single-use, 5-minute expiry, 5-attempt lockout. Requesting a
  new code invalidates the previous one. No plaintext OTP is ever logged or audited.
- **Account enumeration**: unknown numbers receive the same generic response and no
  challenge row.
- **Sessions**: secure/HttpOnly/SameSite cookies, `use_strict_mode`, session id and
  CSRF token rotated on login/logout, coarse UA fingerprint binding, 120-minute idle
  and 12-hour absolute lifetimes, immediate `suspended`-user revocation.
- **CSRF**: synchroniser token on every non-GET request (header `X-CSRF-Token` or
  `_token`); only `/health` and `/api/v1/health` bypass it, and only GET is routed.
- **Tenant isolation**: the active estate is resolved from the database memberships
  of the authenticated user, never from request input. `TenantContext::switchTo()`
  only accepts estates the user belongs to; `assertScope()` blocks cross-tenant ids.
  Audit-log reads are filtered by the resolved active estate id.
- **RBAC**: authorisation decisions use permission slugs (`Permission::authorize()`),
  never role names. Roles are templates that map to permissions in the database.
- **Rate limiting**: fixed-window counters in MySQL for OTP request cooldown,
  per-phone/per-IP OTP limits, OTP verification limits and route throttles.
- **Output escaping / XSS**: every dynamic value in views goes through `e()`; the
  Content-Security-Policy allows `script-src 'self'` and `style-src 'self'` only
  (no inline script/style, no external CDNs).
- **Headers**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, `Cache-Control: no-store`,
  HSTS when served over HTTPS.
- **SQL injection**: PDO prepared statements for all dynamic values; table/column
  identifiers come from developer constants only.
- **Audit**: `audit_logs` is append-only with actor, estate, action, entity,
  correlation id, IP and user agent; credential-shaped metadata is redacted.
- **Error handling**: internal messages and stack traces are never sent to users;
  every 5xx has a correlation id that links to `storage/logs/`.
- **Visitor credentials**: PINs are stored only as a keyed HMAC
  (`access-pin:{credential_uuid}`) and are unrecoverable; the six-digit PIN is shown
  to the resident only once (create/rotate). QR/pass tokens are stored as an HMAC for
  lookup plus AES-256-GCM ciphertext (`access-qr:{credential_uuid}`) for re-display,
  so the raw token never sits in the database. Credentials are single-use, expire
  with the invitation and are revoked on revoke/rotate. The QR PNG endpoint requires
  authentication and returns `Cache-Control: no-store`.
- **Gate assignment**: guard verification/check-in/check-out enforce a selected gate
  server-side against active `guard_gate_assignments` (409 `GATE_CONTEXT_REQUIRED`
  when no gate is selected, 403 `GATE_NOT_ASSIGNED` for a gate the guard is not
  assigned to) — the UI is never the source of truth.
- **Visitor rate limits**: per-credential and per-IP PIN budgets, per-user invitation
  creation budget, public pass-page per-IP budget (429 + `retry_after`).
- **Public pass page**: no auth, but exposes only estate name, visitor name,
  household label, validity and status — never the PIN, token, resident phone,
  email, user id or host id.
- **Visitor audit**: every invitation/walk-in/check-in/check-out state change writes
  an append-only audit row (`visitor.invitation.*`, `visitor.walkin.*`,
  `visitor.checked_in/out`, `visitor.verification_failed`) with ids/labels only — no
  PIN, QR token or resident phone in metadata or logs.
- **Vehicle & parking isolation**: vehicles are scoped to the authenticated user's
  households (cross-household = 404, cross-tenant = 403/404, no enumeration); plates
  are normalized (uppercase alphanumeric) and unique per estate
  (409 `PLATE_ALREADY_REGISTERED`); availability is computed from real reservations
  only (maintenance bays excluded, half-open `[start, end)` overlap, active sessions
  excluded). Invitation + parking reservation are **transactional**: bays are locked
  `FOR UPDATE` in `bay_code` order, re-checked, and a failure returns
  **409 `NO_PARKING_AVAILABLE` with the whole invitation rolled back** (no invitation
  row, no fake confirmation). Guard parking side effects never fail the visitor
  check-in (`parking.session_failed` audit + continue); the public pass page shows
  the bay code only, never the plate or resident PII; audit actions are
  `vehicle.created/updated/deactivated`, `parking.reserved`,
  `parking.reservation_cancelled`, `parking.session_started/completed/failed`; the
  `parking_confirmation` dev SMS never includes the host plate.
- **Preview data is flag-gated**: `UI_PREVIEW_CONTENT` only controls the remaining
  Home announcements demo content; Visitors, Pass, Vehicles and Bookings render real
  data only, and production pilots must set the flag to `false`.
- **Security email alerts**: sent only after commit from the SMS notification points;
  recipients are the host's `users.email` + configured fallbacks (deduped); bodies never
  contain PIN/OTP/QR token/phone; `LogMailProvider` writes masked recipients and a
  redacted subject only; failures are audited (`email.alert_failed`) and never block or
  500 the visitor flow; SMTP errors are generic `MAIL_SEND_FAILED` with no credentials
  or provider response text.
- **PWA serving**: `/manifest.json` is configuration-driven and served no-store;
  `/service-worker.js` is served with `Cache-Control: no-cache, max-age=0` +
  `Service-Worker-Allowed: /`; caches are versioned (`v4`) with safe cleanup, API and
  authenticated pages are never cached, and no inline handlers/absolute URLs are used.
- **CSP unchanged**: the refreshed screens use no inline `<script>`, `style=`
  attributes, `onclick=` handlers or external assets (asserted by the test suite).

---

## 8. Testing

The suite is plain PHP — no PHPUnit, no Composer:

```bash
php tests/run.php                 # run everything
php tests/run.php --filter=Router # run matching test classes/methods
php tests/run.php --filter=Integration\AuthFlowTest::testFullOtpLoginLogoutFlow
```

Parallel runs are serialised automatically: `tests/run.php` holds an exclusive
cross-process lock at `storage/temporary/mtaapass-test.lock` (workspace-local and
git-ignored, so different terminals/agents share it) for the whole run. A second
run prints the lock path and the last known holder and waits instead of rebuilding
the shared `_test` database underneath the first one. The wait is bounded by
`TEST_LOCK_TIMEOUT` seconds (default `900`; set `TEST_LOCK_TIMEOUT=0` to fail fast
with exit code 1). Only one suite may run against `mtaapass_test` at a time; the
lock is released automatically by the OS if a runner crashes, so it can never be
left permanently stale.

How it works:

- `tests/bootstrap.php` overrides the environment **before** booting the app:
  `APP_ENV=testing`, `DB_DATABASE=mtaapass_test`, `SMS_DRIVER=mock`,
  `SMS_DEV_EXPOSE_OTP=true`, `RATE_LIMIT_ENABLED=true`, `LOG_LEVEL=error`, and
  defines `MTAAPASS_TEST_SESSION` so the HTTP pipeline runs in-process against an
  array session store.
- The test database is auto-created and **only databases ending in `_test` may be
  rebuilt**; `Migrator::fresh()` refuses anything else, so the development database
  cannot be dropped by accident.
- `tests/run.php` rebuilds the schema and seeds it once, then runs every `test*`
  method in `tests/Unit`, `tests/Integration` and `tests/Security`, printing
  `PASS`/`FAIL` per test and exiting non-zero on any failure.
- Integration and security tests start from a freshly migrated + seeded database
  and clear OTP/throttle rate-limit windows in `setUp()`.
- HTTP helpers dispatch requests through the real kernel, so middleware, CSRF,
  routing, envelopes and error handling are all exercised — no web server needed.

Coverage includes: UUID/phone/crypto/validator/router/migrator/request/helper units;
migration idempotency and checksums; seed inventory and idempotency; health checks;
the full OTP login/logout flow; unauthenticated and permission-denied paths; estate
switching; audit rows and scoping; CSRF failures; OTP rate limits and attempt
lockout; cross-tenant isolation; IDOR on estate UUIDs; security headers; HTML
escaping; session rotation, fingerprinting and expiry. The layout-refresh suite adds
route/auth checks for `/app/visitors` and `/app/bookings`, exact Home markup (hero,
six tiles, announcements + dots, five-slot bottom nav + QR FAB), segmented-tab
behaviour and fallbacks, preview-on/off states, QR-sheet dialog semantics and
credential-free markup, CSP/no-inline and a11y checks (single `h1`, labelled
icon-only buttons, `aria-current`), and XSS escaping of preview/announcement strings.

The Increment 2 suite adds: credential crypto (AES-256-GCM round-trip/context/tamper,
PIN/QR HMAC determinism); invitation create/list/revoke/extend/rotate with validation,
rate limits, dev SMS + audit; PIN and QR verification (direct token and full pass URL)
incl. expiry/revocation/attempt logging; transactional check-in → 409
`ALREADY_INSIDE`, checkout → 409 `NOT_INSIDE`, single-use replay; walk-in
create/approve/reject incl. cross-household denial and `WALKIN_NOT_PENDING`; public
pass page valid/404/expired/revoked/checked-out with no resident PII and page rate
limit; guard dashboard/active lists, no-gate 409 `GATE_CONTEXT_REQUIRED`, unassigned
gate 403 `GATE_NOT_ASSIGNED`, pure-guard `/app → /gate` redirect, noscript guard form
flows; and security checks (plaintext-PIN scan across every DB column, QR token never
in audit/notification logs, CSRF on all new POSTs, QR PNG auth + `no-store`, XSS on
resident/guard/public pages, cross-tenant denial).

The Increment 3 suite adds: plate normalization/equality unit matrix; parking
presenter label/tone and canonical-shape units; vehicle register CRUD scoped to the
resident's household (duplicate 409, cross-household 404, cross-tenant 403/404,
inactive excluded, admin list-all, audit); availability windows (maintenance bay
excluded, accessible bay included, seeded/active reservation overlap excluded,
completed session does not block, half-open boundary, invalid window 422, tenant
scoping); transactional invite+parking reservation (bay/zone/window summary, audit,
`parking_confirmation` SMS without plate), `NO_PARKING_AVAILABLE` rollback (no
invitation/visitor/reservation rows), sequential conflict 409, foreign-vehicle
rejection, revoke cancels reservation; guard check-in starts session/occupies
bay/marks reservation, checkout completes/frees/completes, no-parking check-in
unchanged, parking side effects never fail the visitor check-in, active-sessions
endpoint, explicit session start/stop; real Bookings screen (`?tab=facilities&from=&to=`
with visitor bays, My Bookings = seeded reservation, no preview chip), Vehicles/My
Parking/invite/pass screens with real data; and security checks (CSRF on every new
POST, XSS on vehicle free-text, tenant isolation for vehicles/parking, duplicate and
replay safety).

The Increment 4 suite adds: exact **99-route** table with middleware for every
admin route (incl. the shared admin-area gate); the full access matrix (estate_admin
all, estate_manager bounded: households/buildings/guards yes, residents/settings no,
gates read-only; resident/tenant/guard 403 on every admin route; unauthenticated 302
/login; suspended admin loses access; admin console link only for allowed users);
tenant isolation (Riverside?Greenview lists/uuids, foreign 404 with no leak);
buildings create/list/edit + `BUILDING_EXISTS` duplicate and the documented
no-archive limitation; households create/edit/`UNIT_EXISTS`/status toggle/archive +
archived-household exclusion from the invite selector; household members add
new/existing-user, `MEMBER_EXISTS`, single-primary enforcement,
`PRIMARY_MEMBER_REQUIRED`, soft removal; residents create/link by phone,
`RESIDENT_EXISTS`, invalid phone/role, dev SMS with the configured product name,
suspend ? immediate access loss ? reactivate; guards create-with-gates + assignment
rows + guard login/gate dashboard, assign-gates replacement, archive access loss;
gates create/edit/`GATE_EXISTS`/soft archive + selector exclusion + archived gate
rejected on the visitor verification path; allowlisted settings GET/update (JSON +
audit), `SETTING_NOT_ALLOWED`, range rejection; and security checks (CSRF on all 17
admin POSTs, XSS on every new field, no hard deletes, exact ?2 audit actions with no
phone/name/OTP/PIN, no foreign data or SQL/trace leakage in errors).

The Increment 5 suite adds: dependency-free SMTP protocol tests through injected
transcript I/O (implicit TLS vs STARTTLS, multiline EHLO, AUTH LOGIN + AUTH PLAIN
fallback, MAIL FROM/RCPT/DATA framing + dot-stuffing, 535 auth failure and rejection
mapping with no credential/response leakage, unreachable/timeout handling,
`--check` auth-only flow); `MailManager` driver selection (`log`/`smtp`/`resend`,
unknown/misconfigured fallback, injected-provider seam) and `config/mail.php` SMTP
block parsing; `MailMessage` validation/dedupe/masking; `LogMailProvider` masked
`mail.log` output with no raw address/body/PIN/OTP; `scripts/mail-test.php` CLI
contract (CLI-only, --check never sends, --to requires an explicit valid recipient,
no credentials in output); email alert integration via the log adapter (host email +
fallback dedupe, no-recipient skip, no PII/PIN/token, `email.alert_sent` audit, and a
forced provider failure that is audited as `email.alert_failed` while check-in keeps
working); time-based greeting boundary matrix (hours 4/5/11/12/16/17/21/22 via an
estate-timezone seam, server-rendered/escaped, invalid-timezone Hello fallback); and
PWA install statics (hardened manifest fields/shortcuts/icons, config-driven name,
install-banner hooks, app.js install-state/platform/self-check logic, SW v4 caches +
lifecycle + precache, `.htaccess` SW headers, account installed-state card).

The Increment 6 suite adds (mostly in dedicated parallel-QA files): a **121-route**
exact table incl. the new `PATCH /api/v1/admin/incidents/{uuid}`; resident household
staff CRUD scoping + masked phones + duplicate `STAFF_EXISTS` + archived
`HOUSEHOLD_INACTIVE` + foreign/cross-tenant 404 + CSRF/XSS + `staff.created/updated/deactivated`
audit; incident creation validation (types/severity/occurred-at), resident own-only
visibility, guard session-gate reporting, admin queue filters/detail/status
workflow (resolution notes required, terminal `INCIDENT_CLOSED`), tenant isolation,
no hard deletes, PII-free audit and XSS escaping across resident/guard/admin pages;
high/critical incident email alerts through the log driver (mail.log +
`email.alert_sent`, non-blocking failure) and no-alert for low/medium; migration/health
expectations (7 migrations, 36 tables) and demo staff/incident seed fixtures.

**Test count: 575 test methods** (152 unit, 357 integration, 66 security) across 70
test files (final count verified in the module integration gate). A full run takes
roughly sixteen to eighteen minutes on the local XAMPP/MariaDB setup because every
integration and security test rebuilds the seeded test database.

---

## 9. Known limitations of this increment

- **Bookings is real parking now.** Facilities shows visitor-bay availability for a
  window and My Bookings shows parking reservations; non-parking facility booking
  (clubhouse, gym, etc.) is a later increment.
- **No camera QR scanning in v1.** The guard PWA accepts a typed/pasted PIN or pass
  code; the visitor's QR is a server-rendered image, and scanning/replay-hardening
  for cameras arrives later.
- **The Home QR sheet is a pass shortcut, not a gate credential.** It displays the
  resident's next upcoming pass and links to the real pass screen; real access is
  still the PIN/pass URL verified by a guard.
- **Admin portal is web-form only.** There are no JSON admin APIs in this increment
  (the portal is server-rendered); a mobile/API admin surface is a later increment.
- **Buildings cannot be archived.** The `buildings` table has no status column, so
  buildings are create+edit only (documented contract decision); households carry the
  archive state and must be emptied/moved instead.
- **No platform (SaaS super-admin) portal yet.** Estate provisioning and plan
  management remain later increments; `routes/platform.php` is still a placeholder.
- **Parking bay/allocation administration is deferred.** Bays, zones and resident
  allocations remain seed/API-managed; an admin bay/allocation CRUD UI is a later
  increment (the `parking_manager` permissions are in place).
- **No overstay enforcement job or ANPR/barriers.** Overstay is schema/audit-stub only
  (`parking_violations`), and no automatic fines or boom-barrier integration exist.
- **Staff directory has no accounts or invites.** Household staff are directory
  entries only (no user accounts, OTP login or SMS invitations); they can be verified
  at the gate by name/type. There is no guard-facing staff directory screen beyond
  that verification in this increment.
- **Incidents are text-only.** No photo/evidence upload, no offline queue, no PDF
  export and no automatic fine/barrier integration; incident notifications are audit +
  optional high/critical email alerts only (no SMS).
- **Billing, notices and incidents modules are not installed.** The dashboard
  reports them as `not_installed`; there are no routes for them yet.
- **Walk-in expiry job not implemented.** Pending walk-ins never auto-approve; an old
  request may still display as "awaiting response" until a background job lands.
- **No offline queue yet.** Guard verify/check-in/check-out require connectivity in
  this increment; offline sync is a later increment.
- **Browser install prompts cannot be automated here.** The suite proves the static
  installability criteria, manifest/SW/headers and app hooks; an actual Chromium/iOS
  install must be verified on a real device (and the owner must redeploy for the live
  server to serve the new manifest/SW).
- **Email needs a verified sender domain.** `MAIL_DRIVER=log` is the default locally
  (masked `mail.log` only); production alerts use Resend SMTP with a verified sender
  and the API key as the SMTP password. The provided API key must be
  replaced/verified by the owner, and Resend's sandbox sender only delivers to
  verified recipients. There is no per-user email preference/opt-out yet.
- **Resident PWA palette deviates from Blueprint §17 by product-owner direction**
  (dark navy/royal blue; light theme retained). Guard/admin/platform portals keep
  the blueprint palette in later increments.
- **SMS is development-only.** `log` and `mock` adapters exist; a real provider
  adapter (and webhook authenticity handling) is required before production, and
  the application refuses to start in production with a development adapter.
- **No platform (SaaS super-admin) portal routes yet.** The platform permission
  catalogue and `platform_super_admin` demo account exist, and `routes/platform.php`
  is a documented placeholder.
- **Local MariaDB 10.4 vs production MySQL 8.** The schema avoids MySQL-8-only
  syntax and is tested on MariaDB 10.4; production should be verified on MySQL 8.
- **Sessions use PHP's file store.** No Redis/DB session driver, no horizontal
  session sharing yet.
- **No background queue/worker.** SMS and future notification jobs are sent inline.
- **No backup/restore automation, monitoring or M-Pesa/WhatsApp/barrier
  integrations** — these are later increments.
- **No browser E2E automation.** UI checks are manual/smoke checks; the automated
  suite covers rendered markup and HTTP behaviour in-process.

---

## 10. Next increment

The Increment 6 slices (household staff directory + visitor/security incident
reporting) are delivered on top of the foundation, Visitor/Parking/Admin and
Increment-5 work. The **next increment** is **estate notices** (Increment 7:
migration 9 adding `estate_notices`, published/draft notice feeds for residents and
guards, admin notice authoring/publish/archive, 10 new web routes ? **131** total,
permissions `notice.view`/`notice.manage` 50 ? 52, role links **204 ? 217**, and four
Greenview demo notices).
---

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `APP_KEY is not configured` | Run `php scripts/keygen.php` and put the `APP_KEY=` line in `.env`. |
| `SQLSTATE[HY000] [2002]` connection refused | Check MariaDB is running and `DB_PORT` matches (XAMPP often `3307`). |
| `Module "openssl" is already loaded` | Benign XAMPP warning; ignore it. |
| Test suite refuses to run | The database name must end in `_test` (`mtaapass_test`); never point the test runner at `mtaapass`. |
| Login page shows no OTP | Confirm `SMS_DRIVER=log` and `SMS_DEV_EXPOSE_OTP=true` in development, then check `storage/logs/sms.log`. |
