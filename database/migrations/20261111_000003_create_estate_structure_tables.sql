-- MtaaPass foundation migration 20261111_000003
-- Estate structure: buildings, households, household members, gates and
-- guard-to-gate assignments.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Tenant isolation is enforced with composite foreign keys that include
-- estate_id so a household can never point at a building from another estate
-- and a guard assignment can never cross estates.

CREATE TABLE buildings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    building_type ENUM('apartment_block', 'street', 'cluster', 'zone') NOT NULL DEFAULT 'apartment_block',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_buildings_uuid (uuid),
    UNIQUE KEY uq_buildings_estate_name (estate_id, name),
    UNIQUE KEY uq_buildings_id_estate (id, estate_id),
    CONSTRAINT fk_buildings_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE households (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    building_id BIGINT UNSIGNED NULL,
    unit_number VARCHAR(60) NOT NULL,
    household_type ENUM('apartment', 'maisonette', 'other') NOT NULL DEFAULT 'apartment',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_households_uuid (uuid),
    UNIQUE KEY uq_households_estate_unit (estate_id, unit_number),
    UNIQUE KEY uq_households_id_estate (id, estate_id),
    KEY idx_households_building_estate (building_id, estate_id),
    CONSTRAINT fk_households_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_households_building FOREIGN KEY (building_id, estate_id) REFERENCES buildings (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE household_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    relationship VARCHAR(60) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_household_members_uuid (uuid),
    UNIQUE KEY uq_household_members_household_user (household_id, user_id),
    KEY idx_household_members_household_estate (household_id, estate_id),
    KEY idx_household_members_user (user_id, status),
    CONSTRAINT fk_household_members_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_household_members_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(180) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_gates_uuid (uuid),
    UNIQUE KEY uq_gates_estate_name (estate_id, name),
    UNIQUE KEY uq_gates_id_estate (id, estate_id),
    CONSTRAINT fk_gates_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guard_gate_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    guard_user_id BIGINT UNSIGNED NOT NULL,
    shift VARCHAR(40) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_guard_gate_assignments_uuid (uuid),
    UNIQUE KEY uq_guard_gate_assignments_gate_guard (gate_id, guard_user_id),
    KEY idx_guard_gate_assignments_gate_estate (gate_id, estate_id),
    KEY idx_guard_gate_assignments_guard (guard_user_id),
    CONSTRAINT fk_guard_gate_assignments_gate FOREIGN KEY (gate_id, estate_id) REFERENCES gates (id, estate_id),
    CONSTRAINT fk_guard_gate_assignments_guard FOREIGN KEY (guard_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
