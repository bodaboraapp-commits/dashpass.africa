# MtaaPass — Foundation Increment (Blueprint Task 001) — Integration Contract

**Status:** binding for this increment. Read fully before writing code.
**Source of truth:** `C:\Users\user\Downloads\MtaaPass_Product_Requirements_and_Implementation_Blueprint.md` (v1.0). If this contract and the blueprint disagree, the blueprint wins for product requirements; this contract wins for exact names/payloads.
**Scope guard:** Build **only** Task 001 (§70). Do not build visitor, parking, billing or notification features. Do not mark a screen complete because it renders.

## 0. Environment facts

- Workspace: `C:\xampp\htdocs\www\MtaaPASS` (already scaffolded; do not recreate).
- PHP CLI: `php` (8.2.12, XAMPP). Production target is PHP 8.3+, so avoid 8.3-only syntax.
- MySQL/MariaDB: already running at `127.0.0.1:3307`, user `root`, empty password.
  - Development DB: `mtaapass`
  - Test DB: `mtaapass_test` (only DB the test runner may drop)
  - Do **not** start/stop MySQL services or Apache.
- `.env` exists with `APP_KEY`, `DB_PORT=3307`. Never commit or print secrets.
- A benign `Module "openssl" is already loaded` warning is printed on stderr by this XAMPP build; ignore it.
- Lint every PHP file you create: `php -l path\to\file.php`.

## 1. Ground rules (non-negotiable, from Blueprint §0 and §52)

1. `declare(strict_types=1);` in every PHP file. Plain PHP 8.2+ / PDO / MySQL only. **No Composer, no Node build, no frameworks.**
2. PDO prepared statements only. No string-interpolated user input in SQL. No dynamic SQL identifiers from input.
3. Never trust `estate_id`, `user_id`, role, permission or price from the browser. Derive from `App\Core\Context` (`$context->auth`, `$context->tenant`, `$context->permission`).
4. Escape every dynamic HTML output with `e()`. No `echo $raw`.
5. No inline `<script>` or inline `style="..."` (CSP: `script-src 'self'; style-src 'self'`). All JS/CSS in `public/assets`.
6. Never log or audit raw OTPs, PINs, passwords, tokens or full session ids.
7. Keep tenant scoping inside repositories/services, not only in UI.
8. Stay inside your assigned write scope. If a shared file needs a change, report it to the Lead instead of editing it.
9. Report back: files created/changed, commands run, results, blockers. Be concise.

## 2. Existing foundation (already written by Lead — do not modify)

```
app/helpers.php            e(), url(), asset(), env(), config(), csrf_token(), csrf_field(), format_datetime()
app/bootstrap.php          returns App\Core\App (loads .env, config, autoloader, routes/web|api|platform)
app/Core/
  Env Config Clock Uuid Crypto Phone Database Request Response Session Csrf
  HttpException ValidationException Logger ErrorHandler Router MiddlewareResolver
  Context Auth TenantContext Permission Validator RateLimiter AuditLogger View Migrator App
app/Middleware/
  SecurityHeaders CsrfProtection Authenticate GuestOnly EnsureEstate RequirePermission Throttle
config/  app.php database.php security.php sms.php logging.php
```

Key API you must use (exact signatures):

```php
// Handlers registered in routes/*.php
function (App\Core\Request $request, array $params, App\Core\Context $context): App\Core\Response

// Context services
$context->db            // App\Core\Database: select/selectOne/scalar/execute/insert/update/transaction
$context->auth          // check(), user(): ?array, requireUser(): array, login(int $userId), logout(), id(): ?int
$context->tenant        // memberships(), active(): ?array, requireEstate(): array, estateId(): ?int,
                        // switchTo(string $estateUuid): array, assertScope(int $estateId): int, roleName()
$context->permission    // can(slug), authorize(slug), slugs(), platformCan(slug), platformSlugs(), authorizePlatform()
$context->audit         // record(action, entityType, entityId=null, metadata=[], estateId=null, actorId=null)
$context->rateLimiter   // attempt(key,max,window): array{allowed,remaining,retry_after,hits}, clear(key), clearPrefix(prefix)
$context->correlationId // uuid string
$context->request       // App\Core\Request

// Static helpers
Session::get/put/forget/pull/pullFlash/flash/destroy/regenerate()
Csrf::token() / Csrf::regenerate() / Csrf::check($token)
View::render('app/home', $data, 'app'): Response
Response::success($message, $data, $status=200) / Response::error($code,$message,$status,$extra,$correlationId)
Response::redirect($to, 302) / Response::html() / Response::json() / Response::noContent()
Clock::nowDb() / Clock::toDb($dt) / Clock::parse(iso) / Clock::fromDb($str)
Uuid::v4() / Uuid::isValid()
Crypto::randomToken() / Crypto::numericCode($len) / Crypto::hmac($context,$value) / Crypto::equals($known,$given)
Phone::normalize($raw) / Phone::isValid($e164) / Phone::display($e164) / Phone::mask($e164)
Validator::validate($data, $rules, $labels=[]): array  // throws ValidationException(422)
HttpException::badRequest/unauthorized/forbidden/notFound/conflict/tooManyRequests
Logger::info/warning/error/security($message, array $context)
```

Route registration (already wired in `app/Core/App.php`):

```php
$router->get('/path', [App\Modules\X\XController::class, 'method'], ['auth', 'estate', 'permission:audit.view', 'throttle:name,60,60']);
```

