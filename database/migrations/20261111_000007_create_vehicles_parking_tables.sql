-- MtaaPass/DashPASS Increment 3 migration 20261111_000007
-- Vehicles & parking: estate-scoped vehicles, parking zones/bays, resident
-- allocations, visitor reservations, parking sessions and violations.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8.
--
-- Tenant isolation: parking children carry estate_id and are tied to their
-- parent through composite foreign keys that include estate_id, so a bay,
-- reservation, session or allocation can never cross estates.

CREATE TABLE vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    vehicle_type ENUM('car', 'motorcycle', 'van', 'truck', 'other') NOT NULL DEFAULT 'car',
    colour VARCHAR(40) NULL,
    status ENUM('active', 'inactive', 'blacklisted') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_uuid (uuid),
    UNIQUE KEY uq_vehicles_estate_plate (estate_id, plate_number),
    UNIQUE KEY uq_vehicles_id_estate (id, estate_id),
    KEY idx_vehicles_household_status (household_id, status),
    KEY idx_vehicles_household_estate (household_id, estate_id),
    CONSTRAINT fk_vehicles_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_vehicles_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_zones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    zone_type ENUM('resident', 'visitor', 'mixed', 'service') NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_zones_uuid (uuid),
    UNIQUE KEY uq_parking_zones_estate_name (estate_id, name),
    UNIQUE KEY uq_parking_zones_id_estate (id, estate_id),
    CONSTRAINT fk_parking_zones_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_bays (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    zone_id BIGINT UNSIGNED NOT NULL,
    bay_code VARCHAR(30) NOT NULL,
    bay_type ENUM('resident', 'visitor', 'accessible', 'service') NOT NULL,
    status ENUM('available', 'reserved', 'occupied', 'maintenance') NOT NULL DEFAULT 'available',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_bays_uuid (uuid),
    UNIQUE KEY uq_parking_bays_estate_code (estate_id, bay_code),
    UNIQUE KEY uq_parking_bays_id_estate (id, estate_id),
    KEY idx_parking_bays_estate_status_type (estate_id, status, bay_type),
    KEY idx_parking_bays_zone_estate (zone_id, estate_id),
    CONSTRAINT fk_parking_bays_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_parking_bays_zone FOREIGN KEY (zone_id, estate_id) REFERENCES parking_zones (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    bay_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NULL,
    status ENUM('active', 'suspended', 'ended') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_allocations_uuid (uuid),
    KEY idx_parking_allocations_bay (bay_id, status),
    KEY idx_parking_allocations_household (household_id, status),
    KEY idx_parking_allocations_bay_estate (bay_id, estate_id),
    KEY idx_parking_allocations_household_estate (household_id, estate_id),
    KEY idx_parking_allocations_vehicle (vehicle_id),
    CONSTRAINT fk_parking_allocations_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_parking_allocations_bay FOREIGN KEY (bay_id, estate_id) REFERENCES parking_bays (id, estate_id),
    CONSTRAINT fk_parking_allocations_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_parking_allocations_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    invitation_id BIGINT UNSIGNED NOT NULL,
    bay_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    plate_number VARCHAR(20) NULL,
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NOT NULL,
    status ENUM('reserved', 'checked_in', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'reserved',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_reservations_uuid (uuid),
    KEY idx_parking_reservations_bay_window (bay_id, starts_at, ends_at, status),
    KEY idx_parking_reservations_invitation (invitation_id),
    KEY idx_parking_reservations_invitation_estate (invitation_id, estate_id),
    KEY idx_parking_reservations_bay_estate (bay_id, estate_id),
    CONSTRAINT fk_parking_reservations_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_parking_reservations_invitation FOREIGN KEY (invitation_id, estate_id) REFERENCES visitor_invitations (id, estate_id),
    CONSTRAINT fk_parking_reservations_bay FOREIGN KEY (bay_id, estate_id) REFERENCES parking_bays (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    bay_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    invitation_id BIGINT UNSIGNED NULL,
    checkin_at DATETIME(3) NOT NULL,
    checkout_at DATETIME(3) NULL,
    status ENUM('active', 'completed', 'overstay', 'void') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_sessions_uuid (uuid),
    KEY idx_parking_sessions_estate_status_bay (estate_id, status, bay_id),
    KEY idx_parking_sessions_invitation (invitation_id, status),
    KEY idx_parking_sessions_invitation_estate (invitation_id, estate_id),
    KEY idx_parking_sessions_bay_estate (bay_id, estate_id),
    KEY idx_parking_sessions_vehicle (vehicle_id),
    CONSTRAINT fk_parking_sessions_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_parking_sessions_bay FOREIGN KEY (bay_id, estate_id) REFERENCES parking_bays (id, estate_id),
    CONSTRAINT fk_parking_sessions_invitation FOREIGN KEY (invitation_id, estate_id) REFERENCES visitor_invitations (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parking_violations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    bay_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    plate_number VARCHAR(20) NULL,
    violation_type ENUM('overstay', 'unauthorised_bay', 'unregistered_vehicle', 'other') NOT NULL,
    status ENUM('open', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    notes VARCHAR(500) NULL,
    reported_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    resolved_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_violations_uuid (uuid),
    KEY idx_parking_violations_estate (estate_id, status, created_at),
    KEY idx_parking_violations_bay_estate (bay_id, estate_id),
    KEY idx_parking_violations_vehicle (vehicle_id),
    CONSTRAINT fk_parking_violations_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_parking_violations_bay FOREIGN KEY (bay_id, estate_id) REFERENCES parking_bays (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
