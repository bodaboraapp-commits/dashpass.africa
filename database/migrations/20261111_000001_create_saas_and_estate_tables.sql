-- MtaaPass foundation migration 20261111_000001
-- SaaS accounts, estates, estate settings, subscription plans and subscriptions.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8 (no functional indexes,
-- no DEFAULT on JSON/TEXT columns, no CHECK-constraint dependencies).

CREATE TABLE saas_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    status ENUM('active', 'suspended', 'closed') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_saas_accounts_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Nairobi',
    estate_type ENUM('apartment', 'maisonette', 'mixed') NOT NULL DEFAULT 'mixed',
    status ENUM('active', 'suspended', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_estates_uuid (uuid),
    UNIQUE KEY uq_estates_slug (slug),
    UNIQUE KEY uq_estates_id_account (id, account_id),
    KEY idx_estates_account_status (account_id, status),
    CONSTRAINT fk_estates_account FOREIGN KEY (account_id) REFERENCES saas_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estate_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estate_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_estate_settings_key (estate_id, setting_key),
    CONSTRAINT fk_estate_settings_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    monthly_price_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    household_limit INT UNSIGNED NULL,
    gate_device_limit SMALLINT UNSIGNED NULL,
    features JSON NULL,
    status ENUM('active', 'retired') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_plans_uuid (uuid),
    UNIQUE KEY uq_subscription_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired') NOT NULL DEFAULT 'trialing',
    billing_cycle ENUM('monthly', 'annual') NOT NULL DEFAULT 'monthly',
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NULL,
    trial_ends_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscriptions_uuid (uuid),
    KEY idx_subscriptions_estate_status (estate_id, status),
    KEY idx_subscriptions_plan (plan_id),
    CONSTRAINT fk_subscriptions_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