Middleware aliases: `auth`, `guest`, `estate`, `permission:<slug>`, `throttle:<name>,<max>,<windowSeconds>`.
CSRF is global: every non-GET request needs `X-CSRF-Token` header or `_token` form field. Exempt: `/health`, `/api/v1/health`.

## 3. Agent write scopes (disjoint — never cross)

| Agent | Task | Owns (write) |
|---|---|---|
| Lead | T0 | `app/Core/**`, `app/Middleware/**`, `app/helpers.php`, `app/bootstrap.php`, `config/**`, `.env*`, `.gitignore`, `docs/**` |
| schema-seed | T1 | `database/**`, `scripts/migrate.php`, `scripts/seed.php`, `scripts/install.php`, `scripts/keygen.php`, `storage/**` |
| pwa-shell | T2 | `app/Views/**`, `public/**`, `scripts/generate-icons.php` |
| modules | T3 | `app/Modules/**`, `routes/**` |
| tests | T4/T5 | `tests/**`, `README.md` |

Read-only for everyone: the contract, `app/Core/**`, `config/**`, `.env`.

## 4. Database schema (T1 — exact)

Migrations (SQL files, applied in filename order by `App\Core\Migrator`; one batch, tracked in `schema_migrations`):

```
database/migrations/20261111_000001_create_saas_and_estate_tables.sql
database/migrations/20261111_000002_create_identity_and_rbac_tables.sql
database/migrations/20261111_000003_create_estate_structure_tables.sql
database/migrations/20261111_000004_create_security_and_audit_tables.sql
```

Conventions: InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, `BIGINT UNSIGNED` ids, `uuid CHAR(36)` public ids (unique), timestamps UTC `DATETIME(3)` with `DEFAULT CURRENT_TIMESTAMP(3)` and `ON UPDATE CURRENT_TIMESTAMP(3)` where mutable. MariaDB 10.4 must accept the SQL (avoid MySQL-8-only syntax such as functional indexes, `CHECK` enforcement dependencies, or `ANY_VALUE` in DDL). Every FK needs an index. Where a child row must not cross tenants, use composite FKs as specified.

### 4.1 SaaS + estates
`saas_accounts`: id, uuid UNIQUE, name VARCHAR(180), status ENUM('active','suspended','closed') DEFAULT 'active', created_at, updated_at.
`estates`: id, uuid UNIQUE, account_id, name, slug VARCHAR(180) UNIQUE, timezone VARCHAR(80) DEFAULT 'Africa/Nairobi', estate_type ENUM('apartment','maisonette','mixed') DEFAULT 'mixed', status ENUM('active','suspended','archived') DEFAULT 'active', created_at, updated_at. UNIQUE(id, account_id). INDEX(account_id, status).
`estate_settings`: id, estate_id, setting_key VARCHAR(120), setting_value JSON NULL, created_at, updated_at. UNIQUE(estate_id, setting_key).
`subscription_plans`: id, uuid UNIQUE, code VARCHAR(60) UNIQUE, name VARCHAR(120), monthly_price_cents INT UNSIGNED, currency CHAR(3) DEFAULT 'KES', household_limit INT UNSIGNED NULL, gate_device_limit SMALLINT UNSIGNED NULL, features JSON NULL, status ENUM('active','retired') DEFAULT 'active', created_at, updated_at.
`subscriptions`: id, uuid UNIQUE, estate_id, plan_id, status ENUM('trialing','active','past_due','suspended','cancelled','expired') DEFAULT 'trialing', billing_cycle ENUM('monthly','annual') DEFAULT 'monthly', starts_at, ends_at NULL, trial_ends_at NULL, created_at, updated_at. INDEX(estate_id, status).

### 4.2 Identity + RBAC
`users`: id, uuid UNIQUE, full_name VARCHAR(180), phone VARCHAR(20) UNIQUE, email VARCHAR(190) NULL, password_hash VARCHAR(255) NULL, status ENUM('active','pending','suspended') DEFAULT 'pending', last_login_at NULL, created_at, updated_at.
`roles`: id, scope ENUM('platform','estate'), name VARCHAR(80), description VARCHAR(180) NULL. UNIQUE(scope, name).
`permissions`: id, slug VARCHAR(100) UNIQUE, name VARCHAR(150), scope ENUM('platform','estate').
`role_permissions`: role_id, permission_id, PRIMARY KEY(role_id, permission_id).
`estate_memberships`: id, uuid UNIQUE, estate_id, user_id, role_id, status ENUM('active','pending','suspended') DEFAULT 'active', created_at, updated_at. UNIQUE(estate_id, user_id). INDEX(user_id, status).
`platform_memberships`: id, uuid UNIQUE, user_id, role_id, status ENUM('active','suspended') DEFAULT 'active', created_at, updated_at. UNIQUE(user_id, role_id).

