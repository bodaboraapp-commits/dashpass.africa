-- MtaaPass migration 20261111_000019
-- Synced WhatsApp message templates (owner request 2026-11-15).
--
-- The Estate Admin syncs the approved templates from the estate WhatsApp Business
-- Account (Meta Graph /{waba_id}/message_templates) so the broadcast composer can
-- pick a real approved template + language instead of typing its name, and the
-- parameter count is validated before sending.
--
-- One row per template name + language per estate; the raw Meta components are kept
-- for future rendering and the body text/parameter count are extracted for the UI.

CREATE TABLE whatsapp_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    waba_id VARCHAR(60) NOT NULL,
    meta_template_id VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    language VARCHAR(12) NOT NULL DEFAULT 'en',
    status VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
    category VARCHAR(30) NULL,
    body_text TEXT NULL,
    parameter_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    components TEXT NULL,
    synced_at DATETIME(3) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_templates_uuid (uuid),
    UNIQUE KEY uq_whatsapp_templates_name (estate_id, name, language),
    KEY idx_whatsapp_templates_estate_status (estate_id, status),
    CONSTRAINT fk_whatsapp_templates_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
