# MtaaPass/DashPASS — Database schema reference

**Generated:** 2026-11-11 18:55:01 EAT from the live **dev** database `mtaapass` (read-only).
**State:** **37 tables**, **8 applied migrations**, **52 permissions / 13 roles / 217 role-permission links**, **86 foreign keys**.

> Source of truth for column types/indexes/FKs is this document + the migrations under
> `database/migrations/`. Regenerate by querying `information_schema` (read-only) — do not hand-edit
> generated sections. Row counts/seed rows reflect the live dev DB and may include QA/acceptance smoke
> rows beyond the canonical demos listed below.

## 1. Conventions

| Convention | Rule |
|---|---|
| Engine / charset | Every table is `InnoDB` with `utf8mb4` / `utf8mb4_unicode_ci`. |
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT` (surrogate; never exposed publicly). |
| Public ids | `uuid CHAR(36)` with a `UNIQUE(uuid)` index; all routes/API take UUIDs, not numeric ids. |
| Tenant isolation | Tenant rows carry `estate_id BIGINT UNSIGNED NOT NULL` → `estates(id)`; cross-tenant links use **composite FKs** `(child_id, estate_id) → parent(id, estate_id)` requiring a parent `UNIQUE(id, estate_id)`. |
| Timestamps | `created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)` (+ `updated_at … ON UPDATE` on mutable tables); stored UTC, rendered in the estate timezone. |
| Enums | Finite MySQL `ENUM`s for state machines (no lookup tables); see §4. No hard deletes: state columns (`status`) express archive/void. |
| Money | Integer minor units (`*_cents INT UNSIGNED`) + `currency CHAR(3)`; never floats. |
| JSON-ish data | `audit_logs.metadata` / `subscription_plans.features` use JSON-in-LONGTEXT (existing convention); Inc-10 `billing_payments.metadata` is native `JSON`. |
| Settings | `estate_settings(estate_id, setting_key)` unique, `setting_value` JSON scalar (int/bool/string). |
| Replaceable links | `guard_gate_assignments` is deleted+reinserted by design (the only contract-allowed hard delete); every other table is status/append-only. |
| Scope | `roles.scope` = `platform` (platform portal) or `estate` (tenant roles); platform users are not estate users. |

## 2. Migration ledger

| # | Migration file | Batch | Applied at (UTC) | Creates |
|---|---|---|---|---|
| 1 | `20261111_000001_create_saas_and_estate_tables.sql` | 1 | 2026-11-11 13:42:33.446 | saas_accounts, estates, estate_settings, subscription_plans, subscriptions |
| 2 | `20261111_000002_create_identity_and_rbac_tables.sql` | 1 | 2026-11-11 13:42:33.639 | users, roles, permissions, role_permissions, platform_memberships, estate_memberships |
| 3 | `20261111_000003_create_estate_structure_tables.sql` | 1 | 2026-11-11 13:42:33.785 | buildings, households, household_members, gates, guard_gate_assignments |
| 4 | `20261111_000004_create_security_and_audit_tables.sql` | 1 | 2026-11-11 13:42:33.904 | audit_logs, otp_challenges, access_credentials, access_verification_attempts, rate_limits, notification_logs |
| 5 | `20261111_000006_create_visitor_access_tables.sql` | 1 | 2026-11-11 13:42:34.091 | visitors, visitor_invitations, visitor_access_events, walk_in_requests |
| 6 | `20261111_000007_create_vehicles_parking_tables.sql` | 1 | 2026-11-11 13:42:34.424 | vehicles, parking_zones, parking_bays, parking_allocations, parking_reservations, parking_sessions, parking_violations |
| 7 | `20261111_000008_create_staff_and_incident_tables.sql` | 2 | 2026-11-11 14:28:40.610 | household_staff, access_incidents (Inc-6) |
| 8 | `20261111_000009_create_estate_notices_table.sql` | 3 | 2026-11-11 15:02:38.216 | estate_notices (Inc-7) |

> Gap note: there is intentionally no `000005` file; migration numbering has a documented gap. Applied checksums are verified by `App\Core\Migrator` on every run.

**Inc-9 (platform portal) adds no tables/permissions** (verified: platform tables/plans/subscriptions/memberships/roles/audit all already exist).
**Inc-8 (parking administration) adds no tables** (existing `parking_*` + `estate_settings` only; adds Greenview `parking_overstay_grace_minutes=30`).

## 3. Tables by domain

### SaaS accounts & billing (billing tables staged in Inc-10)

#### `platform_memberships` — Platform-operator role membership (platform portal area gate).

Rows (live dev): **1**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_platform_memberships_uuid (U)` |
| `user_id` | `bigint(20) unsigned` | no | — | `uq_platform_memberships_user_role (U)` |
| `role_id` | `bigint(20) unsigned` | no | — | `idx_platform_memberships_role`<br>`uq_platform_memberships_user_role[2]` |
| `status` | `enum('active','suspended')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_platform_memberships_role`(role_id), `PRIMARY`(id) UNIQUE, `uq_platform_memberships_user_role`(user_id,role_id) UNIQUE, `uq_platform_memberships_uuid`(uuid) UNIQUE

- FK `fk_platform_memberships_role`: (role_id) → `roles`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_platform_memberships_user`: (user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `saas_accounts` — SaaS tenant account that owns estates (multi-tenant root).

Rows (live dev): **1**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_saas_accounts_uuid (U)` |
| `name` | `varchar(180)` | no | — |  |
| `status` | `enum('active','suspended','closed')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_saas_accounts_uuid`(uuid) UNIQUE

#### `subscriptions` — Per-estate plan subscription history (one current per estate, enforced by services).

Rows (live dev): **2**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_subscriptions_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_subscriptions_estate_status` |
| `plan_id` | `bigint(20) unsigned` | no | — | `idx_subscriptions_plan` |
| `status` | `enum('trialing','active','past_due','suspended','cancelled','expired')` | no | `'trialing'` | `idx_subscriptions_estate_status[2]` |
| `billing_cycle` | `enum('monthly','annual')` | no | `'monthly'` |  |
| `starts_at` | `datetime(3)` | no | — |  |
| `ends_at` | `datetime(3)` | YES | `NULL` |  |
| `trial_ends_at` | `datetime(3)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_subscriptions_estate_status`(estate_id,status), `idx_subscriptions_plan`(plan_id), `PRIMARY`(id) UNIQUE, `uq_subscriptions_uuid`(uuid) UNIQUE

- FK `fk_subscriptions_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_subscriptions_plan`: (plan_id) → `subscription_plans`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `subscription_plans` — Subscription plan catalogue (code, price in cents, limits, features).

Rows (live dev): **3**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_subscription_plans_uuid (U)` |
| `code` | `varchar(60)` | no | — | `uq_subscription_plans_code (U)` |
| `name` | `varchar(120)` | no | — |  |
| `monthly_price_cents` | `int(10) unsigned` | no | — |  |
| `currency` | `char(3)` | no | `'KES'` |  |
| `household_limit` | `int(10) unsigned` | YES | `NULL` |  |
| `gate_device_limit` | `smallint(5) unsigned` | YES | `NULL` |  |
| `features` | `longtext` | YES | `NULL` |  |
| `status` | `enum('active','retired')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_subscription_plans_code`(code) UNIQUE, `uq_subscription_plans_uuid`(uuid) UNIQUE

### Identity & authentication

