<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;

/**
 * Estate building management (Increment 4 contract §2).
 *
 * Buildings are create/edit only: the `buildings` table has no status column and
 * the contract forbids schema changes, so there is no archive action (documented
 * limitation). Names are unique per estate (422 BUILDING_EXISTS). Foreign-estate
 * uuids are 404.
 */
final class BuildingAdminService
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
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->db->select(
            'SELECT b.id, b.uuid, b.name, b.building_type, b.created_at,
                    (SELECT COUNT(*) FROM households h WHERE h.building_id = b.id AND h.estate_id = b.estate_id) AS household_count
             FROM buildings b
             WHERE b.estate_id = :estate_id
             ORDER BY b.name ASC',
            ['estate_id' => $this->access->estateId()]
        );

        return array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'name' => (string) $row['name'],
                'building_type' => (string) $row['building_type'],
                'household_count' => (int) $row['household_count'],
                'created_at' => Clock::fromDb((string) $row['created_at'])?->format('Y-m-d\TH:i:s.v\Z'),
            ],
            $rows
        );
    }

    /**
     * @return array{building: array<string, mixed>, households: array<int, array<string, mixed>>}
     */
    public function show(string $buildingUuid): array
    {
        $building = $this->access->findBuilding($buildingUuid);

        $households = $this->db->select(
            'SELECT h.uuid, h.unit_number, h.household_type, h.status,
                    (SELECT COUNT(*) FROM household_members hm
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id AND hm.status = \'active\') AS member_count
             FROM households h
             WHERE h.building_id = :building_id AND h.estate_id = :estate_id
             ORDER BY h.unit_number ASC',
            ['building_id' => (int) $building['id'], 'estate_id' => $this->access->estateId()]
        );

        return [
            'building' => [
                'uuid' => (string) $building['uuid'],
                'name' => (string) $building['name'],
                'building_type' => (string) $building['building_type'],
                'created_at' => $this->access->iso((string) $building['created_at']),
            ],
            'households' => array_map(
                function (array $row): array {
                    $status = (string) $row['status'];

                    return [
                        'uuid' => (string) $row['uuid'],
                        'unit_number' => (string) $row['unit_number'],
                        'household_type' => (string) $row['household_type'],
                        'status' => $status,
                        'status_label' => $this->access->householdStatusLabel($status),
                        'status_tone' => $this->access->householdStatusTone($status),
                        'member_count' => (int) $row['member_count'],
                    ];
                },
                $households
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = $this->access->validate(
            $input,
            [
                'name' => 'required|string|min:2|max:120',
                'building_type' => 'required|in:' . implode(',', $this->access->buildingTypeValues()),
            ],
            ['name' => 'building name', 'building_type' => 'building type']
        );

        $this->ensureUniqueName((string) $clean['name']);

        $uuid = Uuid::v4();
        $id = $this->db->insert('buildings', [
            'uuid' => $uuid,
            'estate_id' => $this->access->estateId(),
            'name' => (string) $clean['name'],
            'building_type' => (string) $clean['building_type'],
            'created_at' => Clock::nowDb(),
        ]);

        $this->access->audit('admin.building_created', 'building', $id, ['building_uuid' => $uuid]);

        return ['uuid' => $uuid, 'name' => (string) $clean['name'], 'building_type' => (string) $clean['building_type']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $buildingUuid, array $input): array
    {
        $building = $this->access->findBuilding($buildingUuid);

        $clean = $this->access->validate(
            $input,
            [
                'name' => 'required|string|min:2|max:120',
                'building_type' => 'required|in:' . implode(',', $this->access->buildingTypeValues()),
            ],
            ['name' => 'building name', 'building_type' => 'building type']
        );

        $this->ensureUniqueName((string) $clean['name'], (int) $building['id']);

        $this->db->update(
            'buildings',
            ['name' => (string) $clean['name'], 'building_type' => (string) $clean['building_type']],
            ['id' => (int) $building['id']]
        );

        $this->access->audit('admin.building_updated', 'building', (int) $building['id'], [
            'building_uuid' => (string) $building['uuid'],
            'changed' => ['name', 'building_type'],
        ]);

        return [
            'uuid' => (string) $building['uuid'],
            'name' => (string) $clean['name'],
            'building_type' => (string) $clean['building_type'],
        ];
    }

    private function ensureUniqueName(string $name, ?int $excludeId = null): void
    {
        $params = ['estate_id' => $this->access->estateId(), 'name' => $name];
        $sql = 'SELECT id FROM buildings WHERE estate_id = :estate_id AND name = :name';

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        if ($this->db->selectOne($sql . ' LIMIT 1', $params) !== null) {
            throw new HttpException(
                422,
                'BUILDING_EXISTS',
                'That building name is already used in this estate.',
                ['errors' => ['name' => ['That building name is already used in this estate.']]]
            );
        }
    }
}
