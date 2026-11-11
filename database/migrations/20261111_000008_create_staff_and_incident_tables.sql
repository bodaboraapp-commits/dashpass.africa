-- MtaaPass/DashPASS Increment 6 migration 20261111_000008
-- Household staff directory + visitor/security incidents (contract section 1).
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8.
--
-- Increment 6 adds ONLY these two tables: no ALTERs, no changes to existing
-- tables, no permission-seeder changes. Tenant isolation uses composite FKs
-- that include estate_id; the nullable optional links rely on MySQL/MariaDB
-- MATCH SIMPLE semantics (a composite FK is not checked when any child column,
-- here gate_id/household_id/invitation_id, is NULL).

CREATE TABLE household_staff (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(180) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    staff_type ENUM('domestic', 'driver', 'gardener', 'nanny', 'cook', 'security', 'other') NOT NULL DEFAULT 'domestic',
    notes VARCHAR(500) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_household_staff_uuid (uuid),
    UNIQUE KEY uq_household_staff_id_estate (id, estate_id),
    KEY idx_household_staff_estate_status (estate_id, status),
    KEY idx_household_staff_household_status (household_id, status),
    KEY idx_household_staff_household_estate (household_id, estate_id),
    KEY idx_household_staff_user (user_id),
    CONSTRAINT fk_household_staff_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_household_staff_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_household_staff_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    invitation_id BIGINT UNSIGNED NULL,
    reported_by_user_id BIGINT UNSIGNED NOT NULL,
    incident_type ENUM('unauthorised_access', 'tailgating', 'lost_pass', 'suspicious_activity', 'property_damage', 'medical', 'other') NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'investigating', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    summary VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    resolved_at DATETIME(3) NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolution_notes VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_incidents_uuid (uuid),
    UNIQUE KEY uq_access_incidents_id_estate (id, estate_id),
    KEY idx_access_incidents_estate_status_created (estate_id, status, created_at),
    KEY idx_access_incidents_estate_severity (estate_id, severity),
    KEY idx_access_incidents_reporter (reported_by_user_id, created_at),
    KEY idx_access_incidents_gate_created (gate_id, created_at),
    KEY idx_access_incidents_gate_estate (gate_id, estate_id),
    KEY idx_access_incidents_household_estate (household_id, estate_id),
    KEY idx_access_incidents_invitation_estate (invitation_id, estate_id),
    KEY idx_access_incidents_resolved_by (resolved_by_user_id),
    CONSTRAINT fk_access_incidents_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_access_incidents_gate FOREIGN KEY (gate_id, estate_id) REFERENCES gates (id, estate_id),
    CONSTRAINT fk_access_incidents_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_access_incidents_invitation FOREIGN KEY (invitation_id, estate_id) REFERENCES visitor_invitations (id, estate_id),
    CONSTRAINT fk_access_incidents_reporter FOREIGN KEY (reported_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_access_incidents_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
