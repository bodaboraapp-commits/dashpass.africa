-- MtaaPass migration 20261111_000016
-- Tenant move-out ledger (owner request 2026-11-13).
--
-- The Estate Admin move-out module removes a tenant/household member, revokes
-- their estate access (membership suspended), deactivates the household staff,
-- active visitor invitations and vehicles, and marks the house vacant (no active
-- household members). This table keeps an audit-friendly historical record with
-- snapshots so the unit history survives later re-occupation.
--
-- Snapshot columns are deliberately denormalised (tenant name/phone, unit and
-- building at the time of the move-out) so later edits/deactivations never
-- rewrite history.

CREATE TABLE household_move_outs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    estate_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    tenant_user_id BIGINT UNSIGNED NOT NULL,
    unit_number_snapshot VARCHAR(60) NOT NULL,
    building_name_snapshot VARCHAR(120) NULL,
    tenant_name_snapshot VARCHAR(180) NOT NULL,
    tenant_phone_snapshot VARCHAR(20) NULL,
    members_removed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    invitations_revoked SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    vehicles_deactivated SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    moved_out_at DATETIME NOT NULL,
    reason VARCHAR(500) NULL,
    notes TEXT NULL,
    moved_out_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_household_move_outs_uuid (uuid),
    KEY idx_household_move_outs_estate_date (estate_id, moved_out_at),
    KEY idx_household_move_outs_household (household_id),
    CONSTRAINT fk_household_move_outs_estate FOREIGN KEY (estate_id) REFERENCES estates (id),
    CONSTRAINT fk_household_move_outs_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_household_move_outs_tenant FOREIGN KEY (tenant_user_id) REFERENCES users (id),
    CONSTRAINT fk_household_move_outs_actor FOREIGN KEY (moved_out_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
