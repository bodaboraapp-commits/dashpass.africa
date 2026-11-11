-- MtaaPass foundation migration 20261111_000004
-- Security, operational and audit tables: audit trail, OTP challenges,
-- fixed-window rate limits and notification delivery log.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- audit_logs is deliberately append-only and has no foreign keys so the
-- security history stays intact even if a subject row changes.

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    correlation_id CHAR(36) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_audit_logs_uuid (uuid),
    KEY idx_audit_logs_estate (estate_id, created_at),
    KEY idx_audit_logs_actor (actor_id, created_at),
    KEY idx_audit_logs_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    purpose ENUM('login', 'staff_login') NOT NULL DEFAULT 'login',
    user_id BIGINT UNSIGNED NULL,
    otp_hash CHAR(64) NOT NULL,
    expires_at DATETIME(3) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    consumed_at DATETIME(3) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_otp_challenges_uuid (uuid),
    KEY idx_otp_challenges_phone_purpose (phone, purpose, created_at),
    KEY idx_otp_challenges_expires (expires_at),
    KEY idx_otp_challenges_user (user_id),
    CONSTRAINT fk_otp_challenges_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    rl_key VARCHAR(160) NOT NULL,
    window_started_at DATETIME(3) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    expires_at DATETIME(3) NOT NULL,
    PRIMARY KEY (rl_key),
    KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NULL,
    channel ENUM('sms', 'push', 'in_app') NOT NULL DEFAULT 'sms',
    recipient VARCHAR(190) NOT NULL,
    template VARCHAR(80) NULL,
    provider VARCHAR(40) NULL,
    provider_message_id VARCHAR(120) NULL,
    status ENUM('queued', 'sent', 'failed', 'delivered') NOT NULL DEFAULT 'queued',
    error VARCHAR(500) NULL,
    sent_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_logs_uuid (uuid),
    KEY idx_notification_logs_estate (estate_id, created_at),
    KEY idx_notification_logs_status (status),
    CONSTRAINT fk_notification_logs_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