### 4.3 Estate structure
`buildings`: id, uuid UNIQUE, estate_id, name VARCHAR(120), building_type ENUM('apartment_block','street','cluster','zone') DEFAULT 'apartment_block', created_at. UNIQUE(estate_id, name), UNIQUE(id, estate_id).
`households`: id, uuid UNIQUE, estate_id, building_id NULL, unit_number VARCHAR(60), household_type ENUM('apartment','maisonette','other') DEFAULT 'apartment', status ENUM('active','inactive') DEFAULT 'active', created_at. UNIQUE(estate_id, unit_number), UNIQUE(id, estate_id). FK (building_id, estate_id) -> buildings(id, estate_id). (building_id nullable, so the composite FK is not enforced when NULL — that is fine.)
`household_members`: id, uuid UNIQUE, estate_id, household_id, user_id, relationship VARCHAR(60) NULL, is_primary TINYINT(1) DEFAULT 0, status ENUM('active','inactive') DEFAULT 'active', created_at, updated_at. UNIQUE(household_id, user_id). FK (household_id, estate_id) -> households(id, estate_id). INDEX(user_id, status).
`gates`: id, uuid UNIQUE, estate_id, name VARCHAR(100), location VARCHAR(180) NULL, status ENUM('active','inactive') DEFAULT 'active', created_at. UNIQUE(estate_id, name), UNIQUE(id, estate_id).
`guard_gate_assignments`: id, uuid UNIQUE, estate_id, gate_id, guard_user_id, shift VARCHAR(40) NULL, status ENUM('active','inactive') DEFAULT 'active', created_at. UNIQUE(gate_id, guard_user_id). FK (gate_id, estate_id) -> gates(id, estate_id), FK guard_user_id -> users(id).

### 4.4 Security + audit
`audit_logs` (append-only; no FK to keep history independent): id, uuid UNIQUE, estate_id NULL, actor_id NULL, action VARCHAR(100), entity_type VARCHAR(80), entity_id BIGINT UNSIGNED NULL, metadata JSON NULL, correlation_id CHAR(36) NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3). INDEX(estate_id, created_at), INDEX(actor_id, created_at), INDEX(action, created_at).
`otp_challenges`: id, uuid UNIQUE, phone VARCHAR(20), purpose ENUM('login','staff_login') DEFAULT 'login', user_id NULL, otp_hash CHAR(64), expires_at, attempts SMALLINT UNSIGNED DEFAULT 0, max_attempts SMALLINT UNSIGNED DEFAULT 5, consumed_at NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at. INDEX(phone, purpose, created_at), INDEX(expires_at).
`rate_limits`: rl_key VARCHAR(160) PRIMARY KEY, window_started_at DATETIME(3), hits INT UNSIGNED, expires_at DATETIME(3). INDEX(expires_at).
`notification_logs`: id, uuid UNIQUE, estate_id NULL, channel ENUM('sms','push','in_app') DEFAULT 'sms', recipient VARCHAR(190), template VARCHAR(80) NULL, provider VARCHAR(40) NULL, provider_message_id VARCHAR(120) NULL, status ENUM('queued','sent','failed','delivered') DEFAULT 'queued', error VARCHAR(500) NULL, sent_at NULL, created_at. INDEX(estate_id, created_at), INDEX(status).

`schema_migrations` is created by `Migrator` — do not create it in SQL.

## 5. Seed data (T1 — exact, idempotent, demo-labelled)

Layout:

```
database/seeds/run.php          // returns callable that seeds everything, in order
database/seeds/Seeder.php       // base class: db(), helpers, idempotent upserts
database/seeds/PermissionsSeeder.php
database/seeds/RolesSeeder.php
database/seeds/DemoSeeder.php
```

`database/seeds/run.php` must `require` its sibling classes and return `callable` (no autoloader for this namespace). Both `scripts/seed.php` and `tests/bootstrap.php` do:

```php
$seed = require BASE_PATH . '/database/seeds/run.php';
$seed(); // idempotent
```

**Permissions** — seed exactly these slugs (scope `platform` for `platform.*`, else `estate`), human-readable `name`:

```
estate.view, estate.settings.manage, estate.user.manage,
household.view, household.manage, household.member.manage, household.staff.manage,
visitor.invitation.create, visitor.invitation.view_own, visitor.invitation.view_all,
visitor.invitation.revoke, visitor.invitation.extend,
visitor.walkin.create, visitor.walkin.approve, visitor.walkin.reject,
visitor.checkin, visitor.checkout, visitor.manual_override,
visitor.history.view_own, visitor.history.view_all,
visitor.incident.create, visitor.incident.manage,
gate.view, gate.manage, guard.manage,
vehicle.view_own, vehicle.manage_own, vehicle.view_all, vehicle.manage_all,
parking.view, parking.bay.manage, parking.allocation.manage,
parking.reservation.create, parking.reservation.manage,
parking.session.checkin, parking.session.checkout, parking.violation.manage,
reports.access.view, reports.parking.view, reports.security.view, audit.view,
billing.subscription.view, billing.subscription.manage, billing.invoice.view, billing.payment.manage,
platform.estate.manage, platform.plan.manage, platform.subscription.manage,
platform.support.manage, platform.audit.view
```

**Roles** (scope: platform for the four `platform_*`, estate for the rest) and exact permission mapping:

