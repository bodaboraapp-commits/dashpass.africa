-- MtaaPass migration 20261111_000018
-- WhatsApp broadcast messaging (owner request 2026-11-15).
--
-- The Estate Admin composes a broadcast for the estate's opted-in people (active
-- estate residents and/or the household staff directory), picks an approved Meta
-- template, previews it, then sends now or schedules it. Each recipient attempt is
-- logged, and recipients can opt out (per phone) from the resident Account screen.
--
-- Delivery is handled by App\Modules\Notifications\WhatsAppBroadcastService through
-- the estate's existing WhatsApp Cloud API credentials.

CREATE TABLE whatsapp_broadcasts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    template_name VARCHAR(120) NOT NULL,
    template_language VARCHAR(12) NOT NULL DEFAULT 'en',
    parameters TEXT NULL,
    preview_text TEXT NULL,
    audience ENUM('residents', 'staff', 'both') NOT NULL DEFAULT 'residents',
    status ENUM('draft', 'scheduled', 'sending', 'sent', 'partial', 'failed', 'cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    recipients_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    sent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_broadcasts_uuid (uuid),
    KEY idx_whatsapp_broadcasts_estate_status (estate_id, status, created_at),
    KEY idx_whatsapp_broadcasts_schedule (estate_id, status, scheduled_at),
    CONSTRAINT fk_whatsapp_broadcasts_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_whatsapp_broadcasts_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_broadcast_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    broadcast_id BIGINT UNSIGNED NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    recipient_kind ENUM('resident', 'staff') NOT NULL DEFAULT 'resident',
    user_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    recipient_name VARCHAR(180) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    status ENUM('queued', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'queued',
    error_code VARCHAR(60) NULL,
    provider_message_id VARCHAR(120) NULL,
    sent_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_broadcast_recipients_uuid (uuid),
    KEY idx_whatsapp_broadcast_recipients_broadcast (broadcast_id, status),
    KEY idx_whatsapp_broadcast_recipients_estate (estate_id, created_at),
    CONSTRAINT fk_whatsapp_broadcast_recipients_broadcast FOREIGN KEY (broadcast_id) REFERENCES whatsapp_broadcasts (id),
    CONSTRAINT fk_whatsapp_broadcast_recipients_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_opt_outs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    phone VARCHAR(20) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_opt_outs_uuid (uuid),
    UNIQUE KEY uq_whatsapp_opt_outs_estate_phone (estate_id, phone),
    KEY idx_whatsapp_opt_outs_user (user_id),
    CONSTRAINT fk_whatsapp_opt_outs_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_whatsapp_opt_outs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
