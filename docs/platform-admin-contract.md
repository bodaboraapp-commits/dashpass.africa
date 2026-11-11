# MtaaPass/DashPASS — Increment 9: Platform Super-Admin Portal — binding spec

**Status:** binding. Read fully before writing code.
**Base:** foundation contract; expected after Inc-8: **147 routes**, 8 migrations, 37 tables, role links 217.
**Product name:** config-driven. **No schema changes** — platform tables already exist: `saas_accounts`, `plans` (`subscription_plans`), `subscriptions`, `platform_memberships`, `roles` (platform_*), `audit_logs`, `estates`.
**Blueprint:** SaaS super-admin (platform operator) portal — estate provisioning, plans, subscriptions, platform audit. Invoices/payments (billing) need new schema and are a later increment.

## 0. Scope
In scope: protected **platform portal** (separate shell), estate/account listing + detail, estate provisioning (create estate + admin user/membership + default settings under an account), estate status management (active/suspended/archived), plan management (create/retire), subscription management (assign/change plan, status), platform audit viewer, platform dashboard stats. Tests/README.

Out of scope: payments/invoices/PSP integration (later increment needing schema), white-label domains, impersonation ("login as estate admin" — defer), support ticketing, per-estate usage metrics beyond simple counts.

**Rules:** platform permissions only (`platform.audit.view`, `platform.estate.manage`, `platform.plan.manage`, `platform.subscription.manage`, `platform.support.manage`); platform context is **not** estate context — no `estate` middleware; every platform route requires a platform-role membership (platform area gate analogous to admin gate). No hard deletes: estates/plans status only (plans retire; estates archived); subscriptions status transitions. Audit every mutation: `platform.estate_created`, `platform.estate_status_changed`, `platform.plan_created`, `platform.plan_retired`, `platform.subscription_created`, `platform.subscription_changed`. Metadata uuids/codes/status only, no PII. CSRF+validation on all POSTs.

