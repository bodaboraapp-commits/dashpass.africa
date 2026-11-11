<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;

/**
 * Household staff directory persistence (Increment 6 §1/§3).
 *
 * Every query is estate-scoped; resident-facing queries additionally join the
 * caller's active household membership so a foreign household simply resolves to
 * nothing (services then return a generic 404). Staff rows are never deleted:
 * `status` active/inactive only.
 */
final class StaffRepository
{
    private const SELECT = 'SELECT s.id, s.uuid, s.estate_id, s.household_id, s.user_id,
                s.full_name, s.phone, s.staff_type, s.notes, s.status, s.created_at, s.updated_at,
                h.uuid AS household_uuid, h.unit_number, h.status AS household_status,
                b.name AS building_name
         FROM household_staff s
         INNER JOIN households h ON h.id = s.household_id AND h.estate_id = s.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $estateId): array
    {
        return array_map([self::class, 'shape'], $this->db->select(
            self::SELECT . "
             WHERE s.estate_id = :estate_id
               AND EXISTS (
                   SELECT 1 FROM household_members hm
                   WHERE hm.household_id = s.household_id
                     AND hm.estate_id = s.estate_id
                     AND hm.user_id = :user_id
                     AND hm.status = 'active'
               )
             ORDER BY s.status ASC, s.full_name ASC",
            ['estate_id' => $estateId, 'user_id' => $userId]
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forEstate(int $estateId): array
    {
        return array_map([self::class, 'shape'], $this->db->select(
            self::SELECT . '
             WHERE s.estate_id = :estate_id
             ORDER BY h.unit_number ASC, s.status ASC, s.full_name ASC',
            ['estate_id' => $estateId]
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(string $uuid, int $userId, int $estateId): ?array
    {
        $row = $this->db->selectOne(
            self::SELECT . "
             WHERE s.estate_id = :estate_id
               AND s.uuid = :uuid
               AND EXISTS (
                   SELECT 1 FROM household_members hm
                   WHERE hm.household_id = s.household_id
                     AND hm.estate_id = s.estate_id
                     AND hm.user_id = :user_id
                     AND hm.status = 'active'
               )
             LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid, 'user_id' => $userId]
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForEstate(string $uuid, int $estateId): ?array
    {
        $row = $this->db->selectOne(
            self::SELECT . ' WHERE s.estate_id = :estate_id AND s.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @return array<string, mixed>|null Active staff duplicate by (household, phone).
     */
    public function findActiveByPhone(int $estateId, int $householdId, string $phone, ?int $excludeId = null): ?array
    {
        $params = [
            'estate_id' => $estateId,
            'household_id' => $householdId,
            'phone' => $phone,
        ];

        $sql = "SELECT id, uuid FROM household_staff
                WHERE estate_id = :estate_id
                  AND household_id = :household_id
                  AND phone = :phone
                  AND status = 'active'";

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return $this->db->selectOne($sql . ' LIMIT 1', $params);
    }

    /**
     * Household rows the user belongs to in the active estate (active only).
     *
     * @return array<int, array<string, mixed>>
     */
    public function householdsForUser(int $userId, int $estateId): array
    {
        return $this->db->select(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.status = 'active'
             ORDER BY hm.is_primary DESC, h.unit_number ASC",
            ['user_id' => $userId, 'estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserHousehold(string $uuid, int $userId, int $estateId): ?array
    {
        return $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.uuid = :uuid
             LIMIT 1",
            ['user_id' => $userId, 'estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Active estate households (admin selector).
     */
    public function estateHouseholds(int $estateId): array
    {
        return $this->db->select(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.status = 'active'
             ORDER BY h.unit_number ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(array $fields): int
    {
        return $this->db->insert('household_staff', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $this->db->update('household_staff', $fields, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return $row;
    }
}
