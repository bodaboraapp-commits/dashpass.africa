<?php

declare(strict_types=1);

namespace App\Modules\Vehicles;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Uuid;

/**
 * Estate-scoped vehicle queries.
 *
 * Every lookup is constrained by the resolved active estate and, for resident
 * callers, by the caller's household ids, so a vehicle uuid from another
 * household/estate is indistinguishable from a missing one (404).
 */
final class VehicleRepository
{
    private const SELECT = 'SELECT v.id, v.uuid, v.estate_id, v.household_id, v.plate_number,
                v.vehicle_type, v.colour, v.status, v.created_at, v.updated_at,
                h.uuid AS household_uuid, h.unit_number,
                b.name AS building_name
         FROM vehicles v
         INNER JOIN households h ON h.id = v.household_id AND h.estate_id = v.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id';

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * @param array<int, int> $householdIds
     * @return array<int, array<string, mixed>>
     */
    public function forHouseholds(int $estateId, array $householdIds): array
    {
        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $params = array_merge([$estateId], array_values($householdIds));

        return $this->db->select(
            self::SELECT . '
             WHERE v.estate_id = ?
               AND v.household_id IN (' . $placeholders . ')
             ORDER BY v.plate_number ASC',
            $params
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forEstate(int $estateId): array
    {
        return $this->db->select(
            self::SELECT . ' WHERE v.estate_id = :estate_id ORDER BY v.plate_number ASC',
            ['estate_id' => $estateId]
        );
    }

    /**
     * Active vehicles for one household (invite form options).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForHouseholds(int $estateId, array $householdIds): array
    {
        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $params = array_merge([$estateId], array_values($householdIds));

        return $this->db->select(
            self::SELECT . "
             WHERE v.estate_id = ?
               AND v.household_id IN (" . $placeholders . ")
               AND v.status = 'active'
             ORDER BY v.plate_number ASC",
            $params
        );
    }

    /**
     * @param array<int, int> $householdIds
     * @return array<string, mixed>|null
     */
    public function findForHouseholds(int $estateId, array $householdIds, string $uuid): ?array
    {
        if ($householdIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $params = array_merge([$estateId, $uuid], array_values($householdIds));

        return $this->db->selectOne(
            self::SELECT . '
             WHERE v.estate_id = ?
               AND v.uuid = ?
               AND v.household_id IN (' . $placeholders . ')
             LIMIT 1',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInEstate(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE v.estate_id = :estate_id AND v.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * Find an active vehicle that belongs to a specific household (invite flow).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForHousehold(int $estateId, int $householdId, string $uuid): ?array
    {
        return $this->db->selectOne(
            self::SELECT . "
             WHERE v.estate_id = :estate_id
               AND v.household_id = :household_id
               AND v.uuid = :uuid
               AND v.status = 'active'
             LIMIT 1",
            ['estate_id' => $estateId, 'household_id' => $householdId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPlate(int $estateId, string $plate): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE v.estate_id = :estate_id AND v.plate_number = :plate LIMIT 1',
            ['estate_id' => $estateId, 'plate' => $plate]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->insert('vehicles', $data);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->db->update('vehicles', ['status' => $status, 'updated_at' => Clock::nowDb()], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateFields(int $id, array $fields): void
    {
        $fields['updated_at'] = Clock::nowDb();
        $this->db->update('vehicles', $fields, ['id' => $id]);
    }

    public function uuid(): string
    {
        return Uuid::v4();
    }
}