- `platform_super_admin` → ALL permissions (estate + platform).
- `platform_operations` → platform.estate.manage, platform.subscription.manage, platform.support.manage, billing.subscription.view, reports.access.view.
- `platform_support` → platform.support.manage, platform.audit.view.
- `platform_finance` → billing.subscription.view, billing.invoice.view, billing.payment.manage, billing.subscription.manage, platform.subscription.manage.
- `estate_admin` → estate.view, estate.settings.manage, estate.user.manage, household.view, household.manage, household.member.manage, household.staff.manage, visitor.invitation.view_all, visitor.invitation.revoke, visitor.walkin.approve, visitor.walkin.reject, visitor.history.view_all, visitor.incident.create, visitor.incident.manage, gate.view, gate.manage, guard.manage, vehicle.view_all, vehicle.manage_all, parking.view, parking.bay.manage, parking.allocation.manage, parking.reservation.manage, parking.violation.manage, reports.access.view, reports.parking.view, reports.security.view, audit.view, billing.subscription.view.
- `estate_manager` → estate.view, household.view, household.manage, household.member.manage, household.staff.manage, visitor.invitation.view_all, visitor.walkin.approve, visitor.walkin.reject, visitor.history.view_all, visitor.incident.create, visitor.incident.manage, gate.view, guard.manage, vehicle.view_all, parking.view, parking.allocation.manage, parking.reservation.manage, reports.access.view, reports.parking.view, reports.security.view, audit.view, billing.subscription.view.
- `security_supervisor` → estate.view, gate.view, gate.manage, guard.manage, visitor.invitation.view_all, visitor.checkin, visitor.checkout, visitor.walkin.create, visitor.walkin.approve, visitor.manual_override, visitor.history.view_all, visitor.incident.create, visitor.incident.manage, vehicle.view_all, parking.view, parking.session.checkin, parking.session.checkout, reports.security.view.
- `security_guard` → gate.view, visitor.checkin, visitor.checkout, visitor.walkin.create, visitor.incident.create, vehicle.view_all, parking.view, parking.session.checkin, parking.session.checkout.
- `parking_manager` → estate.view, vehicle.view_all, parking.view, parking.bay.manage, parking.allocation.manage, parking.reservation.manage, parking.session.checkin, parking.session.checkout, parking.violation.manage, reports.parking.view.
- `resident` → estate.view, household.view, household.member.manage, visitor.invitation.create, visitor.invitation.view_own, visitor.invitation.revoke, visitor.invitation.extend, visitor.history.view_own, vehicle.view_own, vehicle.manage_own, parking.view, parking.reservation.create.
- `tenant` → estate.view, household.view, visitor.invitation.create, visitor.invitation.view_own, visitor.invitation.revoke, visitor.invitation.extend, visitor.history.view_own, vehicle.view_own, vehicle.manage_own, parking.view, parking.reservation.create.
- `household_member` → estate.view, household.view, visitor.invitation.create, visitor.invitation.view_own, visitor.history.view_own, vehicle.view_own.
- `auditor` → estate.view, household.view, gate.view, visitor.invitation.view_all, visitor.history.view_all, vehicle.view_all, parking.view, reports.access.view, reports.parking.view, reports.security.view, audit.view.

**Demo data** (all users `status='active'`; estate names/labels clearly demo):

SaaS account `Greenview Holdings Ltd`. Plans: `starter` Starter 250000c/50hh/2dev; `growth` Growth 500000c/150hh/5dev; `premium` Premium 900000c/300hh/10dev. Active monthly Growth subscription for each estate.

Estates: `Greenview Residences` slug `greenview-residences` type `mixed`; `Riverside Court` slug `riverside-court` type `apartment`. Both timezone `Africa/Nairobi`.
Estate settings (Greenview): `visitor_validity_minutes=240`, `visitor_parking_grace_minutes=30`, `walkin_approval_timeout_seconds=300`, `max_visitors_per_invitation=5`.

Buildings Greenview: Block A / Block B (`apartment_block`), Willow Court (`cluster`), Acacia Street (`street`). Riverside: Tower 1 (`apartment_block`).
Households Greenview (unit_number / building): A-001..A-006 / Block A, B-001..B-003 / Block B, W-01, W-02 / Willow Court, S-01 / Acacia Street. Riverside: R-101, R-102 / Tower 1.
Gates: Greenview `Main Gate` (location "Off Greenview Road"), `Service Gate`; Riverside `Riverside Gate`.

Users (phone → name → membership role → additional):

| Phone | Name | Membership | Extra |
|---|---|---|---|
| +254700000001 | Grace Wanjiku | estate_admin @ Greenview **and** Riverside | estate switcher demo |
| +254700000002 | Peter Ochieng | estate_manager @ Greenview | |
| +254700000003 | Samuel Kariuki | security_supervisor @ Greenview | |
| +254700000004 | John Mutiso | security_guard @ Greenview | assigned Main Gate |
| +254700000005 | David Kiptoo | security_guard @ Greenview | assigned Service Gate |
| +254700000006 | Faith Achieng | security_guard @ Greenview | assigned Main Gate |
| +254700000007 | Brian Ochieng | security_guard @ Greenview | assigned Service Gate |
| +254700000008 | Mary Atieno | parking_manager @ Greenview | |
| +254700000009 | Lucy Wambui | auditor @ Greenview | |
| +254700000010 | Jane Smith | resident @ Greenview | household A-001, primary |
| +254700000011 | Brian Otieno | tenant @ Greenview | household A-002, primary |
| +254700000012 | Lydia Njeri | household_member @ Greenview | household A-002, member |
| +254700000013 | Amina Hassan | resident @ Greenview | household B-001, primary |
| +254700000014 | David Njoroge | estate_admin @ Riverside **only** | cross-tenant test account |
| +254700000020 | Daniel Mwangi | platform_super_admin | platform membership |

Idempotency: look up by natural keys (phone for users, slug for estates/plans/gates/buildings/household unit_number, slug/name for roles/permissions) and only insert/update what is missing. Re-running `php scripts/seed.php` must not duplicate rows or change counts.

## 6. SMS provider abstraction (T3)

