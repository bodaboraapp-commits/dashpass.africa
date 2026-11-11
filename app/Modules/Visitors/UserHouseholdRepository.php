<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Database;

/**
 * Household lookups scoped to the authenticated resident and active estate.
 *
 * A household uuid supplied by a browser is only ever resolved through a
 * membership check, so foreign or invented uuids are indistinguishable from
 * missing ones (contract §4.1: 404 HOUSEHOLD_NOT_FOUND, no enumeration).
 */
final class UserHouseholdRepository
{
    /**
     * Canonical household option shape for the invite form (§7.1).
     */
    private const COLUMNS = 'h.id, h.uuid, h.unit_number, h.household_type, h.building_id, h.status,
        b.name AS building_name, hm.relationship, hm.is_primary';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Households the user belongs to in one estate (primary first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUserInEstate(int $userId, int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . '
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = :status
               AND h.status = :household_status
             ORDER BY hm.is_primary DESC, h.unit_number ASC',
            [
                'user_id' => $userId,
                'estate_id' => $estateId,
                'status' => 'active',
                'household_status' => 'active',
            ]
        );

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * All active-estate households (guard walk-in creation).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForEstate(int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT h.id, h.uuid, h.unit_number, h.household_type, h.building_id, h.status,
                    b.name AS building_name, NULL AS relationship, 0 AS is_primary
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id
               AND h.status = 'active'
             ORDER BY h.unit_number ASC",
            ['estate_id' => $estateId]
        );

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * Resolve a household uuid for a specific user membership.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, string $householdUuid, int $estateId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . '
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = :status
               AND h.uuid = :uuid
             LIMIT 1',
            ['user_id' => $userId, 'estate_id' => $estateId, 'status' => 'active', 'uuid' => $householdUuid]
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Resolve a household uuid inside the estate (guard scope, no membership).
     *
     * @return array<string, mixed>|null
     */
    public function findInEstate(int $estateId, string $householdUuid): ?array
    {
        $row = $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.household_type, h.building_id, h.status,
                    b.name AS building_name, NULL AS relationship, 0 AS is_primary
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id
               AND h.uuid = :uuid
             LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $householdUuid]
        );

        return $row === null ? null : self::shape($row);
    }

    public function isMember(int $userId, int $householdId, int $estateId): bool
    {
        return $this->db->selectOne(
            "SELECT 1 AS member
             FROM household_members
             WHERE user_id = :user_id
               AND household_id = :household_id
               AND estate_id = :estate_id
               AND status = 'active'
             LIMIT 1",
            ['user_id' => $userId, 'household_id' => $householdId, 'estate_id' => $estateId]
        ) !== null;
    }

    /**
     * Household ids for the user, used to scope list queries.
     *
     * @return array<int, int>
     */
    public function idsForUser(int $userId, int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT household_id FROM household_members
             WHERE user_id = :user_id AND estate_id = :estate_id AND status = 'active'",
            ['user_id' => $userId, 'estate_id' => $estateId]
        );

        return array_map(static fn (array $row): int => (int) $row['household_id'], $rows);
    }

    /**
     * Active members of a household (walk-in approval notifications).
     *
     * @return array<int, array{user_id: int, full_name: string, phone: string}>
     */
    public function membersWithPhones(int $householdId, int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT u.id AS user_id, u.full_name, u.phone
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND u.status = 'active'
             ORDER BY hm.is_primary DESC, hm.id ASC",
            ['household_id' => $householdId, 'estate_id' => $estateId]
        );

        return array_map(
            static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'full_name' => (string) $row['full_name'],
                'phone' => (string) $row['phone'],
            ],
            $rows
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $unit = (string) ($row['unit_number'] ?? '');
        $building = isset($row['building_name']) ? (string) $row['building_name'] : '';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => (string) ($row['uuid'] ?? ''),
            'unit_number' => $unit,
            'household_type' => (string) ($row['household_type'] ?? 'apartment'),
            'status' => (string) ($row['status'] ?? 'active'),
            'building_name' => $building === '' ? null : $building,
            'relationship' => $row['relationship'] === null ? null : (string) $row['relationship'],
            'is_primary' => (bool) ($row['is_primary'] ?? false),
            'label' => $building === '' ? $unit : $unit . ' · ' . $building,
        ];
    }
}
