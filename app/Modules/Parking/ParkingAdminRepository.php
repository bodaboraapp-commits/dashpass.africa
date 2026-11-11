<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Database;

/**
 * Parking administration persistence (Increment 8 §2).
 *
 * Every query is estate-scoped. Lists join the related zone/household/vehicle/bay
 * data needed by the frozen T61 view shapes. Nothing is hard-deleted: bays move to
 * `maintenance`, allocations to `ended`, violations to resolved/dismissed.
 */
final class ParkingAdminRepository
{
    private const ZONE_SELECT = 'SELECT z.id, z.uuid, z.name, z.zone_type, z.created_at,
                (SELECT COUNT(*) FROM parking_bays b WHERE b.zone_id = z.id AND b.estate_id = z.estate_id) AS bay_count
         FROM parking_zones z';

    private const BAY_SELECT = 'SELECT b.id, b.uuid, b.zone_id, b.bay_code, b.bay_type, b.status, b.created_at, b.updated_at,
                z.uuid AS zone_uuid, z.name AS zone_name,
                (SELECT h.unit_number FROM parking_allocations a
                 INNER JOIN households h ON h.id = a.household_id AND h.estate_id = a.estate_id
                 WHERE a.bay_id = b.id AND a.estate_id = b.estate_id AND a.status = \'active\'
                 LIMIT 1) AS allocated_unit_number,
                (SELECT bd.name FROM parking_allocations a
                 INNER JOIN households h ON h.id = a.household_id AND h.estate_id = a.estate_id
                 LEFT JOIN buildings bd ON bd.id = h.building_id AND bd.estate_id = h.estate_id
                 WHERE a.bay_id = b.id AND a.estate_id = b.estate_id AND a.status = \'active\'
                 LIMIT 1) AS allocated_building_name
         FROM parking_bays b
         INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id';

    private const ALLOCATION_SELECT = 'SELECT a.id, a.uuid, a.bay_id, a.household_id, a.vehicle_id,
                a.starts_at, a.ends_at, a.status, a.created_at, a.updated_at,
                b.uuid AS bay_uuid, b.bay_code,
                h.uuid AS household_uuid, h.unit_number, bd.name AS building_name,
                v.uuid AS vehicle_uuid, v.plate_number
         FROM parking_allocations a
         INNER JOIN parking_bays b ON b.id = a.bay_id AND b.estate_id = a.estate_id
         INNER JOIN households h ON h.id = a.household_id AND h.estate_id = a.estate_id
         LEFT JOIN buildings bd ON bd.id = h.building_id AND bd.estate_id = h.estate_id
         LEFT JOIN vehicles v ON v.id = a.vehicle_id AND v.estate_id = a.estate_id';

    private const VIOLATION_SELECT = 'SELECT v.id, v.uuid, v.bay_id, v.vehicle_id, v.plate_number,
                v.violation_type, v.status, v.notes, v.reported_by_user_id, v.created_at, v.resolved_at,
                b.uuid AS bay_uuid, b.bay_code,
                rep.full_name AS reported_by_name
         FROM parking_violations v
         INNER JOIN parking_bays b ON b.id = v.bay_id AND b.estate_id = v.estate_id
         LEFT JOIN users rep ON rep.id = v.reported_by_user_id';

