-- MtaaPass/DashPASS Increment 10 migration 20261111_000010
-- Billing / invoices (contract §1). Additive only: two new tables + one
-- non-destructive UNIQUE index on subscriptions for the invoice composite FK.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8.
--
-- No data statements, no destructive ALTERs, no changes to any other table.
-- Money is integer minor units (cents); currency is a 3-letter ISO code.
-- No hard deletes: invoices use status void; payments use failed/refunded.
--
-- STATEMENT ORDER MATTERS: the subscriptions (id, estate_id) unique index must
-- exist BEFORE billing_invoices is created, because billing_invoices declares the
-- composite FK (subscription_id, estate_id) -> subscriptions (id, estate_id) and
-- InnoDB requires an index on the referenced parent columns at FK creation time.
-- The ALTER is additive/non-destructive (index only, no data change).

ALTER TABLE subscriptions ADD UNIQUE KEY uq_subscriptions_id_estate (id, estate_id);

CREATE TABLE billing_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    invoice_number VARCHAR(40) NOT NULL,
    status ENUM('draft', 'open', 'paid', 'void', 'past_due') NOT NULL DEFAULT 'open',
    period_start DATE NULL,
    period_end DATE NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    due_at DATETIME(3) NULL,
    paid_at DATETIME(3) NULL,
    notes VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_invoices_uuid (uuid),
    UNIQUE KEY uq_billing_invoices_estate_number (estate_id, invoice_number),
    UNIQUE KEY uq_billing_invoices_id_estate (id, estate_id),
    KEY idx_billing_invoices_estate_status_due (estate_id, status, due_at),
    KEY idx_billing_invoices_account_created (account_id, created_at),
    KEY idx_billing_invoices_subscription (subscription_id, estate_id),
    KEY idx_billing_invoices_created_by (created_by_user_id),
    KEY idx_billing_invoices_updated_by (updated_by_user_id),
    CONSTRAINT fk_billing_invoices_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_billing_invoices_account FOREIGN KEY (account_id) REFERENCES saas_accounts (id),
    CONSTRAINT fk_billing_invoices_subscription FOREIGN KEY (subscription_id, estate_id) REFERENCES subscriptions (id, estate_id),
    CONSTRAINT fk_billing_invoices_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_billing_invoices_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    method ENUM('mpesa', 'bank', 'card', 'cash', 'other') NOT NULL,
    reference VARCHAR(80) NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    status ENUM('pending', 'confirmed', 'failed', 'refunded') NOT NULL DEFAULT 'confirmed',
    paid_at DATETIME(3) NULL,
    recorded_by_user_id BIGINT UNSIGNED NOT NULL,
    metadata JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_payments_uuid (uuid),
    UNIQUE KEY uq_billing_payments_id_estate (id, estate_id),
    KEY idx_billing_payments_invoice_status (invoice_id, status),
    KEY idx_billing_payments_invoice_estate (invoice_id, estate_id),
    KEY idx_billing_payments_estate_created (estate_id, created_at),
    KEY idx_billing_payments_recorded_by (recorded_by_user_id),
    CONSTRAINT fk_billing_payments_invoice FOREIGN KEY (invoice_id, estate_id) REFERENCES billing_invoices (id, estate_id),
    CONSTRAINT fk_billing_payments_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_billing_payments_recorded_by FOREIGN KEY (recorded_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
