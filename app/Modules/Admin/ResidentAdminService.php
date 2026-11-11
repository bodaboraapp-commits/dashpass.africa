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
 * Estate resident/member management (Increment 4 contract §2).
 *
 * Adding a resident either links an existing global user by phone or creates a
 * new user, then creates/activates the estate membership (allowlisted resident
 * roles) and optionally attaches the person to a household. A dev SMS invite is
 * sent after commit. Suspension sets estate_memberships.status='suspended', which
 * makes TenantContext drop the estate immediately (access lost).
 */
final class ResidentAdminService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly AdminAccess $access,
        private readonly AdminNotifier $notifier
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, AdminAccess::make($context), AdminNotifier::make($context->db));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->db->select(
            'SELECT em.uuid, em.status, em.created_at,
                    u.uuid AS user_uuid, u.full_name, u.phone,
                    r.name AS role_name,
                    (SELECT h.unit_number
                     FROM household_members hm
                     INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
                     WHERE hm.user_id = u.id AND hm.estate_id = em.estate_id AND hm.status = \'active\'
                     LIMIT 1) AS household_unit
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id
             ORDER BY u.full_name ASC',
            ['estate_id' => $this->access->estateId()]
        );

        return array_map(
            function (array $row): array {
                $status = (string) $row['status'];

                return [
                    'uuid' => (string) $row['uuid'],
                    'full_name' => (string) $row['full_name'],
                    'phone_masked' => $this->access->maskedPhone((string) $row['phone']),
                    'role_slugs' => [(string) $row['role_name']],
                    'household_unit' => $row['household_unit'] === null ? null : (string) $row['household_unit'],
                    'status' => $status,
                    'status_label' => $this->access->membershipStatusLabel($status),
                    'status_tone' => $this->access->membershipStatusTone($status),
                    'created_at' => $this->access->iso((string) $row['created_at']),
                ];
            },
            $rows
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = Validator::validate(
            $input,
            [
                'full_name' => 'required|string|min:2|max:180',
                'phone' => 'required|phone',
                'role_slug' => 'required|in:' . implode(',', $this->access->residentRoleValues()),
                'household_uuid' => 'nullable|uuid',
                'relationship' => 'nullable|string|max:60',
                'is_primary' => 'nullable|boolean',
            ],
            [
                'full_name' => 'full name',
                'phone' => 'phone number',
                'role_slug' => 'role',
                'household_uuid' => 'household',
                'relationship' => 'relationship',
                'is_primary' => 'primary member flag',
            ]
        );

        $roleSlug = (string) $clean['role_slug'];
        $roleId = $this->access->roleId($roleSlug);

        if ($roleId === null) {
            throw new ValidationException(['role_slug' => ['That role is not available in this estate.']]);
        }

        $phone = (string) $clean['phone'];
        $estateId = $this->access->estateId();
        $household = null;
        $householdUuid = isset($clean['household_uuid']) && is_string($clean['household_uuid'])
            ? $clean['household_uuid']
            : null;

        if ($householdUuid !== null) {
            $household = $this->access->findHousehold($householdUuid);

            if ((string) $household['status'] !== 'active') {
                throw new HttpException(
                    422,
                    'HOUSEHOLD_INACTIVE',
                    'Archived households cannot receive new residents.'
                );
            }
        }

        $membershipUuid = Uuid::v4();
        $userUuid = '';
        $userId = 0;
        $membershipId = 0;
        $createdUser = false;
        $relationship = isset($clean['relationship']) && $clean['relationship'] !== null
            ? trim((string) $clean['relationship'])
            : null;
        $requestedPrimary = (bool) ($clean['is_primary'] ?? false);

        $this->db->transaction(function () use (
            $clean,
            $phone,
            $estateId,
            $roleId,
            $membershipUuid,
            $household,
            $relationship,
            $requestedPrimary,
            &$userUuid,
            &$userId,
            &$membershipId,
            &$createdUser
        ): void {
            $user = $this->access->findUserByPhone($phone);

            if ($user !== null) {
                $userId = (int) $user['id'];
                $userUuid = (string) $user['uuid'];
                $membership = $this->access->membershipForUser($userId);

                if ($membership !== null && (string) $membership['status'] === 'active') {
                    throw new HttpException(
                        422,
                        'RESIDENT_EXISTS',
                        'That phone number already belongs to an active member of this estate.',
                        ['errors' => ['phone' => ['That phone number already belongs to an active member of this estate.']]]
                    );
                }
            } else {
                $createdUser = true;
                $userUuid = Uuid::v4();
                $now = Clock::nowDb();
                $userId = $this->db->insert('users', [
                    'uuid' => $userUuid,
                    'full_name' => (string) $clean['full_name'],
                    'phone' => $phone,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $existingMembership = $this->access->membershipForUser($userId);

            if ($existingMembership !== null) {
                $membershipId = (int) $existingMembership['id'];
                $this->db->update('estate_memberships', [
                    'role_id' => $roleId,
                    'status' => 'active',
                    'updated_at' => Clock::nowDb(),
                ], ['id' => $membershipId]);
            } else {
                $now = Clock::nowDb();
                $membershipId = $this->db->insert('estate_memberships', [
                    'uuid' => $membershipUuid,
                    'estate_id' => $estateId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (is_array($household)) {
                $this->attachToHousehold(
                    (int) $household['id'],
                    $estateId,
                    $userId,
                    $relationship,
                    $requestedPrimary
                );
            }
        });

        $action = $createdUser ? 'admin.resident_created' : 'admin.resident_linked';

        $this->access->audit($action, 'estate_membership', $membershipId, [
            'membership_uuid' => $membershipUuid,
            'user_uuid' => $userUuid,
            'role' => $roleSlug,
            'household_uuid' => $householdUuid,
        ]);

        // Invite SMS after commit: a delivery failure never fails the operation.
        $this->notifier->sendResidentInvite($estateId, $phone, $this->access->estateName());

        return ['uuid' => $membershipUuid, 'user_uuid' => $userUuid, 'created_user' => $createdUser];
    }

    /**
     * @return array{status: string}
     */
    public function suspend(string $membershipUuid): array
    {
        $membership = $this->access->findMembership($membershipUuid);

        if ((int) $membership['user_id'] === $this->access->userId()) {
            throw new HttpException(
                422,
                'CANNOT_SUSPEND_SELF',
                'You cannot suspend your own estate membership.'
            );
        }

        if ((string) $membership['status'] !== 'suspended') {
            $this->db->update(
                'estate_memberships',
                ['status' => 'suspended', 'updated_at' => Clock::nowDb()],
                ['id' => (int) $membership['id']]
            );

            $this->access->audit('admin.resident_suspended', 'estate_membership', (int) $membership['id'], [
                'membership_uuid' => (string) $membership['uuid'],
                'user_uuid' => (string) $membership['user_uuid'],
            ]);
        }

        return ['status' => 'suspended'];
    }

    /**
     * @return array{status: string}
     */
    public function reactivate(string $membershipUuid): array
    {
        $membership = $this->access->findMembership($membershipUuid);

        if ((string) $membership['status'] !== 'active') {
            $this->db->update(
                'estate_memberships',
                ['status' => 'active', 'updated_at' => Clock::nowDb()],
                ['id' => (int) $membership['id']]
            );

            $this->access->audit('admin.resident_reactivated', 'estate_membership', (int) $membership['id'], [
                'membership_uuid' => (string) $membership['uuid'],
                'user_uuid' => (string) $membership['user_uuid'],
            ]);
        }

        return ['status' => 'active'];
    }

    private function attachToHousehold(
        int $householdId,
        int $estateId,
        int $userId,
        ?string $relationship,
        bool $requestedPrimary
    ): void {
        $existing = $this->db->selectOne(
            'SELECT id, status FROM household_members
             WHERE household_id = :household_id AND user_id = :user_id LIMIT 1',
            ['household_id' => $householdId, 'user_id' => $userId]
        );

        $hasPrimary = $this->db->selectOne(
            "SELECT id FROM household_members
             WHERE household_id = :household_id AND estate_id = :estate_id
               AND status = 'active' AND is_primary = 1 LIMIT 1",
            ['household_id' => $householdId, 'estate_id' => $estateId]
        ) !== null;
        $isPrimary = $requestedPrimary || !$hasPrimary;

        if ($isPrimary) {
            $this->db->execute(
                "UPDATE household_members SET is_primary = 0
                 WHERE household_id = :household_id AND estate_id = :estate_id AND status = 'active'",
                ['household_id' => $householdId, 'estate_id' => $estateId]
            );
        }

        $now = Clock::nowDb();

        if ($existing !== null) {
            $this->db->update('household_members', [
                'relationship' => $relationship,
                'is_primary' => $isPrimary ? 1 : 0,
                'status' => 'active',
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);

            return;
        }

        $this->db->insert('household_members', [
            'uuid' => Uuid::v4(),
            'estate_id' => $estateId,
            'household_id' => $householdId,
            'user_id' => $userId,
            'relationship' => $relationship,
            'is_primary' => $isPrimary ? 1 : 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
