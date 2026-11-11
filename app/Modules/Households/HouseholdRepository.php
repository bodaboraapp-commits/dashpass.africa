<?php

declare(strict_types=1);

namespace App\Modules\Households;

use App\Core\Database;

/**
 * Household-scoped read model.
 *
 * All lookups are constrained by both the estate id (resolved server-side) and,
 * for resident views, the authenticated user id.
 */
final class HouseholdRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * Households the given user belongs to inside one estate (contract §8.1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUserInEstate(int $userId, int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT h.uuid AS household_uuid,
                    h.unit_number,
                    h.household_type,
                    b.name AS building_name,
                    hm.relationship,
                    hm.is_primary
             FROM household_members hm
             INNER JOIN households h
                     ON h.id = hm.household_id
                    AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b
                    ON b.id = h.building_id
                   AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
             ORDER BY hm.is_primary DESC, h.unit_number ASC",
            ['user_id' => $userId, 'estate_id' => $estateId]
        );

        return array_map(
            static fn (array $row): array => [
                'household_uuid' => (string) $row['household_uuid'],
                'unit_number' => (string) $row['unit_number'],
                'household_type' => (string) $row['household_type'],
                'building_name' => $row['building_name'] === null ? null : (string) $row['building_name'],
                'relationship' => $row['relationship'] === null ? null : (string) $row['relationship'],
                'is_primary' => (bool) $row['is_primary'],
            ],
            $rows
        );
    }

    /**
     * Owner-side members of a household inside the same estate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function membersOf(int $householdId, int $estateId): array
    {
        return $this->db->select(
            "SELECT hm.uuid, hm.relationship, hm.is_primary, hm.status, hm.created_at,
                    u.uuid AS user_uuid, u.full_name, u.phone
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id
               AND hm.estate_id = :estate_id
             ORDER BY hm.is_primary DESC, hm.id ASC",
            ['household_id' => $householdId, 'estate_id' => $estateId]
        );
    }

    public function countForEstate(int $estateId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM households WHERE estate_id = :estate_id',
            ['estate_id' => $estateId]
        );
    }
}
