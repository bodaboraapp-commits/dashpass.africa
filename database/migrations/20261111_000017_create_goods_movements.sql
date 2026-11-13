-- MtaaPass migration 20261111_000017
-- Goods in/out register (owner request 2026-11-13).
--
-- Flow: the tenant (resident) initiates a goods check-in or check-out request for
-- their household; the Estate Admin approves or declines it; the guard verifies
-- the approved movement at the selected gate (which completes it). Every step is
-- audited and the resident is notified in-app of the decision and the gate result.
--
-- `reference_code` is the short human code shown to the guard (per estate unique).

CREATE TABLE goods_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    resident_user_id BIGINT UNSIGNED NOT NULL,
    reference_code VARCHAR(24) NOT NULL,
    direction ENUM('in', 'out') NOT NULL,
    description VARCHAR(300) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    carrier_name VARCHAR(120) NULL,
    vehicle_plate VARCHAR(20) NULL,
    recipient_name VARCHAR(180) NULL,
    recipient_phone VARCHAR(20) NULL,
    expected_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    status ENUM('pending', 'approved', 'declined', 'completed') NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(500) NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME(3) NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME(3) NULL,
    gate_id BIGINT UNSIGNED NULL,
    verification_note VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_goods_movements_uuid (uuid),
    UNIQUE KEY uq_goods_movements_reference (estate_id, reference_code),
    KEY idx_goods_movements_estate_status (estate_id, status, created_at),
    KEY idx_goods_movements_household (household_id, created_at),
    CONSTRAINT fk_goods_movements_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_goods_movements_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_goods_movements_resident FOREIGN KEY (resident_user_id) REFERENCES users (id),
    CONSTRAINT fk_goods_movements_decided_by FOREIGN KEY (decided_by) REFERENCES users (id),
    CONSTRAINT fk_goods_movements_verified_by FOREIGN KEY (verified_by) REFERENCES users (id),
    CONSTRAINT fk_goods_movements_gate FOREIGN KEY (gate_id) REFERENCES gates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
