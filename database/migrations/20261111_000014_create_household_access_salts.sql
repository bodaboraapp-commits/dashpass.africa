-- MtaaPass migration 20261111_000014
-- Rotatable household access passes: a per-person salt mixed into the signed
-- household pass token. Rotating the PIN writes a new salt, which changes both
-- the 4-digit pass code and the QR token so a previously shared QR stops
-- verifying at the gate.
--
-- Deliberately no FK to household_members/household_staff (person_type selects
-- the table); the estate FK keeps the row tenant-scoped and cleaned up with an
-- estate export/removal.

CREATE TABLE household_access_salts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    person_type ENUM('member', 'staff') NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    salt CHAR(32) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_household_access_salts_uuid (uuid),
    UNIQUE KEY uq_household_access_salts_person (person_type, person_id),
    KEY idx_household_access_salts_estate (estate_id),
    CONSTRAINT fk_household_access_salts_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