#### `access_credentials` — Hashed visitor access PIN/QR credentials per invitation.

Rows (live dev): **18**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_access_credentials_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_access_credentials_invitation_estate[2]` |
| `invitation_id` | `bigint(20) unsigned` | no | — | `idx_access_credentials_invitation`<br>`idx_access_credentials_invitation_estate` |
| `credential_hash` | `char(64)` | no | — | `uq_access_credentials_hash (U)` |
| `credential_type` | `enum('pin','qr')` | no | — | `idx_access_credentials_invitation[3]` |
| `encrypted_secret` | `text` | YES | `NULL` |  |
| `expires_at` | `datetime(3)` | no | — |  |
| `max_uses` | `smallint(5) unsigned` | no | `1` |  |
| `used_count` | `smallint(5) unsigned` | no | `0` |  |
| `status` | `enum('active','used','revoked','expired')` | no | `'active'` | `idx_access_credentials_invitation[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `revoked_at` | `datetime(3)` | YES | `NULL` |  |

Indexes: `idx_access_credentials_invitation`(invitation_id,status,credential_type), `idx_access_credentials_invitation_estate`(invitation_id,estate_id), `PRIMARY`(id) UNIQUE, `uq_access_credentials_hash`(credential_hash) UNIQUE, `uq_access_credentials_uuid`(uuid) UNIQUE

- FK `fk_access_credentials_invitation`: (invitation_id, estate_id) → `visitor_invitations`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `access_verification_attempts` — Rate-limit/anti-bruteforce audit of visitor access verification.

Rows (live dev): **5**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_access_verification_attempts_estate` |
| `gate_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_access_verification_attempts_gate` |
| `credential_type` | `enum('pin','qr')` | no | — |  |
| `credential_hash` | `char(64)` | YES | `NULL` |  |
| `success` | `tinyint(1)` | no | — |  |
| `ip_address` | `varchar(45)` | YES | `NULL` |  |
| `user_agent` | `varchar(255)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_access_verification_attempts_estate[2]`<br>`idx_access_verification_attempts_gate[2]` |

Indexes: `idx_access_verification_attempts_estate`(estate_id,created_at), `idx_access_verification_attempts_gate`(gate_id,created_at), `PRIMARY`(id) UNIQUE

#### `otp_challenges` — OTP challenges for login/verification (hashed, TTL, attempts).

Rows (live dev): **279**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_otp_challenges_uuid (U)` |
| `phone` | `varchar(20)` | no | — | `idx_otp_challenges_phone_purpose` |
| `purpose` | `enum('login','staff_login')` | no | `'login'` | `idx_otp_challenges_phone_purpose[2]` |
| `user_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_otp_challenges_user` |
| `otp_hash` | `char(64)` | no | — |  |
| `expires_at` | `datetime(3)` | no | — | `idx_otp_challenges_expires` |
| `attempts` | `smallint(5) unsigned` | no | `0` |  |
| `max_attempts` | `smallint(5) unsigned` | no | `5` |  |
| `consumed_at` | `datetime(3)` | YES | `NULL` |  |
| `ip_address` | `varchar(45)` | YES | `NULL` |  |
| `user_agent` | `varchar(255)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_otp_challenges_phone_purpose[3]` |

Indexes: `idx_otp_challenges_expires`(expires_at), `idx_otp_challenges_phone_purpose`(phone,purpose,created_at), `idx_otp_challenges_user`(user_id), `PRIMARY`(id) UNIQUE, `uq_otp_challenges_uuid`(uuid) UNIQUE

- FK `fk_otp_challenges_user`: (user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `rate_limits` — Keyed rate-limit buckets (login/OTP/resend etc.).

Rows (live dev): **21**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `rl_key` | `varchar(160)` | no | — | `PRIMARY (U)` |
| `window_started_at` | `datetime(3)` | no | — |  |
| `hits` | `int(10) unsigned` | no | — |  |
| `expires_at` | `datetime(3)` | no | — | `idx_rate_limits_expires` |

Indexes: `idx_rate_limits_expires`(expires_at), `PRIMARY`(rl_key) UNIQUE

#### `users` — Global passwordless user identity; unique phone; shared across estates/platform.

Rows (live dev): **17**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_users_uuid (U)` |
| `full_name` | `varchar(180)` | no | — |  |
| `phone` | `varchar(20)` | no | — | `uq_users_phone (U)` |
| `email` | `varchar(190)` | YES | `NULL` |  |
| `password_hash` | `varchar(255)` | YES | `NULL` |  |
| `status` | `enum('active','pending','suspended')` | no | `'pending'` |  |
| `last_login_at` | `datetime(3)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_users_phone`(phone) UNIQUE, `uq_users_uuid`(uuid) UNIQUE

### RBAC, audit & operations

#### `audit_logs` — Append-only audit trail; estate_id nullable for platform actions; JSON-ish metadata.

Rows (live dev): **934**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_audit_logs_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_audit_logs_estate` |
| `actor_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_audit_logs_actor` |
| `action` | `varchar(100)` | no | — | `idx_audit_logs_action` |
| `entity_type` | `varchar(80)` | no | — |  |
| `entity_id` | `bigint(20) unsigned` | YES | `NULL` |  |
| `metadata` | `longtext` | YES | `NULL` |  |
| `correlation_id` | `char(36)` | YES | `NULL` |  |
| `ip_address` | `varchar(45)` | YES | `NULL` |  |
| `user_agent` | `varchar(255)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_audit_logs_action[2]`<br>`idx_audit_logs_actor[2]`<br>`idx_audit_logs_estate[2]` |

Indexes: `idx_audit_logs_action`(action,created_at), `idx_audit_logs_actor`(actor_id,created_at), `idx_audit_logs_estate`(estate_id,created_at), `PRIMARY`(id) UNIQUE, `uq_audit_logs_uuid`(uuid) UNIQUE

#### `notification_logs` — SMS/email provider delivery log.

Rows (live dev): **295**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_notification_logs_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_notification_logs_estate` |
| `channel` | `enum('sms','push','in_app')` | no | `'sms'` |  |
| `recipient` | `varchar(190)` | no | — |  |
| `template` | `varchar(80)` | YES | `NULL` |  |
| `provider` | `varchar(40)` | YES | `NULL` |  |
| `provider_message_id` | `varchar(120)` | YES | `NULL` |  |
| `status` | `enum('queued','sent','failed','delivered')` | no | `'queued'` | `idx_notification_logs_status` |
| `error` | `varchar(500)` | YES | `NULL` |  |
| `sent_at` | `datetime(3)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_notification_logs_estate[2]` |

Indexes: `idx_notification_logs_estate`(estate_id,created_at), `idx_notification_logs_status`(status), `PRIMARY`(id) UNIQUE, `uq_notification_logs_uuid`(uuid) UNIQUE

- FK `fk_notification_logs_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `permissions` — Permission catalogue (platform.* and estate-domain slugs).

Rows (live dev): **52**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `slug` | `varchar(100)` | no | — | `uq_permissions_slug (U)` |
| `name` | `varchar(150)` | no | — |  |
| `scope` | `enum('platform','estate')` | no | — |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_permissions_slug`(slug) UNIQUE

#### `roles` — Role catalogue: platform-scoped and estate-scoped roles.

Rows (live dev): **13**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `scope` | `enum('platform','estate')` | no | — | `uq_roles_scope_name (U)` |
| `name` | `varchar(80)` | no | — | `uq_roles_scope_name[2]` |
| `description` | `varchar(180)` | YES | `NULL` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_roles_scope_name`(scope,name) UNIQUE

#### `role_permissions` — Role → permission links (seeded; see RBAC summary).

Rows (live dev): **217**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `role_id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `permission_id` | `bigint(20) unsigned` | no | — | `idx_role_permissions_permission`<br>`PRIMARY[2]` |

Indexes: `idx_role_permissions_permission`(permission_id), `PRIMARY`(role_id,permission_id) UNIQUE

- FK `fk_role_permissions_permission`: (permission_id) → `permissions`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_role_permissions_role`: (role_id) → `roles`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `schema_migrations` — Applied migration ledger with checksums and batch numbers.

Rows (live dev): **8**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `migration` | `varchar(190)` | no | — | `uq_migration_name (U)` |
| `batch` | `int(10) unsigned` | no | — |  |
| `checksum` | `char(64)` | no | — |  |
| `applied_at` | `datetime(3)` | no | — |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_migration_name`(migration) UNIQUE

### Estate structure

#### `buildings` — Estate buildings (no updated_at; immutable in Inc-4).

Rows (live dev): **6**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_buildings_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_buildings_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `uq_buildings_estate_name (U)`<br>`uq_buildings_id_estate[2]` |
| `name` | `varchar(120)` | no | — | `uq_buildings_estate_name[2]` |
| `building_type` | `enum('apartment_block','street','cluster','zone')` | no | `'apartment_block'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_buildings_estate_name`(estate_id,name) UNIQUE, `uq_buildings_id_estate`(id,estate_id) UNIQUE, `uq_buildings_uuid`(uuid) UNIQUE

- FK `fk_buildings_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `estates` — Estate tenant record (slug unique, status active/suspended/archived).

Rows (live dev): **2**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_estates_id_account (U)` |
| `uuid` | `char(36)` | no | — | `uq_estates_uuid (U)` |
| `account_id` | `bigint(20) unsigned` | no | — | `idx_estates_account_status`<br>`uq_estates_id_account[2]` |
| `name` | `varchar(180)` | no | — |  |
| `slug` | `varchar(180)` | no | — | `uq_estates_slug (U)` |
| `timezone` | `varchar(80)` | no | `'Africa/Nairobi'` |  |
| `estate_type` | `enum('apartment','maisonette','mixed')` | no | `'mixed'` |  |
| `status` | `enum('active','suspended','archived')` | no | `'active'` | `idx_estates_account_status[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_estates_account_status`(account_id,status), `PRIMARY`(id) UNIQUE, `uq_estates_id_account`(id,account_id) UNIQUE, `uq_estates_slug`(slug) UNIQUE, `uq_estates_uuid`(uuid) UNIQUE

- FK `fk_estates_account`: (account_id) → `saas_accounts`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `estate_memberships` — User ↔ estate role links (active/pending/suspended; no inactive enum).

Rows (live dev): **17**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_estate_memberships_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `uq_estate_memberships_estate_user (U)` |
| `user_id` | `bigint(20) unsigned` | no | — | `idx_estate_memberships_user`<br>`uq_estate_memberships_estate_user[2]` |
| `role_id` | `bigint(20) unsigned` | no | — | `idx_estate_memberships_role` |
| `status` | `enum('active','pending','suspended')` | no | `'active'` | `idx_estate_memberships_user[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_estate_memberships_role`(role_id), `idx_estate_memberships_user`(user_id,status), `PRIMARY`(id) UNIQUE, `uq_estate_memberships_estate_user`(estate_id,user_id) UNIQUE, `uq_estate_memberships_uuid`(uuid) UNIQUE

- FK `fk_estate_memberships_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_estate_memberships_role`: (role_id) → `roles`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_estate_memberships_user`: (user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `estate_settings` — Per-estate key/value settings (JSON scalar values).

Rows (live dev): **5**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `uq_estate_settings_key (U)` |
| `setting_key` | `varchar(120)` | no | — | `uq_estate_settings_key[2]` |
| `setting_value` | `longtext` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_estate_settings_key`(estate_id,setting_key) UNIQUE

- FK `fk_estate_settings_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `households` — Estate households/units (soft archive via status).

Rows (live dev): **33**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_households_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_households_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_households_building_estate[2]`<br>`uq_households_estate_unit (U)`<br>`uq_households_id_estate[2]` |
| `building_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_households_building_estate` |
| `unit_number` | `varchar(60)` | no | — | `uq_households_estate_unit[2]` |
| `household_type` | `enum('apartment','maisonette','other')` | no | `'apartment'` |  |
| `status` | `enum('active','inactive')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_households_building_estate`(building_id,estate_id), `PRIMARY`(id) UNIQUE, `uq_households_estate_unit`(estate_id,unit_number) UNIQUE, `uq_households_id_estate`(id,estate_id) UNIQUE, `uq_households_uuid`(uuid) UNIQUE

- FK `fk_households_building`: (building_id, estate_id) → `buildings`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_households_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `household_members` — Residents linked to households; single-primary rule in services.

Rows (live dev): **18**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_household_members_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_household_members_household_estate[2]` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_household_members_household_estate`<br>`uq_household_members_household_user (U)` |
| `user_id` | `bigint(20) unsigned` | no | — | `idx_household_members_user`<br>`uq_household_members_household_user[2]` |
| `relationship` | `varchar(60)` | YES | `NULL` |  |
| `is_primary` | `tinyint(1)` | no | `0` |  |
| `status` | `enum('active','inactive')` | no | `'active'` | `idx_household_members_user[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_household_members_household_estate`(household_id,estate_id), `idx_household_members_user`(user_id,status), `PRIMARY`(id) UNIQUE, `uq_household_members_household_user`(household_id,user_id) UNIQUE, `uq_household_members_uuid`(uuid) UNIQUE

- FK `fk_household_members_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_household_members_user`: (user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `household_staff` — Domestic staff directory (Inc-6); soft archive via status.

Rows (live dev): **18**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_household_staff_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_household_staff_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_household_staff_estate_status`<br>`idx_household_staff_household_estate[2]`<br>`uq_household_staff_id_estate[2]` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_household_staff_household_estate`<br>`idx_household_staff_household_status` |
| `user_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_household_staff_user` |
| `full_name` | `varchar(180)` | no | — |  |
| `phone` | `varchar(20)` | no | — |  |
| `staff_type` | `enum('domestic','driver','gardener','nanny','cook','security','other')` | no | `'domestic'` |  |
| `notes` | `varchar(500)` | YES | `NULL` |  |
| `status` | `enum('active','inactive')` | no | `'active'` | `idx_household_staff_estate_status[2]`<br>`idx_household_staff_household_status[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_household_staff_estate_status`(estate_id,status), `idx_household_staff_household_estate`(household_id,estate_id), `idx_household_staff_household_status`(household_id,status), `idx_household_staff_user`(user_id), `PRIMARY`(id) UNIQUE, `uq_household_staff_id_estate`(id,estate_id) UNIQUE, `uq_household_staff_uuid`(uuid) UNIQUE

- FK `fk_household_staff_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_household_staff_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_household_staff_user`: (user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

### Gates & guards

#### `gates` — Estate gates (status active/inactive for archive).

Rows (live dev): **4**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_gates_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_gates_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `uq_gates_estate_name (U)`<br>`uq_gates_id_estate[2]` |
| `name` | `varchar(100)` | no | — | `uq_gates_estate_name[2]` |
| `location` | `varchar(180)` | YES | `NULL` |  |
| `status` | `enum('active','inactive')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_gates_estate_name`(estate_id,name) UNIQUE, `uq_gates_id_estate`(id,estate_id) UNIQUE, `uq_gates_uuid`(uuid) UNIQUE

- FK `fk_gates_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `guard_gate_assignments` — Replaceable link of guards to gates (hard-deleted by design).

Rows (live dev): **4**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_guard_gate_assignments_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_guard_gate_assignments_gate_estate[2]` |
| `gate_id` | `bigint(20) unsigned` | no | — | `idx_guard_gate_assignments_gate_estate`<br>`uq_guard_gate_assignments_gate_guard (U)` |
| `guard_user_id` | `bigint(20) unsigned` | no | — | `idx_guard_gate_assignments_guard`<br>`uq_guard_gate_assignments_gate_guard[2]` |
| `shift` | `varchar(40)` | YES | `NULL` |  |
| `status` | `enum('active','inactive')` | no | `'active'` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_guard_gate_assignments_gate_estate`(gate_id,estate_id), `idx_guard_gate_assignments_guard`(guard_user_id), `PRIMARY`(id) UNIQUE, `uq_guard_gate_assignments_gate_guard`(gate_id,guard_user_id) UNIQUE, `uq_guard_gate_assignments_uuid`(uuid) UNIQUE

- FK `fk_guard_gate_assignments_gate`: (gate_id, estate_id) → `gates`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_guard_gate_assignments_guard`: (guard_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

### Visitors & access

#### `visitors` — Visitor identity for invitations/walk-ins.

Rows (live dev): **15**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_visitors_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_visitors_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_visitors_estate_phone`<br>`uq_visitors_id_estate[2]` |
| `name` | `varchar(180)` | no | — |  |
| `phone` | `varchar(20)` | no | — | `idx_visitors_estate_phone[2]` |
| `photo_path` | `varchar(500)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_visitors_estate_phone`(estate_id,phone), `PRIMARY`(id) UNIQUE, `uq_visitors_id_estate`(id,estate_id) UNIQUE, `uq_visitors_uuid`(uuid) UNIQUE

- FK `fk_visitors_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `visitor_access_events` — Check-in/out and access event audit for visitor sessions.

Rows (live dev): **10**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_visitor_access_events_uuid (U)` |
| `event_uuid` | `char(36)` | no | — | `uq_visitor_access_events_event_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_visitor_access_events_estate`<br>`idx_visitor_access_events_gate_estate[2]`<br>`idx_visitor_access_events_invitation_estate[2]` |
| `invitation_id` | `bigint(20) unsigned` | no | — | `idx_visitor_access_events_invitation`<br>`idx_visitor_access_events_invitation_estate` |
| `gate_id` | `bigint(20) unsigned` | no | — | `idx_visitor_access_events_gate_estate` |
| `event_type` | `enum('check_in','check_out','denied','manual_override')` | no | — |  |
| `actor_id` | `bigint(20) unsigned` | no | — | `idx_visitor_access_events_actor` |
| `occurred_at` | `datetime(3)` | no | — | `idx_visitor_access_events_estate[2]`<br>`idx_visitor_access_events_invitation[2]` |
| `source` | `enum('online','offline_sync')` | no | `'online'` |  |
| `metadata` | `longtext` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_visitor_access_events_actor`(actor_id), `idx_visitor_access_events_estate`(estate_id,occurred_at), `idx_visitor_access_events_gate_estate`(gate_id,estate_id), `idx_visitor_access_events_invitation`(invitation_id,occurred_at), `idx_visitor_access_events_invitation_estate`(invitation_id,estate_id), `PRIMARY`(id) UNIQUE, `uq_visitor_access_events_event_uuid`(event_uuid) UNIQUE, `uq_visitor_access_events_uuid`(uuid) UNIQUE

- FK `fk_visitor_access_events_actor`: (actor_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_access_events_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_access_events_gate`: (gate_id, estate_id) → `gates`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_access_events_invitation`: (invitation_id, estate_id) → `visitor_invitations`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `visitor_invitations` — Visitor invitations/passes with validity windows and status workflow.

Rows (live dev): **15**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_visitor_invitations_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_visitor_invitations_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_visitor_invitations_estate_window`<br>`idx_visitor_invitations_household_estate[2]`<br>`uq_visitor_invitations_id_estate[2]` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_visitor_invitations_household`<br>`idx_visitor_invitations_household_estate` |
| `host_user_id` | `bigint(20) unsigned` | no | — | `idx_visitor_invitations_host` |
| `visitor_id` | `bigint(20) unsigned` | no | — | `idx_visitor_invitations_visitor` |
| `purpose` | `varchar(120)` | YES | `NULL` |  |
| `visitor_count` | `smallint(5) unsigned` | no | `1` |  |
| `valid_from` | `datetime(3)` | no | — | `idx_visitor_invitations_estate_window[3]` |
| `valid_until` | `datetime(3)` | no | — | `idx_visitor_invitations_estate_window[4]` |
| `status` | `enum('pending','approved','checked_in','checked_out','revoked','expired','denied')` | no | `'approved'` | `idx_visitor_invitations_estate_window[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_visitor_invitations_host[2]`<br>`idx_visitor_invitations_household[2]` |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_visitor_invitations_estate_window`(estate_id,status,valid_from,valid_until), `idx_visitor_invitations_host`(host_user_id,created_at), `idx_visitor_invitations_household`(household_id,created_at), `idx_visitor_invitations_household_estate`(household_id,estate_id), `idx_visitor_invitations_visitor`(visitor_id), `PRIMARY`(id) UNIQUE, `uq_visitor_invitations_id_estate`(id,estate_id) UNIQUE, `uq_visitor_invitations_uuid`(uuid) UNIQUE

- FK `fk_visitor_invitations_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_invitations_host`: (host_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_invitations_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_visitor_invitations_visitor`: (visitor_id) → `visitors`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `walk_in_requests` — Walk-in visitor approval requests (resident approve/reject).

Rows (live dev): **1**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_walk_in_requests_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_walk_in_requests_estate`<br>`idx_walk_in_requests_gate_estate[2]`<br>`idx_walk_in_requests_household_estate[2]` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_walk_in_requests_household`<br>`idx_walk_in_requests_household_estate` |
| `gate_id` | `bigint(20) unsigned` | no | — | `idx_walk_in_requests_gate_estate` |
| `requested_by_user_id` | `bigint(20) unsigned` | no | — |  |
| `visitor_name` | `varchar(180)` | no | — |  |
| `visitor_phone` | `varchar(20)` | no | — |  |
| `purpose` | `varchar(120)` | YES | `NULL` |  |
| `status` | `enum('pending','approved','rejected','expired')` | no | `'pending'` | `idx_walk_in_requests_estate[2]`<br>`idx_walk_in_requests_household[2]` |
| `invitation_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_walk_in_requests_invitation` |
| `responded_by_user_id` | `bigint(20) unsigned` | YES | `NULL` |  |
| `responded_at` | `datetime(3)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_walk_in_requests_estate[3]` |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_walk_in_requests_estate`(estate_id,status,created_at), `idx_walk_in_requests_gate_estate`(gate_id,estate_id), `idx_walk_in_requests_household`(household_id,status), `idx_walk_in_requests_household_estate`(household_id,estate_id), `idx_walk_in_requests_invitation`(invitation_id), `PRIMARY`(id) UNIQUE, `uq_walk_in_requests_uuid`(uuid) UNIQUE

- FK `fk_walk_in_requests_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_walk_in_requests_gate`: (gate_id, estate_id) → `gates`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_walk_in_requests_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_walk_in_requests_invitation`: (invitation_id) → `visitor_invitations`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

### Vehicles & parking

#### `parking_allocations` — Bay allocations to households/vehicles (active/ended window).

Rows (live dev): **10**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_allocations_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `fk_parking_allocations_estate`<br>`idx_parking_allocations_bay_estate[2]`<br>`idx_parking_allocations_household_estate[2]` |
| `bay_id` | `bigint(20) unsigned` | no | — | `idx_parking_allocations_bay`<br>`idx_parking_allocations_bay_estate` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_parking_allocations_household`<br>`idx_parking_allocations_household_estate` |
| `vehicle_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_parking_allocations_vehicle` |
| `starts_at` | `datetime(3)` | no | — |  |
| `ends_at` | `datetime(3)` | YES | `NULL` |  |
| `status` | `enum('active','suspended','ended')` | no | `'active'` | `idx_parking_allocations_bay[2]`<br>`idx_parking_allocations_household[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `fk_parking_allocations_estate`(estate_id), `idx_parking_allocations_bay`(bay_id,status), `idx_parking_allocations_bay_estate`(bay_id,estate_id), `idx_parking_allocations_household`(household_id,status), `idx_parking_allocations_household_estate`(household_id,estate_id), `idx_parking_allocations_vehicle`(vehicle_id), `PRIMARY`(id) UNIQUE, `uq_parking_allocations_uuid`(uuid) UNIQUE

- FK `fk_parking_allocations_bay`: (bay_id, estate_id) → `parking_bays`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_allocations_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_allocations_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_allocations_vehicle`: (vehicle_id) → `vehicles`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `parking_bays` — Parking bays (status available/reserved/occupied/maintenance).

Rows (live dev): **40**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_parking_bays_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_bays_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_parking_bays_estate_status_type`<br>`idx_parking_bays_zone_estate[2]`<br>`uq_parking_bays_estate_code (U)`<br>`uq_parking_bays_id_estate[2]` |
| `zone_id` | `bigint(20) unsigned` | no | — | `idx_parking_bays_zone_estate` |
| `bay_code` | `varchar(30)` | no | — | `uq_parking_bays_estate_code[2]` |
| `bay_type` | `enum('resident','visitor','accessible','service')` | no | — | `idx_parking_bays_estate_status_type[3]` |
| `status` | `enum('available','reserved','occupied','maintenance')` | no | `'available'` | `idx_parking_bays_estate_status_type[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_parking_bays_estate_status_type`(estate_id,status,bay_type), `idx_parking_bays_zone_estate`(zone_id,estate_id), `PRIMARY`(id) UNIQUE, `uq_parking_bays_estate_code`(estate_id,bay_code) UNIQUE, `uq_parking_bays_id_estate`(id,estate_id) UNIQUE, `uq_parking_bays_uuid`(uuid) UNIQUE

- FK `fk_parking_bays_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_bays_zone`: (zone_id, estate_id) → `parking_zones`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `parking_reservations` — Visitor parking reservations tied to invitations.

Rows (live dev): **6**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_reservations_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `fk_parking_reservations_estate`<br>`idx_parking_reservations_bay_estate[2]`<br>`idx_parking_reservations_invitation_estate[2]` |
| `invitation_id` | `bigint(20) unsigned` | no | — | `idx_parking_reservations_invitation`<br>`idx_parking_reservations_invitation_estate` |
| `bay_id` | `bigint(20) unsigned` | no | — | `idx_parking_reservations_bay_estate`<br>`idx_parking_reservations_bay_window` |
| `vehicle_id` | `bigint(20) unsigned` | YES | `NULL` |  |
| `plate_number` | `varchar(20)` | YES | `NULL` |  |
| `starts_at` | `datetime(3)` | no | — | `idx_parking_reservations_bay_window[2]` |
| `ends_at` | `datetime(3)` | no | — | `idx_parking_reservations_bay_window[3]` |
| `status` | `enum('reserved','checked_in','completed','cancelled','expired')` | no | `'reserved'` | `idx_parking_reservations_bay_window[4]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `fk_parking_reservations_estate`(estate_id), `idx_parking_reservations_bay_estate`(bay_id,estate_id), `idx_parking_reservations_bay_window`(bay_id,starts_at,ends_at,status), `idx_parking_reservations_invitation`(invitation_id), `idx_parking_reservations_invitation_estate`(invitation_id,estate_id), `PRIMARY`(id) UNIQUE, `uq_parking_reservations_uuid`(uuid) UNIQUE

- FK `fk_parking_reservations_bay`: (bay_id, estate_id) → `parking_bays`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_reservations_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_reservations_invitation`: (invitation_id, estate_id) → `visitor_invitations`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `parking_sessions` — Parking sessions (check-in/out, overstay status).

Rows (live dev): **12**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_sessions_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_parking_sessions_bay_estate[2]`<br>`idx_parking_sessions_estate_status_bay`<br>`idx_parking_sessions_invitation_estate[2]` |
| `bay_id` | `bigint(20) unsigned` | no | — | `idx_parking_sessions_bay_estate`<br>`idx_parking_sessions_estate_status_bay[3]` |
| `vehicle_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_parking_sessions_vehicle` |
| `invitation_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_parking_sessions_invitation`<br>`idx_parking_sessions_invitation_estate` |
| `checkin_at` | `datetime(3)` | no | — |  |
| `checkout_at` | `datetime(3)` | YES | `NULL` |  |
| `status` | `enum('active','completed','overstay','void')` | no | `'active'` | `idx_parking_sessions_estate_status_bay[2]`<br>`idx_parking_sessions_invitation[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_parking_sessions_bay_estate`(bay_id,estate_id), `idx_parking_sessions_estate_status_bay`(estate_id,status,bay_id), `idx_parking_sessions_invitation`(invitation_id,status), `idx_parking_sessions_invitation_estate`(invitation_id,estate_id), `idx_parking_sessions_vehicle`(vehicle_id), `PRIMARY`(id) UNIQUE, `uq_parking_sessions_uuid`(uuid) UNIQUE

- FK `fk_parking_sessions_bay`: (bay_id, estate_id) → `parking_bays`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_sessions_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_sessions_invitation`: (invitation_id, estate_id) → `visitor_invitations`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `parking_violations` — Parking violations queue (status open/resolved/dismissed).

Rows (live dev): **40**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_violations_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_parking_violations_bay_estate[2]`<br>`idx_parking_violations_estate` |
| `bay_id` | `bigint(20) unsigned` | no | — | `idx_parking_violations_bay_estate` |
| `vehicle_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_parking_violations_vehicle` |
| `plate_number` | `varchar(20)` | YES | `NULL` |  |
| `violation_type` | `enum('overstay','unauthorised_bay','unregistered_vehicle','other')` | no | — |  |
| `status` | `enum('open','resolved','dismissed')` | no | `'open'` | `idx_parking_violations_estate[2]` |
| `notes` | `varchar(500)` | YES | `NULL` |  |
| `reported_by_user_id` | `bigint(20) unsigned` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_parking_violations_estate[3]` |
| `resolved_at` | `datetime(3)` | YES | `NULL` |  |

Indexes: `idx_parking_violations_bay_estate`(bay_id,estate_id), `idx_parking_violations_estate`(estate_id,status,created_at), `idx_parking_violations_vehicle`(vehicle_id), `PRIMARY`(id) UNIQUE, `uq_parking_violations_uuid`(uuid) UNIQUE

- FK `fk_parking_violations_bay`: (bay_id, estate_id) → `parking_bays`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_parking_violations_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `parking_zones` — Parking zones (no status column: zones cannot be archived).

Rows (live dev): **14**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_parking_zones_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_parking_zones_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `uq_parking_zones_estate_name (U)`<br>`uq_parking_zones_id_estate[2]` |
| `name` | `varchar(100)` | no | — | `uq_parking_zones_estate_name[2]` |
| `zone_type` | `enum('resident','visitor','mixed','service')` | no | — |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `PRIMARY`(id) UNIQUE, `uq_parking_zones_estate_name`(estate_id,name) UNIQUE, `uq_parking_zones_id_estate`(id,estate_id) UNIQUE, `uq_parking_zones_uuid`(uuid) UNIQUE

- FK `fk_parking_zones_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

#### `vehicles` — Household vehicles (plates, soft archive).

Rows (live dev): **3**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_vehicles_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_vehicles_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_vehicles_household_estate[2]`<br>`uq_vehicles_estate_plate (U)`<br>`uq_vehicles_id_estate[2]` |
| `household_id` | `bigint(20) unsigned` | no | — | `idx_vehicles_household_estate`<br>`idx_vehicles_household_status` |
| `plate_number` | `varchar(20)` | no | — | `uq_vehicles_estate_plate[2]` |
| `vehicle_type` | `enum('car','motorcycle','van','truck','other')` | no | `'car'` |  |
| `colour` | `varchar(40)` | YES | `NULL` |  |
| `status` | `enum('active','inactive','blacklisted')` | no | `'active'` | `idx_vehicles_household_status[2]` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_vehicles_household_estate`(household_id,estate_id), `idx_vehicles_household_status`(household_id,status), `PRIMARY`(id) UNIQUE, `uq_vehicles_estate_plate`(estate_id,plate_number) UNIQUE, `uq_vehicles_id_estate`(id,estate_id) UNIQUE, `uq_vehicles_uuid`(uuid) UNIQUE

- FK `fk_vehicles_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_vehicles_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

### Staff & incidents

#### `access_incidents` — Visitor/security incidents (Inc-6); status workflow, never deleted.

Rows (live dev): **39**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_access_incidents_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_access_incidents_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_access_incidents_estate_severity`<br>`idx_access_incidents_estate_status_created`<br>`idx_access_incidents_gate_estate[2]`<br>`idx_access_incidents_household_estate[2]`<br>`idx_access_incidents_invitation_estate[2]`<br>`uq_access_incidents_id_estate[2]` |
| `gate_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_access_incidents_gate_created`<br>`idx_access_incidents_gate_estate` |
| `household_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_access_incidents_household_estate` |
| `invitation_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_access_incidents_invitation_estate` |
| `reported_by_user_id` | `bigint(20) unsigned` | no | — | `idx_access_incidents_reporter` |
| `incident_type` | `enum('unauthorised_access','tailgating','lost_pass','suspicious_activity','property_damage','medical','other')` | no | — |  |
| `severity` | `enum('low','medium','high','critical')` | no | `'medium'` | `idx_access_incidents_estate_severity[2]` |
| `status` | `enum('open','investigating','resolved','dismissed')` | no | `'open'` | `idx_access_incidents_estate_status_created[2]` |
| `summary` | `varchar(180)` | no | — |  |
| `description` | `text` | no | — |  |
| `occurred_at` | `datetime(3)` | no | — |  |
| `resolved_at` | `datetime(3)` | YES | `NULL` |  |
| `resolved_by_user_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_access_incidents_resolved_by` |
| `resolution_notes` | `varchar(500)` | YES | `NULL` |  |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` | `idx_access_incidents_estate_status_created[3]`<br>`idx_access_incidents_gate_created[2]`<br>`idx_access_incidents_reporter[2]` |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_access_incidents_estate_severity`(estate_id,severity), `idx_access_incidents_estate_status_created`(estate_id,status,created_at), `idx_access_incidents_gate_created`(gate_id,created_at), `idx_access_incidents_gate_estate`(gate_id,estate_id), `idx_access_incidents_household_estate`(household_id,estate_id), `idx_access_incidents_invitation_estate`(invitation_id,estate_id), `idx_access_incidents_reporter`(reported_by_user_id,created_at), `idx_access_incidents_resolved_by`(resolved_by_user_id), `PRIMARY`(id) UNIQUE, `uq_access_incidents_id_estate`(id,estate_id) UNIQUE, `uq_access_incidents_uuid`(uuid) UNIQUE

- FK `fk_access_incidents_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_access_incidents_gate`: (gate_id, estate_id) → `gates`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_access_incidents_household`: (household_id, estate_id) → `households`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_access_incidents_invitation`: (invitation_id, estate_id) → `visitor_invitations`(id, estate_id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_access_incidents_reporter`: (reported_by_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_access_incidents_resolved_by`: (resolved_by_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

### Notices

#### `estate_notices` — Estate notices/announcements (Inc-7); draft/published/archived.

Rows (live dev): **20**.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | `PRIMARY (U)`<br>`uq_estate_notices_id_estate (U)` |
| `uuid` | `char(36)` | no | — | `uq_estate_notices_uuid (U)` |
| `estate_id` | `bigint(20) unsigned` | no | — | `idx_estate_notices_estate_category`<br>`idx_estate_notices_estate_status_published`<br>`uq_estate_notices_id_estate[2]` |
| `title` | `varchar(160)` | no | — |  |
| `body` | `text` | no | — |  |
| `category` | `enum('general','security','maintenance','event','emergency')` | no | `'general'` | `idx_estate_notices_estate_category[2]` |
| `priority` | `enum('normal','important','urgent')` | no | `'normal'` |  |
| `status` | `enum('draft','published','archived')` | no | `'draft'` | `idx_estate_notices_estate_status_published[2]` |
| `audience` | `enum('all','residents','staff')` | no | `'all'` |  |
| `published_at` | `datetime(3)` | YES | `NULL` | `idx_estate_notices_estate_status_published[3]` |
| `expires_at` | `datetime(3)` | YES | `NULL` |  |
| `created_by_user_id` | `bigint(20) unsigned` | no | — | `idx_estate_notices_created_by` |
| `updated_by_user_id` | `bigint(20) unsigned` | YES | `NULL` | `idx_estate_notices_updated_by` |
| `created_at` | `datetime(3)` | no | `current_timestamp(3)` |  |
| `updated_at` | `datetime(3)` | no | `current_timestamp(3)` |  |

Indexes: `idx_estate_notices_created_by`(created_by_user_id), `idx_estate_notices_estate_category`(estate_id,category), `idx_estate_notices_estate_status_published`(estate_id,status,published_at), `idx_estate_notices_updated_by`(updated_by_user_id), `PRIMARY`(id) UNIQUE, `uq_estate_notices_id_estate`(id,estate_id) UNIQUE, `uq_estate_notices_uuid`(uuid) UNIQUE

- FK `fk_estate_notices_created_by`: (created_by_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_estate_notices_estate`: (estate_id) → `estates`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.
- FK `fk_estate_notices_updated_by`: (updated_by_user_id) → `users`(id) — ON UPDATE/DELETE: RESTRICT / RESTRICT.

## 4. ENUM catalogue

| Table.column | Allowed values |
|---|---|
| `access_credentials.credential_type` | `pin`, `qr` |
| `access_credentials.status` | `active`, `used`, `revoked`, `expired` |
| `access_incidents.incident_type` | `unauthorised_access`, `tailgating`, `lost_pass`, `suspicious_activity`, `property_damage`, `medical`, `other` |
| `access_incidents.severity` | `low`, `medium`, `high`, `critical` |
| `access_incidents.status` | `open`, `investigating`, `resolved`, `dismissed` |
| `access_verification_attempts.credential_type` | `pin`, `qr` |
| `buildings.building_type` | `apartment_block`, `street`, `cluster`, `zone` |
| `estates.estate_type` | `apartment`, `maisonette`, `mixed` |
| `estates.status` | `active`, `suspended`, `archived` |
| `estate_memberships.status` | `active`, `pending`, `suspended` |
| `estate_notices.category` | `general`, `security`, `maintenance`, `event`, `emergency` |
| `estate_notices.priority` | `normal`, `important`, `urgent` |
| `estate_notices.status` | `draft`, `published`, `archived` |
| `estate_notices.audience` | `all`, `residents`, `staff` |
| `gates.status` | `active`, `inactive` |
| `guard_gate_assignments.status` | `active`, `inactive` |
| `households.household_type` | `apartment`, `maisonette`, `other` |
| `households.status` | `active`, `inactive` |
| `household_members.status` | `active`, `inactive` |
| `household_staff.staff_type` | `domestic`, `driver`, `gardener`, `nanny`, `cook`, `security`, `other` |
| `household_staff.status` | `active`, `inactive` |
| `notification_logs.channel` | `sms`, `push`, `in_app` |
| `notification_logs.status` | `queued`, `sent`, `failed`, `delivered` |
| `otp_challenges.purpose` | `login`, `staff_login` |
| `parking_allocations.status` | `active`, `suspended`, `ended` |
| `parking_bays.bay_type` | `resident`, `visitor`, `accessible`, `service` |
| `parking_bays.status` | `available`, `reserved`, `occupied`, `maintenance` |
| `parking_reservations.status` | `reserved`, `checked_in`, `completed`, `cancelled`, `expired` |
| `parking_sessions.status` | `active`, `completed`, `overstay`, `void` |
| `parking_violations.violation_type` | `overstay`, `unauthorised_bay`, `unregistered_vehicle`, `other` |
| `parking_violations.status` | `open`, `resolved`, `dismissed` |
| `parking_zones.zone_type` | `resident`, `visitor`, `mixed`, `service` |
| `permissions.scope` | `platform`, `estate` |
| `platform_memberships.status` | `active`, `suspended` |
| `roles.scope` | `platform`, `estate` |
| `saas_accounts.status` | `active`, `suspended`, `closed` |
| `subscriptions.status` | `trialing`, `active`, `past_due`, `suspended`, `cancelled`, `expired` |
| `subscriptions.billing_cycle` | `monthly`, `annual` |
| `subscription_plans.status` | `active`, `retired` |
| `users.status` | `active`, `pending`, `suspended` |
| `vehicles.vehicle_type` | `car`, `motorcycle`, `van`, `truck`, `other` |
| `vehicles.status` | `active`, `inactive`, `blacklisted` |
| `visitor_access_events.event_type` | `check_in`, `check_out`, `denied`, `manual_override` |
| `visitor_access_events.source` | `online`, `offline_sync` |
| `visitor_invitations.status` | `pending`, `approved`, `checked_in`, `checked_out`, `revoked`, `expired`, `denied` |
| `walk_in_requests.status` | `pending`, `approved`, `rejected`, `expired` |

## 5. RBAC summary

**52 permissions**, **13 roles**, **217 role-permission links**.

| Role | Scope | Links | Description |
|---|---|---|---|
| `platform_finance` | platform | 5 | Manage billing, invoices and payments. |
| `platform_operations` | platform | 5 | Operate estate onboarding and subscriptions. |
| `platform_super_admin` | platform | 52 | Full platform administration across every estate. |
| `platform_support` | platform | 2 | Support tenants and review platform audit history. |
| `auditor` | estate | 12 | Read-only audit and reporting access. |
| `estate_admin` | estate | 33 | Full administration of a single estate. |
| `estate_manager` | estate | 24 | Day-to-day estate operations management. |
| `household_member` | estate | 11 | Additional member of a household. |
| `parking_manager` | estate | 11 | Manage parking bays, allocations and violations. |
| `resident` | estate | 17 | Resident of an estate household. |
| `security_guard` | estate | 10 | Operate gates and visitor check-in. |
| `security_supervisor` | estate | 19 | Supervise gate security operations. |
| `tenant` | estate | 16 | Renting tenant of an estate household. |

Permission catalogue by prefix: `audit.*` 1 · `billing.*` 4 · `estate.*` 3 · `gate.*` 2 · `guard.*` 1 · `household.*` 4 · `notice.*` 2 · `parking.*` 8 · `platform.*` 5 · `reports.*` 3 · `vehicle.*` 4 · `visitor.*` 15.
`platform_super_admin` maps to `self::ALL_PERMISSIONS` (currently 52), so adding a permission increases that role automatically.
Demo platform super admin: `+254700000020` (Daniel Mwangi, scope `platform_super_admin`, active platform membership).

## 6. Seed / fixture inventory (canonical demos)

> Values below are the seeded demo fixtures; live counts may be higher where QA/acceptance smoke created rows.

| Fixture | Canonical seed rows | Live dev rows (incl. QA smoke) | Canonical identifier rule |
|---|---|---|---|
| SaaS accounts | 1 | 1 | single demo account |
| Plans | 3 | 3 | code in starter/growth/premium |
| Estates | 2 | 2 | slug greenview/riverside |
| Users | 15 | 17 | demo phones +254700000001-14 +20 |
| Greenview settings | 5 | 5 | 5 setting keys |
| Households | 14 | 33 | units A-001..006/B-001..003/W-01..02/S-01/R-101..102 |
| Buildings | 5 | 6 | canonical names |
| Gates | 3 | 4 | canonical names |
| Guard assignments | 4 | 4 | canonical gates |
| Vehicles | 3 | 3 | uuid 2c… |
| Parking zones | 2 | 14 | uuid 2a… |
| Parking bays | 14 | 40 | bays R-01..08/V-01..06 |
| Allocations | 3 | 10 | uuid 2d… |
| Reservations | 1 | 6 | uuid 2e… |
| Sessions | 1 | 12 | uuid 2f… |
| Visitors | 4 | 15 | uuid a1… |
| Visitor invitations | 4 | 15 | uuid b1… |
| Access credentials | 8 | 18 | uuid c1… |
| Visitor access events | 5 | 10 | uuid d10… |
| Walk-in requests | 1 | 1 | uuid f1… |
| Household staff | 3 | 18 | uuid 3a… |
| Access incidents | 3 | 39 | uuid 3b… |
| Estate notices | 4 | 20 | uuid 4a… |

### Demo identifiers

- **Estates:** `greenview-residences` (Greenview Residences), `riverside-court` (Riverside Court).
- **Plans:** `starter` Starter (250000 cents KES, active) · `growth` Growth (500000 cents KES, active) · `premium` Premium (900000 cents KES, active).
- **Buildings:** Block A, Block B, Willow Court, Acacia Street, Tower 1.
- **Households:** A-001@greenview-residences, A-002@greenview-residences, A-003@greenview-residences, A-004@greenview-residences, A-005@greenview-residences, A-006@greenview-residences, B-001@greenview-residences, B-002@greenview-residences, B-003@greenview-residences, W-01@greenview-residences, W-02@greenview-residences, S-01@greenview-residences, R-101@riverside-court, R-102@riverside-court.
- **Gates:** Main Gate (active), Service Gate (inactive), Riverside Gate (active).
- **Vehicles:** KDA123A, KCA456B, KDD789C.
- **Parking zones/bays:** Resident Zone A (resident), Visitor Zone V (visitor); bays R-01, R-02, R-03, R-04, R-05, R-06, R-07, R-08, V-01, V-02, V-03, V-04, V-05, V-06.
- **Demo users (phones):** `+254700000001` Grace Wanjiku, `+254700000002` Peter Ochieng, `+254700000003` Samuel Kariuki, `+254700000004` John Mutiso, `+254700000005` David Kiptoo, `+254700000006` Faith Achieng, `+254700000007` Brian Ochieng, `+254700000008` Mary Atieno, `+254700000009` Lucy Wambui, `+254700000010` Jane Smith, `+254700000011` Brian Otieno, `+254700000012` Lydia Njeri, `+254700000013` Amina Hassan, `+254700000014` David Njoroge, `+254700000020` Daniel Mwangi.
- **Greenview settings:** `max_visitors_per_invitation=5`, `parking_overstay_grace_minutes=30`, `visitor_parking_grace_minutes=30`, `visitor_validity_minutes=240`, `walkin_approval_timeout_seconds=300`.
- **Demo staff:** `3a000001-0000-4000-8000-000000000001` Demo Housekeeper (+254700091001, domestic, active); `3a000001-0000-4000-8000-000000000002` Demo Gardener (+254700091002, gardener, active); `3a000001-0000-4000-8000-000000000003` Demo Driver (+254700091003, driver, active).
- **Demo incidents:** `3b000001-0000-4000-8000-000000000001` suspicious_activity/medium/open; `3b000001-0000-4000-8000-000000000002` unauthorised_access/high/investigating; `3b000001-0000-4000-8000-000000000003` lost_pass/low/resolved.
- **Demo notices:**
  - `4a000001-0000-4000-8000-000000000001` published · general/normal · Water supply maintenance on Saturday
  - `4a000001-0000-4000-8000-000000000002` published · security/important · Main gate barrier upgrade
  - `4a000001-0000-4000-8000-000000000003` published · emergency/urgent · Lift maintenance - Block B
  - `4a000001-0000-4000-8000-000000000004` draft · general/normal · Upcoming community clean-up

## 7. Staged, NOT applied — Increment 10 billing tables

Migration `20261111_000010_create_billing_tables.sql` is staged under
`storage/temporary/inc10/` and **not applied** (dev DB remains 8 migrations / 37 tables).
At the Inc-10 DB gate it will add (additive only):

| Table | Purpose | Notable keys/FKs |
|---|---|---|
| `billing_invoices` | Platform-finance invoices (per estate + account) | `UNIQUE(uuid)`, **`UNIQUE(estate_id, invoice_number)`**, `UNIQUE(id, estate_id)`; 5 FKs incl nullable composite `(subscription_id, estate_id) → subscriptions(id, estate_id)`; enums `status draft\|open\|paid\|void\|past_due` |
| `billing_payments` | Append-only payment ledger per invoice | `UNIQUE(uuid)`, `UNIQUE(id, estate_id)`; composite tenant FK `(invoice_id, estate_id) → billing_invoices(id, estate_id)`, estate + recorded_by FKs; enums `method mpesa\|bank\|card\|cash\|other`, `status pending\|confirmed\|failed\|refunded` |
| `subscriptions` (ALTER) | Additive `UNIQUE KEY uq_subscriptions_id_estate (id, estate_id)` | parent index for the invoice composite FK; non-destructive, no data change |

After Inc-10: **9 migrations / 39 tables / 52 permissions / 217 links**; demo seed adds 2 Greenview invoices (1 paid `INV-202610-0001` + 1 open `INV-<YYYYMM>-0001`) and 1 confirmed M-Pesa payment. Inc-8/Inc-9 add **no tables**.

## 8. Consistency check (read-only)

Checked: FK child-index coverage, orphan rows per FK (MATCH SIMPLE semantics), ENUM values outside declared lists, `UNIQUE(uuid)` presence, FK parent unique indexes, InnoDB/utf8mb4 conventions, FK child/parent type parity.

**Anomalies: none.** All 86 foreign keys have child indexes and zero orphan rows; every table that has a `uuid` column has a `UNIQUE(uuid)` index; all engines/collations are InnoDB/utf8mb4_unicode_ci; all FK child/parent types match; no ENUM stores undeclared values.

Informational (expected, not defects):

- Table `access_verification_attempts` has no `uuid` column (internal catalogue/link/append-only table).
- Table `estate_settings` has no `uuid` column (internal catalogue/link/append-only table).
- Table `permissions` has no `uuid` column (internal catalogue/link/append-only table).
- Table `rate_limits` has no `uuid` column (internal catalogue/link/append-only table).
- Table `roles` has no `uuid` column (internal catalogue/link/append-only table).
- Table `role_permissions` has no `uuid` column (internal catalogue/link/append-only table).
- Table `schema_migrations` has no `uuid` column (internal catalogue/link/append-only table).
- Table `access_credentials` has no `updated_at` column (immutable/link/append-only tables).
- Table `access_verification_attempts` has no `updated_at` column (immutable/link/append-only tables).
- Table `audit_logs` has no `updated_at` column (immutable/link/append-only tables).
- Table `buildings` has no `updated_at` column (immutable/link/append-only tables).
- Table `gates` has no `updated_at` column (immutable/link/append-only tables).
- Table `guard_gate_assignments` has no `updated_at` column (immutable/link/append-only tables).
- Table `households` has no `updated_at` column (immutable/link/append-only tables).
- Table `notification_logs` has no `updated_at` column (immutable/link/append-only tables).
- Table `otp_challenges` has no `updated_at` column (immutable/link/append-only tables).
- Table `parking_violations` has no `updated_at` column (immutable/link/append-only tables).
- Table `parking_zones` has no `updated_at` column (immutable/link/append-only tables).
- Table `permissions` has no `created_at` column.
- Table `permissions` has no `updated_at` column (immutable/link/append-only tables).
- Table `roles` has no `created_at` column.
- Table `roles` has no `updated_at` column (immutable/link/append-only tables).
- Table `schema_migrations` has no `created_at` column.
- Table `schema_migrations` has no `updated_at` column (immutable/link/append-only tables).
- Table `visitors` has no `updated_at` column (immutable/link/append-only tables).
- Table `visitor_access_events` has no `updated_at` column (immutable/link/append-only tables).

---
*Generated read-only from `mtaapass` at 2026-11-11 18:55:01 EAT by `storage/temporary/gen-db-schema.php` (throwaway). Regenerate after schema changes; keep `docs/database-schema.md` in sync with migrations.*