```
app/Integrations/Sms/SmsProvider.php        interface SmsProvider { send(string $phone, string $message): array; getDeliveryStatus(string $messageId): array; }
app/Integrations/Sms/LogSmsProvider.php     development adapter -> storage/logs/sms.log (JSON lines)
app/Integrations/Sms/MockSmsProvider.php    in-memory records for tests (static reset(), messages())
app/Integrations/Sms/SmsManager.php         make(?string $driver=null): SmsProvider ; driver(): string
app/Modules/Notifications/NotificationLogger.php  logSms(estateId, recipient, template, provider, providerMessageId, status, error): void
```

`SmsManager` must throw `RuntimeException` when `APP_ENV=production` and the driver is `log` or `mock` (no silent fake SMS in production). `send()` returns `['message_id' => string, 'status' => 'queued'|'sent', 'driver' => string]`. Provider failure is caught by the caller and logged; it must not roll back the OTP challenge.

## 7. OTP + auth services (T3 — exact behaviour)

`app/Modules/Authentication/UserRepository.php`: `findActiveByPhone(string $e164): ?array`, `findById(int $id): ?array`, `findByPhone(string $e164): ?array`.

`app/Modules/Authentication/OtpService.php`:

```php
public static function make(): self            // wires Database::instance(), SmsManager::make(), NotificationLogger
public function request(string $e164Phone, string $purpose, string $ip, ?string $userAgent = null): array
public function verify(string $e164Phone, string $code, string $purpose, string $ip): int  // returns user id
```

`request()`:
1. Apply rate limits in this order, each via `RateLimiter::attempt()` with keys:
   - `otp:request:cooldown:` . `hash('sha256', $phone)` → max **1**, window `AUTH_OTP_RESEND_COOLDOWN_SECONDS` (60).
   - `otp:request:phone:` . `hash('sha256', $phone)` → max/window from `security.rate_limits.otp_request_phone` (5/900).
   - `otp:request:ip:` . $ip → max/window from `otp_request_ip` (20/900).
   On denial throw `HttpException::tooManyRequests('Please wait before requesting another code.', $retryAfter)` (429 `RATE_LIMITED`).
2. Generate a code with `Crypto::numericCode(length)`. Length from `security.otp.length` (6).
3. Resolve the user. If none: return generic `['requested' => true, 'expires_in' => ttl]`; **do not** create a challenge or reveal absence (in non-production, add `'dev_note' => 'No active MtaaPass account is registered for this number (development mode).'`).
4. In one transaction: mark previous unconsumed `otp_challenges` for (phone,purpose) as consumed (`consumed_at = now`), insert challenge `{uuid, phone, purpose, user_id, otp_hash = Crypto::hmac('otp:' . $challengeUuid, $code), expires_at = now + ttl, attempts = 0, max_attempts, ip_address, user_agent, created_at}`.
5. **After commit** send SMS via provider using template:
   `MtaaPass verification code: {code}. Expires in {minutes} minutes. Never share this code.`
   Record in `notification_logs` (template `otp`, status `sent`/`failed`).
6. Return `['requested' => true, 'expires_in' => ttl, 'dev_otp' => $code, 'dev_note' => 'Development OTP adapter — never enabled in production.']` **only when** `app.env !== 'production'` and `sms.expose_development_otp` is true. Otherwise omit `dev_otp` and `dev_note`.

`verify()`:
1. Rate limit `otp:verify:phone:` . `hash('sha256',$phone)` (10/900) and `otp:verify:ip:` . $ip (30/900). Denial → 429 `RATE_LIMITED`.
2. In a transaction, `SELECT ... FOR UPDATE` the latest unconsumed challenge for (phone, purpose) ordered by `created_at DESC, id DESC LIMIT 1`.
3. No challenge, expiry passed, or already consumed → 422 `INVALID_OTP` "The verification code is invalid or has expired."
4. `attempts >= max_attempts` → 429 `OTP_ATTEMPTS_EXCEEDED` ("Too many incorrect attempts. Request a new code.").
5. Compare `Crypto::equals($storedHash, Crypto::hmac('otp:' . $challengeUuid, $code))`. Mismatch → increment attempts, 422 `INVALID_OTP`.
6. Success → set `consumed_at`, increment attempts, return the challenge `user_id` (must exist and be `active`; otherwise 422 `INVALID_OTP`).

Never audit or log the plaintext code.

## 8. Routes and controllers (T3 — exact)

`routes/web.php` (HTML; all POST are CSRF-protected globally):

| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/` | `AuthController::webWelcome` | — (redirects `/app` when signed in) |
| GET | `/login` | `AuthController::webLogin` | `guest` |
| POST | `/login/otp` | `AuthController::webRequestOtp` | `guest`, `throttle:web-login,20,60` |
| POST | `/login/verify` | `AuthController::webVerifyOtp` | `guest`, `throttle:web-verify,30,60` |
| POST | `/logout` | `AuthController::webLogout` | `auth` |
| GET | `/app` | `ResidentController::webHome` | `auth`, `estate` |
| GET | `/app/account` | `AccountController::webAccount` | `auth` |
| GET | `/app/estates` | `EstateController::webEstateSwitcher` | `auth` |
| POST | `/app/estates/{uuid}/switch` | `EstateController::webSwitch` | `auth` |
| GET | `/app/no-estate` | `AuthController::webNoEstate` | `auth` |
| GET | `/health` | `HealthController::show` | — (JSON) |

`routes/api.php` (JSON, prefix `/api/v1`):

| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/api/v1/health` | `HealthController::show` | — |
| GET | `/api/v1/auth/csrf` | `AuthController::apiCsrf` | — |
| POST | `/api/v1/auth/request-otp` | `AuthController::apiRequestOtp` | `throttle:otp-request,20,60` |
| POST | `/api/v1/auth/verify-otp` | `AuthController::apiVerifyOtp` | `throttle:otp-verify,30,60` |
| POST | `/api/v1/auth/logout` | `AuthController::apiLogout` | `auth` |
| GET | `/api/v1/auth/me` | `AuthController::apiMe` | `auth` |
| GET | `/api/v1/estates` | `EstateController::apiIndex` | `auth` |
| POST | `/api/v1/estates/{uuid}/switch` | `EstateController::apiSwitch` | `auth` |
| GET | `/api/v1/estate` | `EstateController::apiShow` | `auth`, `estate`, `permission:estate.view` |
| GET | `/api/v1/estate/settings` | `EstateController::apiSettings` | `auth`, `estate`, `permission:estate.settings.manage` |
| GET | `/api/v1/estate/audit-logs` | `EstateController::apiAuditLogs` | `auth`, `estate`, `permission:audit.view` |
| GET | `/api/v1/resident/dashboard` | `ResidentController::apiDashboard` | `auth`, `estate`, `permission:estate.view` |

`routes/platform.php`: file must exist with a comment; no routes yet.

Controller classes: `App\Modules\Authentication\AuthController`, `App\Modules\Residents\ResidentController`, `App\Modules\Estates\EstateController`, `App\Modules\Accounts\AccountController`, `App\Modules\Platform\HealthController`. Repositories: `App\Modules\Estates\EstateRepository`, `App\Modules\Households\HouseholdRepository`, `App\Modules\Residents\DashboardService`.

### 8.1 Payload shapes (T2 views and T4 tests rely on these)

`GET /api/v1/auth/me` data:
```json
{
  "user": {"uuid": "...", "full_name": "...", "phone": "+254...", "email": null},
  "memberships": [
    {"estate_uuid": "...", "estate_name": "...", "estate_slug": "...", "estate_type": "mixed",
     "estate_timezone": "Africa/Nairobi", "role_name": "resident", "is_active": false}
  ],
  "active_estate": {"estate_uuid": "...", "estate_name": "...", "estate_slug": "...",
                    "estate_type": "mixed", "estate_timezone": "Africa/Nairobi", "role_name": "resident"},
  "permissions": ["estate.view", "household.view"],
  "csrf_token": "hex string"
}
```
`active_estate` is `null` when the user has no memberships.

`POST /api/v1/auth/verify-otp` (and `request-otp`) success data: OTP request returns
`{"requested": true, "expires_in": 300, "dev_otp": "123456", "dev_note": "..."}` (dev-only fields);
`verify-otp` returns the same object as `/auth/me` plus `"redirect": "/app"`.

`POST /api/v1/auth/logout` → 204 no content.

`GET /api/v1/estates` data: `{"memberships": [ ...same membership shape... ]}`.

`POST /api/v1/estates/{uuid}/switch` data: `{"estate": {...active_estate shape...}, "permissions": [...], "csrf_token": "..."}`. Invalid/unauthorised estate uuid → 404 `ESTATE_NOT_FOUND` (no enumeration).

Estate summary shape (used by `/api/v1/estate`, dashboard `estate`, switch `estate`):
```json
{"estate_uuid":"...","estate_name":"...","estate_slug":"...","estate_type":"mixed",
 "estate_timezone":"Africa/Nairobi","role_name":"resident","gate_count":2,
 "household_count":12,"member_count":8,"plan_name":"Growth","subscription_status":"active"}
```

`GET /api/v1/estate/settings` data: `{"settings": {"visitor_validity_minutes": 240, ...}}` (decoded values).

`GET /api/v1/estate/audit-logs` data: `{"logs": [{"uuid","action","entity_type","entity_id","metadata":{},"correlation_id","ip_address","created_at","actor_name"}], "limit": 50}`; query `?limit=` optional, default 50, clamp 1..200. Audit rows are filtered by the **resolved active estate id only**.

`GET /api/v1/resident/dashboard` data:
```json
{
  "estate": { ...estate summary... },
  "memberships": [ ...membership shape... ],
  "households": [{"household_uuid":"...","unit_number":"A-001","household_type":"apartment",
                  "building_name":"Block A","relationship":"owner","is_primary":true}],
  "gates": [{"name":"Main Gate","status":"active"}],
  "counts": {"households": 12, "buildings": 4, "gates": 2, "members": 14},
  "modules": {"visitors": "not_installed", "parking": "not_installed", "notifications": "not_installed"},
  "announcements": []
}
```

Response envelope: use `Response::success($message, $data)` / `Response::error($code,$message,$status)` from the controller. Keep controller code thin; put logic in services/repositories.

### 8.2 Web page data (T2 renders, T3 passes)

Every authenticated page controller builds a `$shell` array and passes it to `View::render($view, $shell + $viewData, 'app')`:

```php
$shell = [
  'title' => 'Home',                       // string
  'user' => ['full_name'=>..., 'phone'=>...],   // array
  'estate' => $context->tenant->active(),   // ?array (membership shape)
  'memberships' => $context->tenant->memberships(),
  'activeNav' => 'home',                    // home|activity|inbox|account
  'csrfToken' => Csrf::token(),
  'appVersion' => (string) config('app.version'),
  'isDev' => config('app.env') !== 'production',
  'flashError' => Session::pullFlash('error'),      // ?string
  'flashSuccess' => Session::pullFlash('success'),  // ?string
  'permissions' => $context->permission->slugs(),
];
```

