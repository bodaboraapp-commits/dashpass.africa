-- MtaaPass/DashPASS Increment 13 (owner feature) — automated invoice generation & sending
-- Staged migration: schedules + utility charges + platform_settings + delivery/payment extensions
-- DESIGN FROZEN (T164/T165/T166/T167/T168) — NOT APPLIED
--
-- Build order: automated billing ships FIRST as migration 000011; Inc-12 images = 000012.
-- Expected after apply: 10 migrations / 42 tables / 84 FKs / 52 perms / 13 roles / 217 links.
-- No new permission/role/link rows. Payment links are stateless signed tokens (no table/FK).
--
-- This file packs ALL Inc-13 DDL (6 statements: 3 CREATE + 3 additive ALTER):
--   1. CREATE billing_schedules        (frozen T159 model, both scopes; FKs 1-4)
--   2. ALTER billing_invoices          (single ledger: kind/household/source/generation/period/
--                                       pricing/send markers; system actor nullable creator;
--                                       household composite FK 12)
--   3. CREATE utility_charges          (per-household/period utility detail; FKs 5-10)
--   4. ALTER notification_logs         (delivery ledger + in-app inbox: email/whatsapp channels,
--                                       dedupe/attempts/title/body/link/read_at)
--   5. ALTER billing_payments          (provider/provider_txn_id/provider_status + webhook
--                                       idempotency UNIQUE(provider, provider_txn_id))
--   6. CREATE platform_settings        (global non-secret config; FK 11)
--
-- MySQL 8 / MariaDB 10.4 compatible: CREATE TABLE + ADD COLUMN/KEY/CONSTRAINT + MODIFY for the
-- nullable system actor and the widened notification channel enum. JSON columns per project
-- convention. No data statements, no destructive drops.

CREATE TABLE billing_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    scope ENUM('estate_subscription', 'utility_bill') NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NULL,
    utility_type ENUM('water', 'electricity', 'gas', 'other') NULL,
    flat_amount_cents INT UNSIGNED NULL,
    tariff_cents_per_unit INT UNSIGNED NULL,
    unit_label VARCHAR(20) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    period_unit ENUM('month', 'quarter', 'year', 'week', 'once') NOT NULL DEFAULT 'month',
    generate_lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    due_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    reminder_before_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    reminder_on_due TINYINT(1) NOT NULL DEFAULT 1,
    proration_policy ENUM('none', 'first_period') NOT NULL DEFAULT 'none',
    send_on_generate TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'paused', 'archived') NOT NULL DEFAULT 'active',
    next_run_at DATETIME(3) NOT NULL,
    last_run_at DATETIME(3) NULL,
    last_period_key VARCHAR(20) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_schedules_uuid (uuid),
    UNIQUE KEY uq_billing_schedules_subscription (subscription_id),
    UNIQUE KEY uq_billing_schedules_estate_scope_name (estate_id, scope, name),
    KEY idx_billing_schedules_due (scope, status, next_run_at),
    KEY idx_billing_schedules_estate_scope (estate_id, scope),
    KEY idx_billing_schedules_subscription_estate (subscription_id, estate_id),
    KEY idx_billing_schedules_created_by (created_by_user_id),
    KEY idx_billing_schedules_updated_by (updated_by_user_id),
    CONSTRAINT fk_billing_schedules_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_billing_schedules_subscription FOREIGN KEY (subscription_id, estate_id) REFERENCES subscriptions (id, estate_id),
    CONSTRAINT fk_billing_schedules_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_billing_schedules_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single ledger for subscription + utility bills: kind/household payer, automation idempotency
-- (generation_key), pricing/send markers; existing rows stay valid. created_by_user_id becomes
-- nullable for system/automation rows (audit actor 'system').
ALTER TABLE billing_invoices
    ADD COLUMN kind ENUM('subscription', 'utility') NOT NULL DEFAULT 'subscription' AFTER account_id,
    ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER kind,
    ADD COLUMN source ENUM('manual', 'automation') NOT NULL DEFAULT 'manual' AFTER household_id,
    ADD COLUMN generation_key VARCHAR(191) NULL AFTER source,
    ADD COLUMN period_key VARCHAR(20) NULL AFTER period_end,
    ADD COLUMN pricing_snapshot JSON NULL AFTER period_key,
    ADD COLUMN sent_at DATETIME(3) NULL AFTER paid_at,
    ADD COLUMN reminder_sent_at DATETIME(3) NULL AFTER sent_at,
    ADD COLUMN last_reminder_stage VARCHAR(20) NULL AFTER reminder_sent_at,
    MODIFY COLUMN created_by_user_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_billing_invoices_estate_generation (estate_id, generation_key),
    ADD KEY idx_billing_invoices_household_estate (household_id, estate_id),
    ADD CONSTRAINT fk_billing_invoices_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id);

