-- MtaaPass migration 20261111_000015
-- Estate-managed WhatsApp Cloud API credentials (owner request 2026-11-13).
--
-- The Estate Admin enters the Meta/WhatsApp Cloud API details for their estate:
-- phone number id, WhatsApp Business Account id, permanent access token, app
-- secret, webhook verify token and the approved template used for business
-- initiated messages. Access token, app secret and verify token are stored
-- encrypted at rest by App\Modules\Notifications\WhatsAppSettingsService (the
-- columns hold the encrypted payload, never plaintext).
--
-- One settings row per estate; the estate FK keeps it tenant-scoped.

CREATE TABLE estate_whatsapp_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(60) NOT NULL,
    waba_id VARCHAR(60) NULL,
    access_token_encrypted TEXT NULL,
    app_secret_encrypted TEXT NULL,
    verify_token_encrypted TEXT NULL,
    template_name VARCHAR(120) NOT NULL DEFAULT 'estate_notification',
    template_language VARCHAR(12) NOT NULL DEFAULT 'en',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_estate_whatsapp_settings_uuid (uuid),
    UNIQUE KEY uq_estate_whatsapp_settings_estate (estate_id),
    CONSTRAINT fk_estate_whatsapp_settings_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_estate_whatsapp_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