Guest layout pages (`/`, `/login`, `/app/no-estate` when using guest layout) receive:
`['title','content','csrfToken','appVersion','isDev','error','errors','phone','devOtp','devNote','step']` as applicable.

Views T2 must implement (paths relative to `app/Views/`, layout argument given):

| View | Layout | Variables |
|---|---|---|
| `auth/welcome.php` | `guest` | `$csrfToken,$appVersion,$isDev` |
| `auth/login.php` | `guest` | `$step` ('phone'\|'verify'), `$phone`, `$devOtp`, `$devNote`, `$error`, `$errors`, `$csrfToken,$appVersion,$isDev` |
| `app/home.php` | `app` | `$dashboard` (shape 8.1) + shell |
| `app/account.php` | `app` | shell + `$user` |
| `app/estates.php` | `app` | shell |
| `app/no-estate.php` | `guest` | `$csrfToken,$appVersion,$isDev` |
| `errors/error.php` | none (full page) | `$status,$code,$message,$correlationId,$debug,$debugMessage` |
| `layouts/app.php`, `layouts/guest.php` | — | see shell |

`errors/error.php` is rendered directly by `App\Core\ErrorHandler` via `View::capture('errors/error', $data)` — it must be a complete `<html>` document and must not assume a layout.

Web login flow (T3): `POST /login/otp` form field `phone`; on success store `login_phone` in session, flash `dev_otp`/`dev_note` if present, redirect `/login` (the login screen renders its verification step whenever session has `login_phone`, because the fixed route table has no `GET /login/verify`); `POST /login/verify` fields `phone` (hidden) and `code`; on success `AuditLogger::record('auth.login','user',$id)` then redirect to session `intended_url` (validated to start with `/`, never `//`) else `/app`; on failure flash `error` and redirect `/login`. `POST /logout` → audit `auth.logout` → `Auth::logout()` → redirect `/login`.

`GET /` when authenticated redirects to `/app`. `GET /app/no-estate` explains the account has no active estate membership.

## 9. Health endpoint (T3)

`HealthController::show` returns JSON directly (no envelope), status 200 when healthy, 503 when the database check fails:

```json
{"status":"ok","app":"MtaaPass","version":"0.1.0-foundation","environment":"local",
 "time_utc":"2026-11-11T08:00:00.000Z",
 "checks":{"database":{"status":"ok","latency_ms":1.2},
           "migrations":{"status":"ok","applied":4,"pending":0},
           "storage":{"status":"ok"}}}
```

Storage check: `storage/logs` exists and is writable. Never expose config values, credentials or paths.

## 10. PWA shell (T2 — exact deliverables)

- `public/index.php` front controller:
```php
<?php
declare(strict_types=1);
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) { return false; }
}
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->handle(App\Core\Request::fromGlobals())->send();
```
- `public/manifest.json` exactly per Blueprint §46 (id/name/short_name/description/start_url/scope/display standalone/background_color `#F6F5F0`/theme_color `#123D35`/orientation portrait/icons 192 + 512 + one `purpose: "maskable"`).
- `public/service-worker.js`: versioned cache (`mtaapass-shell-v1`), precache shell + CSS + JS + icons, navigation requests → network-first with `/offline.html` fallback, static assets → stale-while-revalidate, **never cache `/api/*` or `/health`**, `message` handler for `LOGOUT` clearing caches, safe activate cleanup, `skipWaiting` on message.
- `public/offline.html`: standalone offline page (external CSS only), clearly states offline status.
- `public/assets/css/app.css`: complete hand-written design system. Palette from Blueprint §17: forest `#123D35`, emerald `#20A67A`, sunshine `#F4B942`, warm ivory `#F6F5F0`, charcoal `#202B32`. Typography: `Inter, "Segoe UI", system-ui, -apple-system, sans-serif` (no external font fetch). Mobile-first; ≥1024px responsive shell with a left nav. Large touch targets (min 44px). Light + dark themes via `[data-theme="dark"]` on `<html>`, default follows `prefers-color-scheme`, manual override stored in localStorage. Respect `prefers-reduced-motion`. Status chips must pair colour with text/icon. Empty states, skeletons, toasts.
- `public/assets/js/app.js`: vanilla JS only. Provide: theme toggle; `MtaaPass.api(path, options)` fetch wrapper that attaches `X-CSRF-Token` from `<meta name="csrf-token">` and parses the response envelope; toast system; service-worker registration + update prompt; online/offline banner; install prompt (`beforeinstallprompt`); login form enhancement (phone step → OTP step, resend countdown); estate switch form enhancement; sign-out via API then redirect. Must degrade gracefully without JS (plain forms still work).
- Icons (generated by `scripts/generate-icons.php` using GD — PHP ext `gd` is available): `public/assets/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` (180), `favicon.png` (32). Brand: forest rounded-square background, emerald gate/arch mark, sunshine accent. Deterministic, no external assets.
- No inline `<script>`/`<style>`; no `onclick=` attributes; no external CDNs.

## 11. Tests + README (T4/T5)

Harness (plain PHP, no PHPUnit/Composer):

