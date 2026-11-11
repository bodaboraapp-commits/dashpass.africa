-- MtaaPass Increment 2 migration 20261111_000006
-- Visitor & Access module: estate-scoped visitors, invitations, PIN/QR access
-- credentials, gate access events, walk-in requests and verification attempts.
-- Engine: InnoDB. Charset: utf8mb4 / utf8mb4_unicode_ci.
-- Must stay compatible with MariaDB 10.4 and MySQL 8.
--
-- Tenant isolation: child rows carry estate_id and are tied to their parent
-- through composite foreign keys including estate_id, so an invitation can
-- never reference a household/gate from another estate and an access credential
-- can never belong to another estate's invitation.

CREATE TABLE visitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    photo_path VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_visitors_uuid (uuid),
    UNIQUE KEY uq_visitors_id_estate (id, estate_id),
    KEY idx_visitors_estate_phone (estate_id, phone),
    CONSTRAINT fk_visitors_estate FOREIGN KEY (estate_id) REFERENCES estates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visitor_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    host_user_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(120) NULL,
    visitor_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    valid_from DATETIME(3) NOT NULL,
    valid_until DATETIME(3) NOT NULL,
    status ENUM('pending', 'approved', 'checked_in', 'checked_out', 'revoked', 'expired', 'denied') NOT NULL DEFAULT 'approved',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_visitor_invitations_uuid (uuid),
    UNIQUE KEY uq_visitor_invitations_id_estate (id, estate_id),
    KEY idx_visitor_invitations_estate_window (estate_id, status, valid_from, valid_until),
    KEY idx_visitor_invitations_host (host_user_id, created_at),
    KEY idx_visitor_invitations_household (household_id, created_at),
    KEY idx_visitor_invitations_household_estate (household_id, estate_id),
    KEY idx_visitor_invitations_visitor (visitor_id),
    CONSTRAINT fk_visitor_invitations_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_visitor_invitations_host FOREIGN KEY (host_user_id) REFERENCES users (id),
    CONSTRAINT fk_visitor_invitations_visitor FOREIGN KEY (visitor_id) REFERENCES visitors (id),
    CONSTRAINT fk_visitor_invitations_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    invitation_id BIGINT UNSIGNED NOT NULL,
    credential_hash CHAR(64) NOT NULL,
    credential_type ENUM('pin', 'qr') NOT NULL,
    encrypted_secret TEXT NULL,
    expires_at DATETIME(3) NOT NULL,
    max_uses SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'used', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    revoked_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_credentials_uuid (uuid),
    UNIQUE KEY uq_access_credentials_hash (credential_hash),
    KEY idx_access_credentials_invitation (invitation_id, status, credential_type),
    KEY idx_access_credentials_invitation_estate (invitation_id, estate_id),
    CONSTRAINT fk_access_credentials_invitation FOREIGN KEY (invitation_id, estate_id) REFERENCES visitor_invitations (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visitor_access_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    event_uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    invitation_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('check_in', 'check_out', 'denied', 'manual_override') NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    source ENUM('online', 'offline_sync') NOT NULL DEFAULT 'online',
    metadata JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_visitor_access_events_uuid (uuid),
    UNIQUE KEY uq_visitor_access_events_event_uuid (event_uuid),
    KEY idx_visitor_access_events_estate (estate_id, occurred_at),
    KEY idx_visitor_access_events_invitation (invitation_id, occurred_at),
    KEY idx_visitor_access_events_invitation_estate (invitation_id, estate_id),
    KEY idx_visitor_access_events_gate_estate (gate_id, estate_id),
    KEY idx_visitor_access_events_actor (actor_id),
    CONSTRAINT fk_visitor_access_events_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_visitor_access_events_actor FOREIGN KEY (actor_id) REFERENCES users (id),
    CONSTRAINT fk_visitor_access_events_invitation FOREIGN KEY (invitation_id, estate_id) REFERENCES visitor_invitations (id, estate_id),
    CONSTRAINT fk_visitor_access_events_gate FOREIGN KEY (gate_id, estate_id) REFERENCES gates (id, estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE walk_in_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    visitor_name VARCHAR(180) NOT NULL,
    visitor_phone VARCHAR(20) NOT NULL,
    purpose VARCHAR(120) NULL,
    status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    invitation_id BIGINT UNSIGNED NULL,
    responded_by_user_id BIGINT UNSIGNED NULL,
    responded_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_walk_in_requests_uuid (uuid),
    KEY idx_walk_in_requests_estate (estate_id, status, created_at),
    KEY idx_walk_in_requests_household (household_id, status),
    KEY idx_walk_in_requests_household_estate (household_id, estate_id),
    KEY idx_walk_in_requests_gate_estate (gate_id, estate_id),
    KEY idx_walk_in_requests_invitation (invitation_id),
    CONSTRAINT fk_walk_in_requests_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_walk_in_requests_household FOREIGN KEY (household_id, estate_id) REFERENCES households (id, estate_id),
    CONSTRAINT fk_walk_in_requests_gate FOREIGN KEY (gate_id, estate_id) REFERENCES gates (id, estate_id),
    CONSTRAINT fk_walk_in_requests_invitation FOREIGN KEY (invitation_id) REFERENCES visitor_invitations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_verification_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estate_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NULL,
    credential_type ENUM('pin', 'qr') NOT NULL,
    credential_hash CHAR(64) NULL,
    success TINYINT(1) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_access_verification_attempts_estate (estate_id, created_at),
    KEY idx_access_verification_attempts_gate (gate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
