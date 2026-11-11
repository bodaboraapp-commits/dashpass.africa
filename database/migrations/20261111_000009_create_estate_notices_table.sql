-- MtaaPass/DashPASS Increment 7 migration 20261111_000009
-- Estate notices / announcements (contract section 1).
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8.
--
-- Increment 7 adds ONLY this one table: no ALTERs, no changes to existing
-- tables. Notices are estate-scoped (estate FK + estate-scoped application
-- queries); the UNIQUE (id, estate_id) key keeps the door open for future
-- tenant-composite children. No hard deletes: status draft|published|archived.

CREATE TABLE estate_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    category ENUM('general', 'security', 'maintenance', 'event', 'emergency') NOT NULL DEFAULT 'general',
    priority ENUM('normal', 'important', 'urgent') NOT NULL DEFAULT 'normal',
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    audience ENUM('all', 'residents', 'staff') NOT NULL DEFAULT 'all',
    published_at DATETIME(3) NULL,
    expires_at DATETIME(3) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_estate_notices_uuid (uuid),
    UNIQUE KEY uq_estate_notices_id_estate (id, estate_id),
    KEY idx_estate_notices_estate_status_published (estate_id, status, published_at),
    KEY idx_estate_notices_estate_category (estate_id, category),
    KEY idx_estate_notices_created_by (created_by_user_id),
    KEY idx_estate_notices_updated_by (updated_by_user_id),
    CONSTRAINT fk_estate_notices_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_estate_notices_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_estate_notices_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
