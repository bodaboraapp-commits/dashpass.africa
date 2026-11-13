<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;

/**
 * Tenant move-out module (owner request 2026-11-13).
 *
 * The Estate Admin removes a tenant from a household and the house becomes
 * vacant again, ready for a new tenant:
 *
 *  - every active household member is deactivated (soft, never hard-deleted);
 *  - resident estate memberships for those people are suspended so they lose
 *    estate access (management roles are never touched);
 *  - active household staff, pending/approved visitor invitations and active
 *    vehicles for that household are deactivated/revoked;
 *  - the household row stays `active` with zero active members (a vacant house),
 *    so the existing "add member" flow can install the next tenant immediately;
 *  - a `household_move_outs` snapshot row preserves the tenant/unit history and
 *    the action is audited.
 */
final class TenantMoveOutService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly AdminAccess $access
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, AdminAccess::make($context));
    }

    /**
     * True when the move-out table exists (the deployment ran migration 000016).
     *
     * The Move-outs tab is reachable on a freshly pulled deployment before the
     * migration runs; it must show a "database update required" notice, not a 500.
     */
    public function schemaReady(): bool
    {
        try {
            return $this->db->selectOne("SHOW TABLES LIKE 'household_move_outs'") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Active households in the estate with their primary tenant, plus a derived
     * "vacant" state when the house has no active members.
     *
     * Uses scalar subqueries (no GROUP BY) so the query behaves identically on
     * MariaDB and production MySQL 8 with ONLY_FULL_GROUP_BY enabled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidates(): array
    {
        $estateId = $this->access->estateId();
        $rows = $this->db->select(
            "SELECT h.id, h.uuid, h.unit_number, h.status,
                    b.name AS building_name,
                    (SELECT COUNT(*) FROM household_members hm
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id AND hm.status = 'active') AS members_count,
                    (SELECT COUNT(*) FROM household_staff hs
                     WHERE hs.household_id = h.id AND hs.estate_id = h.estate_id AND hs.status = 'active') AS staff_count,
                    (SELECT COUNT(*) FROM vehicles v
                     WHERE v.household_id = h.id AND v.estate_id = h.estate_id AND v.status = 'active') AS vehicles_count,
                    (SELECT hm.user_id
                     FROM household_members hm
                     INNER JOIN users u ON u.id = hm.user_id
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id
                       AND hm.status = 'active' AND u.status = 'active'
                     ORDER BY hm.is_primary DESC, hm.id ASC LIMIT 1) AS tenant_user_id,
                    (SELECT u.full_name
                     FROM household_members hm
                     INNER JOIN users u ON u.id = hm.user_id
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id
                       AND hm.status = 'active' AND u.status = 'active'
                     ORDER BY hm.is_primary DESC, hm.id ASC LIMIT 1) AS tenant_name,
                    (SELECT u.phone
                     FROM household_members hm
                     INNER JOIN users u ON u.id = hm.user_id
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id
                       AND hm.status = 'active' AND u.status = 'active'
                     ORDER BY hm.is_primary DESC, hm.id ASC LIMIT 1) AS tenant_phone
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id
               AND h.status = 'active'
             ORDER BY b.name ASC, h.unit_number ASC",
            ['estate_id' => $estateId]
        );

        return array_map(
            function (array $row): array {
                $members = (int) ($row['members_count'] ?? 0);

                return [
                    'uuid' => (string) $row['uuid'],
                    'unit_number' => (string) $row['unit_number'],
                    'building_name' => $row['building_name'] === null ? null : (string) $row['building_name'],
                    'label' => trim(
                        (string) $row['unit_number']
                        . (($row['building_name'] ?? null) !== null && (string) $row['building_name'] !== ''
                            ? ' - ' . (string) $row['building_name']
                            : '')
                    ),
                    'tenant_user_id' => (int) ($row['tenant_user_id'] ?? 0),
                    'tenant_name' => trim((string) ($row['tenant_name'] ?? '')),
                    'tenant_phone' => trim((string) ($row['tenant_phone'] ?? '')),
                    'members_count' => $members,
                    'staff_count' => (int) ($row['staff_count'] ?? 0),
                    'vehicles_count' => (int) ($row['vehicles_count'] ?? 0),
                    'vacant' => $members === 0,
                ];
            },
            $rows
        );
    }

    /**
     * Move-out history for the estate, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 50): array
    {
        // A deployment that pulled this module but did not run the migration must
        // not 500 the Move-outs tab: show the pending-update state instead.
        if (!$this->schemaReady()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows = $this->db->select(
            "SELECT mo.uuid, mo.unit_number_snapshot, mo.building_name_snapshot,
                    mo.tenant_name_snapshot, mo.tenant_phone_snapshot,
                    mo.members_removed, mo.invitations_revoked, mo.vehicles_deactivated,
                    mo.moved_out_at, mo.reason, mo.notes, mo.created_at,
                    actor.full_name AS actor_name,
                    h.uuid AS household_uuid
             FROM household_move_outs mo
             INNER JOIN users actor ON actor.id = mo.moved_out_by
             INNER JOIN households h ON h.id = mo.household_id
             WHERE mo.estate_id = :estate_id
             ORDER BY mo.moved_out_at DESC, mo.id DESC
             LIMIT " . $limit,
            ['estate_id' => $this->access->estateId()]
        );

        return array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'household_uuid' => (string) $row['household_uuid'],
                'unit_number' => (string) $row['unit_number_snapshot'],
                'building_name' => $row['building_name_snapshot'] === null ? null : (string) $row['building_name_snapshot'],
                'tenant_name' => (string) $row['tenant_name_snapshot'],
                'tenant_phone' => $row['tenant_phone_snapshot'] === null ? null : (string) $row['tenant_phone_snapshot'],
                'members_removed' => (int) $row['members_removed'],
                'invitations_revoked' => (int) $row['invitations_revoked'],
                'vehicles_deactivated' => (int) $row['vehicles_deactivated'],
                'moved_out_at' => (string) $row['moved_out_at'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'notes' => $row['notes'] === null ? null : (string) $row['notes'],
                'actor_name' => (string) $row['actor_name'],
            ],
            $rows
        );
    }

    /**
     * Execute the move-out for one active household.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function moveOut(array $input): array
    {
        if (!$this->schemaReady()) {
            throw new HttpException(
                422,
                'MOVE_OUT_SCHEMA_MISSING',
                'Move-outs need the latest database update. Run php scripts/migrate.php and try again.'
            );
        }

        $clean = $this->access->validate($input, [
            'household_uuid' => 'required|uuid',
            'moved_out_at' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'confirm' => 'required|in:1',
        ], [
            'household_uuid' => 'household',
            'moved_out_at' => 'move-out date',
            'reason' => 'reason',
            'notes' => 'notes',
            'confirm' => 'confirmation',
        ]);

        $household = $this->access->findHousehold((string) $clean['household_uuid']);

        if ((string) $household['status'] !== 'active') {
            throw new HttpException(
                422,
                'HOUSEHOLD_NOT_ACTIVE',
                'That unit is not an active household. Nothing to move out.'
            );
        }

        $members = $this->db->select(
            "SELECT hm.id, hm.user_id, hm.is_primary, u.full_name, u.phone
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
             ORDER BY hm.is_primary DESC, hm.id ASC",
            ['household_id' => (int) $household['id'], 'estate_id' => $this->access->estateId()]
        );

        if ($members === []) {
            throw new HttpException(
                422,
                'HOUSEHOLD_ALREADY_VACANT',
                'That house is already vacant. Add the next tenant from the Households screen.'
            );
        }

        $tenant = $members[0];

        $movedOutRaw = trim((string) $clean['moved_out_at']);
        // The date rule normalises to a DB datetime; the module stores a day
        // (the guard also renders a date input).
        $movedOutAt = substr($movedOutRaw, 0, 10);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $movedOutAt) !== 1) {
            throw new ValidationException(['moved_out_at' => ['Enter a valid move-out date.']]);
        }

        if ($movedOutAt > Clock::now()->format('Y-m-d')) {
            throw new ValidationException(['moved_out_at' => ['The move-out date cannot be in the future.']]);
        }

        $reason = isset($clean['reason']) ? trim((string) $clean['reason']) : '';
        $notes = isset($clean['notes']) ? trim((string) $clean['notes']) : '';
        $userIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['user_id'], $members)));

        $buildingName = $this->db->scalar(
            'SELECT b.name FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.id = :id LIMIT 1',
            ['id' => (int) $household['id']]
        );

        $invitationsRevoked = 0;
        $vehiclesDeactivated = 0;
        $loginsDisabled = 0;

        $this->db->transaction(function () use (
            $household,
            $tenant,
            $members,
            $userIds,
            $movedOutAt,
            $reason,
            $notes,
            $buildingName,
            &$invitationsRevoked,
            &$vehiclesDeactivated,
            &$loginsDisabled
        ): void {
            $estateId = $this->access->estateId();
            $now = Clock::nowDb();

            $this->db->execute(
                "UPDATE household_members
                 SET status = 'inactive', is_primary = 0, updated_at = :now
                 WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'active'",
                ['now' => $now, 'household_id' => (int) $household['id'], 'estate_id' => $estateId]
            );

            $this->db->execute(
                "UPDATE household_staff
                 SET status = 'inactive', updated_at = :now
                 WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'active'",
                ['now' => $now, 'household_id' => (int) $household['id'], 'estate_id' => $estateId]
            );

            // Suspend resident estate access, never management/guard roles.
            $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
            $this->db->execute(
                "UPDATE estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 SET em.status = 'suspended', em.updated_at = ?
                 WHERE em.estate_id = ?
                   AND em.user_id IN (" . $placeholders . ")
                   AND em.status = 'active'
                   AND r.name NOT IN ('estate_admin', 'estate_manager', 'guard', 'platform_admin', 'platform_super_admin')",
                array_merge([$now, $estateId], $userIds)
            );

            // Owner request (2026-11-13): moved-out people can no longer log in.
            // Suspend the user account when this was their last active access
            // (no other estate membership, no platform membership), so a future
            // OTP login is rejected rather than landing on an empty shell.
            foreach ($userIds as $movedOutUserId) {
                $remainingEstateAccess = (int) ($this->db->scalar(
                    "SELECT COUNT(*) FROM estate_memberships WHERE user_id = :user_id AND status = 'active'",
                    ['user_id' => $movedOutUserId]
                ) ?? 0);
                $activePlatformAccess = (int) ($this->db->scalar(
                    "SELECT COUNT(*) FROM platform_memberships WHERE user_id = :user_id AND status = 'active'",
                    ['user_id' => $movedOutUserId]
                ) ?? 0);

                if ($remainingEstateAccess > 0 || $activePlatformAccess > 0) {
                    continue;
                }

                $changed = $this->db->execute(
                    "UPDATE users SET status = 'suspended', updated_at = :now WHERE id = :user_id AND status = 'active'",
                    ['now' => $now, 'user_id' => $movedOutUserId]
                );

                if ($changed > 0) {
                    $loginsDisabled++;
                    $this->access->audit('admin.tenant_login_disabled', 'user', $movedOutUserId, [
                        'household_uuid' => (string) $household['uuid'],
                        'estate_id' => $estateId,
                    ]);
                }
            }

            $this->db->execute(
                "UPDATE visitor_invitations
                 SET status = 'revoked', updated_at = :now
                 WHERE household_id = :household_id AND estate_id = :estate_id
                   AND status IN ('pending', 'approved')",
                ['now' => $now, 'household_id' => (int) $household['id'], 'estate_id' => $estateId]
            );
            $invitationsRevoked = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM visitor_invitations
                 WHERE household_id = :household_id AND estate_id = :estate_id
                   AND status = 'revoked' AND updated_at = :now",
                ['household_id' => (int) $household['id'], 'estate_id' => $estateId, 'now' => $now]
            );

            $this->db->execute(
                "UPDATE vehicles
                 SET status = 'inactive', updated_at = :now
                 WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'active'",
                ['now' => $now, 'household_id' => (int) $household['id'], 'estate_id' => $estateId]
            );
            $vehiclesDeactivated = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM vehicles
                 WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'inactive' AND updated_at = :now",
                ['household_id' => (int) $household['id'], 'estate_id' => $estateId, 'now' => $now]
            );

            $moveOutId = $this->db->insert('household_move_outs', [
                'uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'household_id' => (int) $household['id'],
                'tenant_user_id' => (int) $tenant['user_id'],
                'unit_number_snapshot' => (string) $household['unit_number'],
                'building_name_snapshot' => $buildingName === null ? null : (string) $buildingName,
                'tenant_name_snapshot' => (string) $tenant['full_name'],
                'tenant_phone_snapshot' => $tenant['phone'] === null ? null : (string) $tenant['phone'],
                'members_removed' => count($members),
                'invitations_revoked' => $invitationsRevoked,
                'vehicles_deactivated' => $vehiclesDeactivated,
                'moved_out_at' => $movedOutAt . ' 00:00:00',
                'reason' => $reason !== '' ? $reason : null,
                'notes' => $notes !== '' ? $notes : null,
                'moved_out_by' => $this->access->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->access->audit('admin.tenant_moved_out', 'household', (int) $household['id'], [
                'move_out_id' => $moveOutId,
                'household_uuid' => (string) $household['uuid'],
                'tenant_user_id' => (int) $tenant['user_id'],
                'members_removed' => count($members),
                'invitations_revoked' => $invitationsRevoked,
                'vehicles_deactivated' => $vehiclesDeactivated,
                'logins_disabled' => $loginsDisabled,
                'moved_out_at' => $movedOutAt,
            ]);
        });

        return [
            'tenant_name' => (string) $tenant['full_name'],
            'tenant_phone' => $tenant['phone'] === null ? null : (string) $tenant['phone'],
            'unit_number' => (string) $household['unit_number'],
            'building_name' => $buildingName === null ? null : (string) $buildingName,
            'members_removed' => count($members),
            'invitations_revoked' => $invitationsRevoked,
            'vehicles_deactivated' => $vehiclesDeactivated,
            'logins_disabled' => $loginsDisabled,
        ];
    }
}
