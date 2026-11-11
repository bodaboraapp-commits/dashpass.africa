<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Core\Validator;

/**
 * Household + household-member management (Increment 4 contract §2).
 *
 * Households are never hard-deleted: archive/unarchive is `status` active/inactive
 * and archived households are excluded from selectors and cannot receive new
 * members. Members are soft-removed (`status` inactive) with a single-primary rule
 * and a last-active-primary guard.
 */
final class HouseholdAdminService
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
    public function list(string $statusFilter): array
    {
        $params = ['estate_id' => $this->access->estateId()];
        $filter = '';

        if ($statusFilter !== 'all') {
            $filter = ' AND h.status = :status';
            $params['status'] = $statusFilter;
        }

        $rows = $this->db->select(
            'SELECT h.uuid, h.unit_number, h.household_type, h.status, b.name AS building_name,
                    (SELECT COUNT(*) FROM household_members hm
                     WHERE hm.household_id = h.id AND hm.estate_id = h.estate_id AND hm.status = \'active\') AS member_count
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id' . $filter . '
             ORDER BY h.unit_number ASC',
            $params
        );

        return array_map(
            function (array $row): array {
                $status = (string) $row['status'];

                return [
                    'uuid' => (string) $row['uuid'],
                    'unit_number' => (string) $row['unit_number'],
                    'building_name' => $row['building_name'] === null ? null : (string) $row['building_name'],
                    'household_type' => (string) $row['household_type'],
                    'status' => $status,
                    'status_label' => $this->access->householdStatusLabel($status),
                    'status_tone' => $this->access->householdStatusTone($status),
                    'member_count' => (int) $row['member_count'],
                ];
            },
            $rows
        );
    }

    /**
     * @return array{household: array<string, mixed>, members: array<int, array<string, mixed>>}
     */
    public function show(string $householdUuid): array
    {
        $household = $this->access->findHousehold($householdUuid);
        $status = (string) $household['status'];

        $members = $this->db->select(
            'SELECT hm.uuid, hm.relationship, hm.is_primary, hm.status,
                    u.uuid AS user_uuid, u.full_name, u.phone
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id AND hm.estate_id = :estate_id
             ORDER BY hm.is_primary DESC, hm.status ASC, hm.id ASC',
            ['household_id' => (int) $household['id'], 'estate_id' => $this->access->estateId()]
        );

        return [
            'household' => [
                'uuid' => (string) $household['uuid'],
                'unit_number' => (string) $household['unit_number'],
                'building_uuid' => $household['building_uuid'] === null ? null : (string) $household['building_uuid'],
                'building_name' => $household['building_name'] === null ? null : (string) $household['building_name'],
                'household_type' => (string) $household['household_type'],
                'status' => $status,
                'status_label' => $this->access->householdStatusLabel($status),
                'status_tone' => $this->access->householdStatusTone($status),
            ],
            'members' => array_map(
                function (array $row): array {
                    $memberStatus = (string) $row['status'];

                    return [
                        'uuid' => (string) $row['uuid'],
                        'user_uuid' => (string) $row['user_uuid'],
                        'full_name' => (string) $row['full_name'],
                        'phone_masked' => $this->access->maskedPhone((string) $row['phone']),
                        'relationship' => $row['relationship'] === null ? null : (string) $row['relationship'],
                        'is_primary' => (bool) $row['is_primary'],
                        'status' => $memberStatus,
                        'status_label' => $this->access->memberStatusLabel($memberStatus),
                        'status_tone' => $this->access->memberStatusTone($memberStatus),
                    ];
                },
                $members
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
                'unit_number' => 'required|string|min:1|max:60',
                'building_uuid' => 'nullable|uuid',
                'household_type' => 'required|in:' . implode(',', $this->access->householdTypeValues()),
            ],
            ['unit_number' => 'unit number', 'building_uuid' => 'building', 'household_type' => 'household type']
        );

        $unit = trim((string) $clean['unit_number']);
        $this->ensureUniqueUnit($unit);

        $buildingId = null;
        $buildingUuid = isset($clean['building_uuid']) && is_string($clean['building_uuid']) ? $clean['building_uuid'] : null;

        if ($buildingUuid !== null) {
            $building = $this->access->findBuilding($buildingUuid);
            $buildingId = (int) $building['id'];
        }

        $uuid = Uuid::v4();
        $id = $this->db->insert('households', [
            'uuid' => $uuid,
            'estate_id' => $this->access->estateId(),
            'building_id' => $buildingId,
            'unit_number' => $unit,
            'household_type' => (string) $clean['household_type'],
            'status' => 'active',
            'created_at' => Clock::nowDb(),
        ]);

        $this->access->audit('admin.household_created', 'household', $id, [
            'household_uuid' => $uuid,
            'building_uuid' => $buildingUuid,
        ]);

        return ['uuid' => $uuid, 'unit_number' => $unit];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $householdUuid, array $input): array
    {
        $household = $this->access->findHousehold($householdUuid);

        $clean = $this->access->validate(
            $input,
            [
                'unit_number' => 'required|string|min:1|max:60',
                'building_uuid' => 'nullable|uuid',
                'household_type' => 'required|in:' . implode(',', $this->access->householdTypeValues()),
                'status' => 'nullable|in:active,inactive',
            ],
            [
                'unit_number' => 'unit number',
                'building_uuid' => 'building',
                'household_type' => 'household type',
                'status' => 'status',
            ]
        );

        $unit = trim((string) $clean['unit_number']);
        $this->ensureUniqueUnit($unit, (int) $household['id']);

        $buildingUuid = isset($clean['building_uuid']) && is_string($clean['building_uuid'])
            ? $clean['building_uuid']
            : null;
        $buildingId = null;

        if ($buildingUuid !== null) {
            $building = $this->access->findBuilding($buildingUuid);
            $buildingId = (int) $building['id'];
        }

        $fields = [
            'unit_number' => $unit,
            'building_id' => $buildingId,
            'household_type' => (string) $clean['household_type'],
        ];

        if (isset($clean['status']) && $clean['status'] !== null) {
            $fields['status'] = (string) $clean['status'];
        }

        $this->db->update('households', $fields, ['id' => (int) $household['id']]);

        $this->access->audit('admin.household_updated', 'household', (int) $household['id'], [
            'household_uuid' => (string) $household['uuid'],
            'changed' => array_keys($fields),
        ]);

        return ['uuid' => (string) $household['uuid'], 'unit_number' => $unit];
    }

    /**
     * @return array{status: string}
     */
    public function archive(string $householdUuid): array
    {
        $household = $this->access->findHousehold($householdUuid);
        $this->db->update('households', ['status' => 'inactive'], ['id' => (int) $household['id']]);

        $this->access->audit('admin.household_archived', 'household', (int) $household['id'], [
            'household_uuid' => (string) $household['uuid'],
            'previous_status' => (string) $household['status'],
        ]);

        return ['status' => 'inactive'];
    }

    /**
     * Add (or re-activate) a member of a household, creating/linking the estate
     * user if needed. Enforces one primary member per household.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addMember(string $householdUuid, array $input): array
    {
        $household = $this->access->findHousehold($householdUuid);

        if ((string) $household['status'] !== 'active') {
            throw new HttpException(
                422,
                'HOUSEHOLD_INACTIVE',
                'Archived households cannot receive new members.'
            );
        }

        $clean = Validator::validate(
            $input,
            [
                'mode' => 'nullable|in:new,existing',
                'phone' => 'required|phone',
                'full_name' => 'nullable|string|min:2|max:180',
                'relationship' => 'nullable|string|max:60',
                'is_primary' => 'nullable|boolean',
            ],
            [
                'mode' => 'add mode',
                'phone' => 'phone number',
                'full_name' => 'full name',
                'relationship' => 'relationship',
                'is_primary' => 'primary member flag',
            ]
        );

        $phone = (string) $clean['phone'];
        $relationship = isset($clean['relationship']) && $clean['relationship'] !== null
            ? trim((string) $clean['relationship'])
            : null;
        $requestedPrimary = (bool) ($clean['is_primary'] ?? false);
        $estateId = $this->access->estateId();
        $memberUuid = Uuid::v4();
        $userId = 0;
        $memberId = 0;
        $userUuid = '';
        $mode = isset($clean['mode']) && is_string($clean['mode']) && $clean['mode'] !== ''
            ? (string) $clean['mode']
            : 'auto';

        if ($mode === 'existing' && $this->access->findUserByPhone($phone) === null) {
            throw new ValidationException(['phone' => ['No estate user exists for that phone number.']]);
        }

        $this->db->transaction(function () use (
            $household,
            $phone,
            $relationship,
            $requestedPrimary,
            $estateId,
            $memberUuid,
            $clean,
            &$userId,
            &$memberId,
            &$userUuid
        ): void {
            $user = $this->access->findUserByPhone($phone);

            if ($user === null) {
                $fullName = isset($clean['full_name']) ? trim((string) $clean['full_name']) : '';

                if ($fullName === '') {
                    throw new ValidationException(['full_name' => ['Enter the resident’s full name.']]);
                }

                $now = Clock::nowDb();
                $userUuid = Uuid::v4();
                $userId = $this->db->insert('users', [
                    'uuid' => $userUuid,
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $roleId = $this->access->roleId('household_member');

                if ($roleId === null) {
                    throw new HttpException(500, 'ROLE_MISSING', 'The household-member role is not configured.');
                }

                $this->db->insert('estate_memberships', [
                    'uuid' => Uuid::v4(),
                    'estate_id' => $estateId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'status' => 'active',
                    'created_at' => Clock::nowDb(),
                    'updated_at' => Clock::nowDb(),
                ]);
            } else {
                $userId = (int) $user['id'];
                $userUuid = (string) $user['uuid'];

                // Only active memberships in the active estate can be added.
                $membership = $this->access->membershipForUser($userId);

                if ($membership === null || (string) $membership['status'] !== 'active') {
                    throw new HttpException(
                        422,
                        'RESIDENT_NOT_ACTIVE',
                        'That person does not have an active membership in this estate.',
                        ['errors' => ['phone' => ['That person does not have an active membership in this estate.']]]
                    );
                }
            }

                $existing = $this->db->selectOne(
                    'SELECT id, status FROM household_members
                     WHERE household_id = :household_id AND user_id = :user_id LIMIT 1',
                    ['household_id' => (int) $household['id'], 'user_id' => $userId]
                );

                if ($existing !== null && (string) $existing['status'] === 'active') {
                    throw new HttpException(
                        422,
                        'MEMBER_EXISTS',
                        'That person is already an active member of this household.',
                        ['errors' => ['phone' => ['That person is already an active member of this household.']]]
                    );
                }

                $hasPrimary = $this->db->selectOne(
                    "SELECT id FROM household_members
                     WHERE household_id = :household_id AND estate_id = :estate_id
                       AND status = 'active' AND is_primary = 1 LIMIT 1",
                    ['household_id' => (int) $household['id'], 'estate_id' => $estateId]
                ) !== null;
                $isPrimary = $requestedPrimary || !$hasPrimary;

                if ($isPrimary) {
                    $this->db->execute(
                        "UPDATE household_members SET is_primary = 0
                         WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'active'",
                        ['household_id' => (int) $household['id'], 'estate_id' => $estateId]
                    );
                }

                $now = Clock::nowDb();

                if ($existing !== null) {
                    $memberId = (int) $existing['id'];
                    $this->db->update('household_members', [
                        'relationship' => $relationship,
                        'is_primary' => $isPrimary ? 1 : 0,
                        'status' => 'active',
                        'updated_at' => $now,
                    ], ['id' => $memberId]);
                } else {
                    $memberId = $this->db->insert('household_members', [
                        'uuid' => $memberUuid,
                        'estate_id' => $estateId,
                        'household_id' => (int) $household['id'],
                        'user_id' => $userId,
                        'relationship' => $relationship,
                        'is_primary' => $isPrimary ? 1 : 0,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $this->access->audit('admin.member_added', 'household_member', $memberId, [
                    'household_uuid' => (string) $household['uuid'],
                    'member_uuid' => $memberUuid,
                    'user_uuid' => $userUuid,
                ]);
        });

        return ['member_uuid' => $memberUuid, 'user_id' => $userId];
    }

    /**
     * Soft-remove a household member (never a hard delete), guarding the last
     * active primary member.
     *
     * @return array{status: string}
     */
    public function removeMember(string $householdUuid, string $memberUuid): array
    {
        $household = $this->access->findHousehold($householdUuid);
        $member = $this->access->findHouseholdMember($householdUuid, $memberUuid);

        if ((string) $member['status'] !== 'active') {
            return ['status' => 'inactive'];
        }

        if ((bool) $member['is_primary']) {
            $activePrimaryCount = (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM household_members
                 WHERE household_id = :household_id AND estate_id = :estate_id
                   AND status = 'active' AND is_primary = 1",
                ['household_id' => (int) $household['id'], 'estate_id' => $this->access->estateId()]
            ) ?? 0);

            if ($activePrimaryCount <= 1) {
                throw new HttpException(
                    422,
                    'PRIMARY_MEMBER_REQUIRED',
                    'Cannot remove the last active primary member. Assign another primary member first.',
                    ['errors' => ['member' => ['Cannot remove the last active primary member.']]]
                );
            }
        }

        $this->db->update(
            'household_members',
            ['status' => 'inactive', 'is_primary' => 0, 'updated_at' => Clock::nowDb()],
            ['id' => (int) $member['id']]
        );

        $this->access->audit('admin.member_removed', 'household_member', (int) $member['id'], [
            'household_uuid' => (string) $household['uuid'],
            'member_uuid' => (string) $member['uuid'],
        ]);

        return ['status' => 'inactive'];
    }

    private function ensureUniqueUnit(string $unit, ?int $excludeId = null): void
    {
        $params = ['estate_id' => $this->access->estateId(), 'unit' => $unit];
        $sql = 'SELECT id FROM households WHERE estate_id = :estate_id AND unit_number = :unit';

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        if ($this->db->selectOne($sql . ' LIMIT 1', $params) !== null) {
            throw new HttpException(
                422,
                'UNIT_EXISTS',
                'That unit number is already used in this estate.',
                ['errors' => ['unit_number' => ['That unit number is already used in this estate.']]]
            );
        }
    }
}