CREATE TABLE utility_charges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    billing_schedule_id BIGINT UNSIGNED NOT NULL,
    period_key VARCHAR(20) NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    charge_model ENUM('flat', 'metered', 'both') NOT NULL,
    units DECIMAL(12, 3) NULL,
    reading_start_units DECIMAL(12, 3) NULL,
    reading_end_units DECIMAL(12, 3) NULL,
    reading_source ENUM('manual', 'import') NULL,
    flat_amount_cents INT UNSIGNED NULL,
    tariff_cents_per_unit INT UNSIGNED NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    pricing_snapshot JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_utility_charges_uuid (uuid),
    UNIQUE KEY uq_utility_charges_schedule_household_period (billing_schedule_id, household_id, period_key),
    KEY idx_utility_charges_estate_period (estate_id, period_key),
    KEY idx_utility_charges_household_period (household_id, period_key),
    KEY idx_utility_charges_invoice_estate (invoice_id, estate_id),
    KEY idx_utility_charges_household_estate (household_id, estate_id),
    KEY idx_utility_charges_schedule (billing_schedule_id),
    KEY idx_utility_charges_created_by (created_by_user_id),
    KEY idx_utility_charges_updated_by (updated_by_user_id),
    CONSTRAINT fk_utility_charges_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_utility_charges_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices (id),
    CONSTRAINT fk_utility_charges_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_utility_charges_schedule FOREIGN KEY (billing_schedule_id) REFERENCES billing_schedules (id),
    CONSTRAINT fk_utility_charges_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_utility_charges_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery ledger + in-app inbox reuse (no new outbox/inbox table, NO user FK — keeps 84 FKs):
-- add email/whatsapp channels, per-user in-app notifications (user_id/type/read_at) and
-- dedupe/attempt/title/body/link columns. Bell/inbox queries use the (user_id, read_at) and
-- (user_id, type, created_at) indexes; user_id is app-validated/estate-scoped, not an FK.
ALTER TABLE notification_logs
    MODIFY COLUMN channel ENUM('sms', 'email', 'whatsapp', 'push', 'in_app') NOT NULL DEFAULT 'sms',
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER estate_id,
    ADD COLUMN type ENUM('notice', 'billing', 'system', 'reminder') NOT NULL DEFAULT 'system' AFTER channel,
    ADD COLUMN dedupe_key VARCHAR(191) NULL AFTER template,
    ADD COLUMN attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER error,
    ADD COLUMN next_attempt_at DATETIME(3) NULL AFTER attempts,
    ADD COLUMN error_code VARCHAR(60) NULL AFTER next_attempt_at,
    ADD COLUMN title VARCHAR(160) NULL AFTER error_code,
    ADD COLUMN body VARCHAR(500) NULL AFTER title,
    ADD COLUMN link VARCHAR(255) NULL AFTER body,
    ADD COLUMN read_at DATETIME(3) NULL AFTER sent_at,
    ADD UNIQUE KEY uq_notification_logs_dedupe (dedupe_key),
    ADD KEY idx_notification_logs_user_read (user_id, read_at),
    ADD KEY idx_notification_logs_user_type_created (user_id, type, created_at);

-- Payment-link/webhook support on the existing manual ledger: provider refs + idempotency.
ALTER TABLE billing_payments
    ADD COLUMN provider VARCHAR(40) NULL AFTER method,
    ADD COLUMN provider_txn_id VARCHAR(120) NULL AFTER reference,
    ADD COLUMN provider_status VARCHAR(40) NULL AFTER status,
    ADD UNIQUE KEY uq_billing_payments_provider_txn (provider, provider_txn_id);

-- Global (super-admin) non-secret configuration; credentials stay in .env.
CREATE TABLE platform_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    `key` VARCHAR(120) NOT NULL,
    value JSON NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_settings_uuid (uuid),
    UNIQUE KEY uq_platform_settings_key (`key`),
    KEY idx_platform_settings_updated_by (updated_by_user_id),
    CONSTRAINT fk_platform_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