    public function __construct(private readonly Database $db)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Zones                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function zones(int $estateId): array
    {
        return $this->db->select(
            self::ZONE_SELECT . ' WHERE z.estate_id = :estate_id ORDER BY z.name ASC',
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findZone(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::ZONE_SELECT . ' WHERE z.estate_id = :estate_id AND z.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findZoneByName(string $name, int $estateId, ?int $excludeId = null): ?array
    {
        $params = ['estate_id' => $estateId, 'name' => $name];
        $sql = 'SELECT id, uuid FROM parking_zones WHERE estate_id = :estate_id AND name = :name';

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->db->selectOne($sql . ' LIMIT 1', $params);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertZone(array $fields): int
    {
        return $this->db->insert('parking_zones', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateZone(int $id, array $fields): void
    {
        $this->db->update('parking_zones', $fields, ['id' => $id]);
    }

    /* ------------------------------------------------------------------ */
    /* Bays                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bays(int $estateId): array
    {
        return $this->db->select(
            self::BAY_SELECT . ' WHERE b.estate_id = :estate_id ORDER BY z.name ASC, b.bay_code ASC',
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBay(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::BAY_SELECT . ' WHERE b.estate_id = :estate_id AND b.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBayByCode(string $code, int $estateId, ?int $excludeId = null): ?array
    {
        $params = ['estate_id' => $estateId, 'code' => $code];
        $sql = 'SELECT id, uuid FROM parking_bays WHERE estate_id = :estate_id AND bay_code = :code';

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->db->selectOne($sql . ' LIMIT 1', $params);
    }

    /**
     * Row lock on the bay (serialises allocation create vs archive for one bay).
     */
    public function lockBay(int $estateId, int $bayId): void
    {
        $this->db->selectOne(
            'SELECT id FROM parking_bays WHERE id = :id AND estate_id = :estate_id FOR UPDATE',
            ['id' => $bayId, 'estate_id' => $estateId]
        );
    }

    /**
     * Row lock on the household (serialises conflicting active allocations).
     */
    public function lockHousehold(int $estateId, int $householdId): void
    {
        $this->db->selectOne(
            'SELECT id FROM households WHERE id = :id AND estate_id = :estate_id FOR UPDATE',
            ['id' => $householdId, 'estate_id' => $estateId]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertBay(array $fields): int
    {
        return $this->db->insert('parking_bays', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateBay(int $id, array $fields): void
    {
        $this->db->update('parking_bays', $fields, ['id' => $id]);
    }

    /**
     * Active allocation, non-terminal reservation or active session on a bay.
     */
    public function bayInUse(int $estateId, int $bayId): bool
    {
        $checks = [
            "SELECT 1 AS x FROM parking_allocations WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active' LIMIT 1",
            "SELECT 1 AS x FROM parking_reservations WHERE estate_id = :estate_id AND bay_id = :bay_id AND status IN ('reserved', 'checked_in') LIMIT 1",
            "SELECT 1 AS x FROM parking_sessions WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active' LIMIT 1",
        ];

        foreach ($checks as $sql) {
            if ($this->db->selectOne($sql, ['estate_id' => $estateId, 'bay_id' => $bayId]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>> Bays selectable for allocation (not maintenance).
     */
    public function allocatableBays(int $estateId): array
    {
        return $this->db->select(
            "SELECT b.id, b.uuid, b.bay_code, b.bay_type, b.status, z.name AS zone_name
             FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
             WHERE b.estate_id = :estate_id AND b.status <> 'maintenance'
             ORDER BY z.name ASC, b.bay_code ASC",
            ['estate_id' => $estateId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Allocations                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allocations(int $estateId): array
    {
        return $this->db->select(
            self::ALLOCATION_SELECT . ' WHERE a.estate_id = :estate_id
             ORDER BY a.status ASC, a.starts_at DESC, a.id DESC',
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAllocation(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::ALLOCATION_SELECT . ' WHERE a.estate_id = :estate_id AND a.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeAllocationForBay(int $estateId, int $bayId, bool $forUpdate = false): ?array
    {
        return $this->db->selectOne(
            "SELECT id, uuid FROM parking_allocations
             WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active' LIMIT 1"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['estate_id' => $estateId, 'bay_id' => $bayId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeAllocationForHousehold(int $estateId, int $householdId, bool $forUpdate = false): ?array
    {
        return $this->db->selectOne(
            "SELECT id, uuid FROM parking_allocations
             WHERE estate_id = :estate_id AND household_id = :household_id AND status = 'active' LIMIT 1"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['estate_id' => $estateId, 'household_id' => $householdId]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertAllocation(array $fields): int
    {
        return $this->db->insert('parking_allocations', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateAllocation(int $id, array $fields): void
    {
        $this->db->update('parking_allocations', $fields, ['id' => $id]);
    }

    /* ------------------------------------------------------------------ */
    /* Violations                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function violations(int $estateId, ?string $status, ?string $type): array
    {
        $filter = '';
        $params = ['estate_id' => $estateId];

        if ($status !== null) {
            $filter .= ' AND v.status = :status';
            $params['status'] = $status;
        }

        if ($type !== null) {
            $filter .= ' AND v.violation_type = :violation_type';
            $params['violation_type'] = $type;
        }

        return $this->db->select(
            self::VIOLATION_SELECT . ' WHERE v.estate_id = :estate_id' . $filter
            . ' ORDER BY v.created_at DESC, v.id DESC',
            $params
        );
    }

    /**
     * @return array<int, array<string, mixed>> Violations reported by one user (guard scope).
     */
    public function violationsForReporter(int $estateId, int $userId): array
    {
        return $this->db->select(
            self::VIOLATION_SELECT . ' WHERE v.estate_id = :estate_id AND v.reported_by_user_id = :user_id
             ORDER BY v.created_at DESC, v.id DESC',
            ['estate_id' => $estateId, 'user_id' => $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findViolation(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::VIOLATION_SELECT . ' WHERE v.estate_id = :estate_id AND v.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertViolation(array $fields): int
    {
        return $this->db->insert('parking_violations', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateViolation(int $id, array $fields): void
    {
        $this->db->update('parking_violations', $fields, ['id' => $id]);
    }

    /**
     * Close an open violation atomically (terminal-state race guard).
     *
     * @param array{status: string, notes: ?string, resolved_at: string} $fields
     * @return int affected rows (0 when another request already closed it)
     */
    public function closeOpenViolation(int $id, array $fields): int
    {
        return $this->db->execute(
            'UPDATE parking_violations
             SET status = :status, notes = :notes, resolved_at = :resolved_at
             WHERE id = :id AND status = :open_status',
            [
                'status' => $fields['status'],
                'notes' => $fields['notes'],
                'resolved_at' => $fields['resolved_at'],
                'id' => $id,
                'open_status' => 'open',
            ]
        );
    }

    /**
     * @return array<string, int>
     */
    public function violationCounts(int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS total FROM parking_violations
             WHERE estate_id = :estate_id GROUP BY status',
            ['estate_id' => $estateId]
        );

        $counts = ['open' => 0, 'resolved' => 0, 'dismissed' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }

    /* ------------------------------------------------------------------ */
    /* Shared lookups / stats / options                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null Estate household (active only).
     */
    public function findHousehold(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.status, h.household_type, bd.name AS building_name
             FROM households h
             LEFT JOIN buildings bd ON bd.id = h.building_id AND bd.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.uuid = :uuid LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null Estate vehicle (active only).
     */
    public function findVehicle(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            "SELECT v.id, v.uuid, v.plate_number, v.household_id FROM vehicles v
             WHERE v.estate_id = :estate_id AND v.uuid = :uuid AND v.status = 'active' LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * True when the household has at least one active resident/tenant member.
     */
    public function householdHasResident(int $estateId, int $householdId): bool
    {
        return $this->db->selectOne(
            "SELECT 1 AS x
             FROM household_members hm
             INNER JOIN estate_memberships em ON em.user_id = hm.user_id AND em.estate_id = hm.estate_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE hm.estate_id = :estate_id AND hm.household_id = :household_id
               AND hm.status = 'active' AND em.status = 'active'
               AND r.name IN ('resident', 'tenant')
             LIMIT 1",
            ['estate_id' => $estateId, 'household_id' => $householdId]
        ) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function householdOptions(int $estateId): array
    {
        return $this->db->select(
            "SELECT h.uuid, h.unit_number, h.household_type, bd.name AS building_name
             FROM households h
             LEFT JOIN buildings bd ON bd.id = h.building_id AND bd.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.status = 'active'
             ORDER BY h.unit_number ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vehicleOptions(int $estateId): array
    {
        return $this->db->select(
            "SELECT v.uuid, v.plate_number, h.uuid AS household_uuid, h.unit_number, bd.name AS building_name
             FROM vehicles v
             INNER JOIN households h ON h.id = v.household_id AND h.estate_id = v.estate_id
             LEFT JOIN buildings bd ON bd.id = h.building_id AND bd.estate_id = h.estate_id
             WHERE v.estate_id = :estate_id AND v.status = 'active'
             ORDER BY v.plate_number ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bayOptions(int $estateId): array
    {
        return $this->db->select(
            "SELECT b.uuid, b.bay_code, b.bay_type, b.status, z.name AS zone_name
             FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
             WHERE b.estate_id = :estate_id
             ORDER BY z.name ASC, b.bay_code ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, int>
     */
    public function stats(int $estateId): array
    {
        return [
            'zones' => (int) ($this->db->scalar('SELECT COUNT(*) FROM parking_zones WHERE estate_id = :estate_id', ['estate_id' => $estateId]) ?? 0),
            'bays' => (int) ($this->db->scalar('SELECT COUNT(*) FROM parking_bays WHERE estate_id = :estate_id', ['estate_id' => $estateId]) ?? 0),
            'available_bays' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM parking_bays WHERE estate_id = :estate_id AND status = 'available'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'maintenance_bays' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM parking_bays WHERE estate_id = :estate_id AND status = 'maintenance'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'active_allocations' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM parking_allocations WHERE estate_id = :estate_id AND status = 'active'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'open_violations' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM parking_violations WHERE estate_id = :estate_id AND status = 'open'",
                ['estate_id' => $estateId]
            ) ?? 0),
        ];
    }
}