```
tests/run.php        // entry: php tests/run.php [--filter=Name]
tests/bootstrap.php  // env overrides + app boot + fresh DB + seed
tests/TestCase.php   // base assertions + HTTP helpers (Tests\TestCase namespace)
tests/Unit/*Test.php
tests/Integration/*Test.php
tests/Security/*Test.php
```

`tests/bootstrap.php` must, **before** requiring `app/bootstrap.php`:
```php
define('MTAAPASS_TEST_SESSION', true);
putenv('APP_ENV=testing'); putenv('APP_DEBUG=true');
putenv('DB_DATABASE=mtaapass_test');
putenv('SMS_DRIVER=mock'); putenv('SMS_DEV_EXPOSE_OTP=true');
putenv('RATE_LIMIT_ENABLED=true');
putenv('LOG_LEVEL=error');
```
then require bootstrap (returns `$app`), then return `['app' => $app]`.

`tests/run.php` must: boot, run `(new Migrator(Database::instance(), BASE_PATH.'/database/migrations'))->fresh()` **once**, seed once, discover test classes, run every `test*` method, isolate failures, print `PASS/FAIL` lines and a summary, `exit(1)` on any failure. Integration/safety net: `TestCase` exposes `$this->freshDatabase()` (fresh + seed) for tests that need a pristine DB; rate-limit tests clear keys via `RateLimiter::clearPrefix('otp:')` inside `setUp`.

`TestCase` helpers:
`$this->app`, `$this->get($path, $withCsrf=false): Response`, `$this->post($path, array $body, $withCsrf=true, array $headers=[]): Response`, `$this->json(Response): array`, `$this->assertStatus($expected, Response)`, `$this->assertSuccess(Response)`, `$this->assertErrorCode(string, Response)`, `$this->assertTrue/False/Same/Null/NotNull/Contains/Count`, `$this->fail()`, `$this->loginAs(string $phone): void` (full dev-OTP login flow, stores session), `$this->logout(): void`, `$this->resetSession(): void`, `$this->csrfToken(): string`, `$this->freshDatabase(): void`, `$this->seededUser(string $phone): array`, `$this->seededEstate(string $slug): array`.

Required coverage (minimum; expand where useful):

*Unit:* Uuid validity/uniqueness; Phone normalisation (0712…, 254712…, +254712…, separators, invalid); Crypto HMAC determinism/context separation, `numericCode` length/digits, `equals`; Validator required/nullable/max/min/phone/uuid/digits/in/date, whitelisting (unknown fields dropped), 422 payload shape; Router matching/params/405; Migrator statement splitting; Request normalisation; helpers `e()` escaping.

*Integration:* migrations fresh + idempotent second run + checksums; seed idempotency + expected role/permission counts + demo users exist; health 200 with checks; full OTP auth flow (request → dev OTP → verify → `/auth/me` → logout → 401); unauthenticated `/app` 302→`/login` and API 401; estate switch; permission denied (resident → `/api/v1/estate/audit-logs` 403; guard → `/api/v1/estate/settings` 403; admin → 200); audit rows written for login and estate switch and scoped to active estate; CSRF missing token → 403 `CSRF_TOKEN_INVALID` on API + web form; rate limit OTP requests (6th → 429); `/health` bypasses CSRF only for GET.

*Security:* cross-tenant isolation (David Njoroge only Riverside: cannot switch to Greenview → 404; session-forced `estate_id` falls back; `/auth/me` memberships only his; audit logs never contain Greenview rows); OTP storage (no plaintext, 64-char hash, single use, expiry, attempt lockout); IDOR on estate uuid (random uuid → 404); security headers (CSP, nosniff, frame deny, `Cache-Control: no-store`); HTML escaping (render error page with `<script>` debug message → escaped); session id rotation on login (assert `_session_regenerated_at` set in test store) and session cleared by `Auth::logout`.

`README.md`: project overview, architecture map, requirements (PHP 8.3+ production; local XAMPP 8.2 / MariaDB 10.4 port 3307 notes), installation (composer-free), `.env` setup + `php scripts/keygen.php`, migrate/seed/install commands, running the app (`php -S 127.0.0.1:8000 -t public public/index.php`), demo accounts table with the dev OTP flow, routes summary, security controls implemented, testing (`php tests/run.php`), known limitations (modules not yet installed, MariaDB vs MySQL 8, no real SMS provider, MySQL-only, session store files, no queue yet), and next increment (Task 002: estate/household/gate management).

## 12. Definition of done for this increment

- `php scripts/migrate.php --fresh` (dev DB unchanged guard: only `_test` DBs may be dropped; on dev use `--fresh` only if it refuses — actually never drop `mtaapass`; create a separate flow: `php scripts/install.php` runs migrate + seed).
- `php tests/run.php` → all tests pass, exit 0.
- `php -S 127.0.0.1:8000 -t public public/index.php` serves `/`, `/login`, `/app` after login, `/health`; PWA manifest + SW fetch 200.
- No secrets in tracked files; `.env` not created by agents other than Lead.
- Task completion reports list files, commands, results, limitations.

## 13. Coordination

- Claim your shared task (`team_task_update` action `claim`) before writing.
- Work only inside your write scope. If blocked by another agent's file, message the Lead — do not edit it.
- Run `php -l` on each PHP file you add. Do not leave syntax errors.
- Do not run destructive database commands against `mtaapass` (only `mtaapass_test` may be dropped).
- Do not modify `.env`, `config/**`, `app/Core/**`, `app/Middleware/**`.
- When done: mark the shared task complete with a short summary, then report to the Lead.
