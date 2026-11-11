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
| RBAC: 50 permissions, 13 roles per estate/platform, 196 role-permission links, permission middleware | Implemented |
| Tenant context resolution (never trusts browser-supplied `estate_id`) | Implemented |
| Audit trail (append-only, estate-scoped reads, credential redaction) | Implemented |
| CSRF protection, security headers/CSP, rate limiting, central error handling | Implemented |
| Health endpoint (database, migrations, storage checks) | Implemented |
| PWA shell: responsive views, manifest, service worker, offline page, icons | Implemented |
| Resident PWA layout refresh (Task 001.1): Home hero + 6 quick tiles, Visitors, Bookings, QR sheet, dark-navy default theme | Implemented (Bookings remains clearly-labelled preview data) |
| **Visitor & Access module (Increment 2)**: invitations, PIN + QR credentials, public pass page, guard PWA (gate selection, verify, check-in/check-out), walk-in approvals, dev SMS + audit | Implemented |
| Parking / billing / notices / incidents modules | **Not installed yet** (next increments) |

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
                          QrCode encoder, public pass), Accounts, Platform,
                          Notifications (feature modules)
app/Integrations/Sms/     SmsProvider interface, Log/Mock adapters, SmsManager
app/Views/                Server-rendered PHP templates + layouts + partials
  app/home.php            Resident Home (hero, quick tiles, announcements)
  app/visitors.php        Real visitor list + pending walk-in approvals
  app/invite.php          Invite-visitor form
  app/pass.php            Resident pass (PIN reveal, QR, revoke/extend)
  app/bookings.php        Bookings list (Facilities / My Bookings)
  public/pass.php         Public /v/{token} pass page (no auth)
  guard/                  Guard PWA screens (dashboard, verify, active, walk-in)
  layouts/app.php         Resident shell (navy, bottom nav + QR FAB)
  layouts/guard.php       Guard shell (high-contrast)
  partials/qr-sheet.php   Shared accessible QR sheet dialog
routes/web.php            HTML routes (resident + guard + public pass)
routes/api.php            JSON API v1 routes
routes/platform.php       Placeholder for the SaaS platform portal (no routes yet)
config/                   app, database, security, sms, logging, ui, visitors
database/migrations/      Five plain-SQL migrations applied in filename order
database/seeds/           Idempotent permission, role, demo and visitor seeders
                          (27 tables, 196 role-permission links after Increment 2)
scripts/                  install / migrate / seed / keygen / generate-icons /
                          generate-estate-hero CLIs
public/assets/css/        app.css (resident design system) + guard.css (guard PWA)
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
| GET | `/app/account` | `auth` |
| GET | `/app/estates` | `auth` |
| POST | `/app/estates/{uuid}/switch` | `auth` |
| GET | `/app/no-estate` | `auth` |
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

Total route table: **60** routes (25 foundation + 35 Increment 2). A pure guard
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
- **Preview data is flag-gated**: the remaining Bookings demo rows are controlled by
  `UI_PREVIEW_CONTENT` and never presented as live records; production pilots must set
  it to `false`.
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

**Test count: 329 test methods** (90 unit, 184 integration, 55 security) across 39
test files (final count verified in the module integration gate). A full run takes
roughly eight to ten minutes on the local XAMPP/MariaDB setup because every
integration and security test rebuilds the seeded test database.

---

## 9. Known limitations of this increment

- **Bookings is still a layout screen.** It renders clearly-labelled
  `Layout preview` facility rows (`UI_PREVIEW_CONTENT`) and honest empty states;
  facility booking and availability are a later increment.
- **No camera QR scanning in v1.** The guard PWA accepts a typed/pasted PIN or pass
  code; the visitor's QR is a server-rendered image, and scanning/replay-hardening
  for cameras arrives later.
- **The Home QR sheet is a pass shortcut, not a gate credential.** It displays the
  resident's next upcoming pass and links to the real pass screen; real access is
  still the PIN/pass URL verified by a guard.
- **Parking, billing, notices and incidents modules are not installed.** The
  dashboard reports them as `not_installed`; there are no routes for them yet.
- **Walk-in expiry job not implemented.** Pending walk-ins never auto-approve; an old
  request may still display as "awaiting response" until a background job lands.
- **No offline queue yet.** Guard verify/check-in/check-out require connectivity in
  this increment; offline sync is a later increment.
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

The Visitor & Access module (Increment 2) is delivered on top of the foundation
and layout refresh. The **next increment** is **vehicles/parking plus estate-admin
management**: vehicle records and resident parking allocations, parking bays,
visitor reservations/sessions, estate/household/gate administration screens and the
matching permissions wiring — building on the tenant context, RBAC, audit trail and
the visitor credential/check-in patterns delivered here.

---

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `APP_KEY is not configured` | Run `php scripts/keygen.php` and put the `APP_KEY=` line in `.env`. |
| `SQLSTATE[HY000] [2002]` connection refused | Check MariaDB is running and `DB_PORT` matches (XAMPP often `3307`). |
| `Module "openssl" is already loaded` | Benign XAMPP warning; ignore it. |
| Test suite refuses to run | The database name must end in `_test` (`mtaapass_test`); never point the test runner at `mtaapass`. |
| Login page shows no OTP | Confirm `SMS_DRIVER=log` and `SMS_DEV_EXPOSE_OTP=true` in development, then check `storage/logs/sms.log`. |