## 1. Routes (147 → **158**; 11 new web, no new APIs)
| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/platform` | PlatformDashboardController::index | platform.audit.view OR platform.estate.manage (area gate) |
| GET | `/platform/estates` | PlatformEstateController::index | `platform.estate.manage` |
| GET | `/platform/estates/new` | PlatformEstateController::create | `platform.estate.manage` |
| POST | `/platform/estates` | PlatformEstateController::store | `platform.estate.manage` |
| GET | `/platform/estates/{uuid}` | PlatformEstateController::show | `platform.estate.manage` |
| POST | `/platform/estates/{uuid}/status` | PlatformEstateController::updateStatus | `platform.estate.manage` |
| GET | `/platform/plans` | PlatformPlanController::index | `platform.plan.manage` |
| POST | `/platform/plans` | PlatformPlanController::store | `platform.plan.manage` |
| POST | `/platform/plans/{uuid}/retire` | PlatformPlanController::retire | `platform.plan.manage` |
| GET | `/platform/subscriptions` | PlatformSubscriptionController::index | `platform.subscription.manage` |
| POST | `/platform/subscriptions/{uuid}` | PlatformSubscriptionController::update | `platform.subscription.manage` |
Platform area gate: caller must hold at least one `platform.*` permission; unauthenticated → 302 `/login`; authenticated non-platform users (Grace/Jane/guards) → **403**; suspended platform membership → 403.
Also: a discreet "Platform console" link in the account screen only for platform-permission users (no link for estate users).

## 2. Business rules
- **Dashboard**: counts (accounts, estates by status, active subscriptions, plans), recent platform audit (permission-filtered), links. Real data only.
- **Estate provisioning**: form fields: account (select existing or create new by name), estate name (2–180 unique slug generated/unique → 422 `ESTATE_EXISTS`), timezone, estate_type; admin full_name + phone (valid, must not already be an active estate admin anywhere? allow; if phone exists globally link user), plan (optional; creates subscription trialing/active via plan), initial settings (visitor_validity_minutes default 240, parking grace 30). Transaction: account (optional create) + estate + default settings + user + estate_admin membership + optional subscription; audit; no partial state on failure. Passwordless (OTP login) — no password fields.
- **Estate status**: active↔suspended↔archived transitions (archived terminal except platform.estate.manage explicit restore? **decision:** archived → active allowed only by platform super admin? Keep simple: allow active/suspended/archived toggles with audit; archived estates disappear from estate switchers but data retained; no delete).
- **Plans**: create code (unique, uppercase letters/numbers/underscore 3–40 → 422 `PLAN_EXISTS`), name, monthly_price_cents ≥0, currency 3 letters, household_limit/gate_device_limit nullable ints ≥0, features key:value map (validated), status active by default; retire sets status retired (existing subscriptions unaffected, not selectable for new); no delete.
- **Subscriptions**: list with estate/plan/status/dates; update allows plan change (active plan only) and status transitions (`trialing|active|past_due|suspended|cancelled|expired`); cancelled/expired terminal (409 `SUBSCRIPTION_CLOSED`); changing plan updates plan_id + audit (price capture deferred to billing increment); one current subscription per estate (latest by starts_at) — creating a second is allowed only by changing the existing one (422 `SUBSCRIPTION_EXISTS` on provisioning if estate already has one).
- **Audit viewer**: list last 100 platform-scope audit rows (platform.audit.view), no PII.
- All PII masked in lists (estate admin phone masked); platform users cannot act on estate data beyond Portal actions (no admin estate routes unless they also hold estate memberships — Grace/demo platform admin separate account).

## 3. Views (pwa-shell)
`app/Views/layouts/platform.php` — platform shell (dark navy, product name + "Platform", operator identity, sign-out, nav Overview/Estate/Plans/Subscriptions/Audit permission-filtered, back-to-app if also estate user).
Screens: `platform/dashboard.php`, `platform/estates.php`, `platform/estate-form.php` (provisioning), `platform/estate.php` (detail + status form + subscription summary), `platform/plans.php` (list + add + retire), `platform/subscriptions.php` (list + change plan/status form), `platform/audit.php` (table).
Patterns: tables/cards, status chips, CSRF/errors/old, empty states, single h1, CSP-safe, root-relative, 44px, reduced-motion, config product name; no estate-tenant data leakage.

## 4. Tests (pre-written by qa-mail, folded by tests)
Platform area gate (platform roles 200; estate-only users 403; unauth 302; suspended platform 403); estate provisioning transaction (account optional/new, estate+settings+admin user+membership+subscription, duplicate slug 422, invalid phone 422, rollback leaves no partial rows); status transitions + audit; plans create/retire + duplicate code 422 + validation; subscriptions list/update transitions + terminal 409 + one-per-estate; audit view permission + PII-free; CSRF/XSS; no hard deletes (row counts stable, status only); routes exact **158**; README Inc-9.

## 5. Tasks
| Task | Agent | Scope |
|---|---|---|
| T64 backend phase 1 | modules | `app/Modules/Platform/**` (platform shell base, services/controllers, provisioning transaction) — no routes until gate |
| T65 views phase 1 | pwa-shell | `app/Views/platform/**`, `layouts/platform.php`, `platform.css`, account link markup — no routes/nav until gate |
| T66 tests pre-written | qa-mail | tests under `storage/temporary/pending-tests/`; route list 158 |
| T67 Lead gate | Lead | full suite + live acceptance + `docs/increment-9-acceptance.md`; recommend billing/invoices (Inc-10) next |

## 6. Acceptance (T67)
1. No migration added (8/37/217). Full suite green; routes exactly 158.
2. Live: platform super admin (+254700000020) logs in → `/platform` dashboard; provisions a test estate with admin user + settings + plan subscription (transaction proven), new admin can log in via OTP and sees their empty estate admin portal; suspend/archive toggles; plan create/retire; subscription plan change/status; audit rows; Grace/Jane/guard get 403 on every platform route; CSRF/XSS/no-hard-delete checks.
3. README/docs updated; next increment = billing/invoices (schema 10) + reports/exports.
