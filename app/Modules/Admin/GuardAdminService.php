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
 * Guard management (Increment 4 contract §2).
 *
 * Creating a guard creates/links the user, its security_guard estate membership
 * and the gate assignments transactionally. Assignment updates replace the whole
 * set (link rows only, no user data is lost). Archive deactivates the membership
 * and clears assignments so the guard immediately loses gate access.
 */
final class GuardAdminService
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
            "SELECT em.uuid, em.status, em.user_id,
                    u.uuid AS user_uuid, u.full_name, u.phone
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id AND r.name = 'security_guard'
             ORDER BY u.full_name ASC",
            ['estate_id' => $this->access->estateId()]
        );

        $assignments = [];

        foreach ($this->db->select(
            "SELECT a.guard_user_id, g.uuid, g.name
             FROM guard_gate_assignments a
             INNER JOIN gates g ON g.id = a.gate_id AND g.estate_id = a.estate_id
             WHERE a.estate_id = :estate_id AND a.status = 'active'
             ORDER BY g.name ASC",
            ['estate_id' => $this->access->estateId()]
        ) as $assignment) {
            $assignments[(int) $assignment['guard_user_id']][] = [
                'uuid' => (string) $assignment['uuid'],
                'name' => (string) $assignment['name'],
            ];
        }

        return array_map(
            function (array $row) use ($assignments): array {
                $status = (string) $row['status'];
                $gates = $assignments[(int) $row['user_id']] ?? [];

                return [
                    'uuid' => (string) $row['uuid'],
                    'full_name' => (string) $row['full_name'],
                    'phone_masked' => $this->access->maskedPhone((string) $row['phone']),
                    'gate_uuids' => array_column($gates, 'uuid'),
                    'gate_names' => array_column($gates, 'name'),
                    'status' => $status,
                    'status_label' => $this->access->membershipStatusLabel($status),
                    'status_tone' => $this->access->membershipStatusTone($status),
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
                'gate_uuids' => 'required|array',
            ],
            ['full_name' => 'full name', 'phone' => 'phone number', 'gate_uuids' => 'gates']
        );

        $gateUuids = $this->validatedGateUuids($input['gate_uuids'] ?? []);

        if ($gateUuids === []) {
            throw new ValidationException(['gate_uuids' => ['Select at least one active gate for the guard.']]);
        }

        $gates = $this->access->activeGatesByUuids($gateUuids);

        if (count($gates) !== count($gateUuids)) {
            throw new ValidationException([
                'gate_uuids' => ['One or more selected gates are not active in this estate.'],
            ]);
        }

        $roleId = $this->access->roleId('security_guard');

        if ($roleId === null) {
            throw new HttpException(500, 'ROLE_MISSING', 'The security-guard role is not configured.');
        }

        $estateId = $this->access->estateId();
        $phone = (string) $clean['phone'];
        $membershipUuid = Uuid::v4();
        $userUuid = '';
        $userId = 0;
        $membershipId = 0;

        $this->db->transaction(function () use (
            $clean,
            $phone,
            $estateId,
            $roleId,
            $membershipUuid,
            $gates,
            &$userUuid,
            &$userId,
            &$membershipId
        ): void {
            $user = $this->access->findUserByPhone($phone);

            if ($user !== null) {
                $userId = (int) $user['id'];
                $userUuid = (string) $user['uuid'];
                $membership = $this->access->membershipForUser($userId);

                if ($membership !== null && (string) $membership['status'] === 'active') {
                    throw new HttpException(
                        422,
                        'GUARD_EXISTS',
                        'That phone number already belongs to an active member of this estate.',
                        ['errors' => ['phone' => ['That phone number already belongs to an active member of this estate.']]]
                    );
                }
            } else {
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

            foreach ($gates as $gate) {
                $this->db->insert('guard_gate_assignments', [
                    'uuid' => Uuid::v4(),
                    'estate_id' => $estateId,
                    'gate_id' => (int) $gate['id'],
                    'guard_user_id' => $userId,
                    'shift' => null,
                    'status' => 'active',
                    'created_at' => Clock::nowDb(),
                ]);
            }
        });

        $this->access->audit('admin.guard_created', 'estate_membership', $membershipId, [
            'membership_uuid' => $membershipUuid,
            'user_uuid' => $userUuid,
            'gate_uuids' => array_column($gates, 'uuid'),
        ]);

        return ['uuid' => $membershipUuid, 'user_uuid' => $userUuid];
    }

    /**
     * Replace a guard's gate assignments transactionally (link rows only).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function assignGates(string $guardMembershipUuid, array $input): array
    {
        $guard = $this->access->findGuardMembership($guardMembershipUuid);

        $gateUuids = $this->validatedGateUuids($input['gate_uuids'] ?? []);
        $gates = $this->access->activeGatesByUuids($gateUuids);

        if (count($gates) !== count($gateUuids)) {
            throw new ValidationException([
                'gate_uuids' => ['One or more selected gates are not active in this estate.'],
            ]);
        }

        $estateId = $this->access->estateId();

        $this->db->transaction(function () use ($guard, $gates, $estateId): void {
            $this->db->execute(
                'DELETE FROM guard_gate_assignments WHERE estate_id = :estate_id AND guard_user_id = :guard_user_id',
                ['estate_id' => $estateId, 'guard_user_id' => (int) $guard['user_id']]
            );

            foreach ($gates as $gate) {
                $this->db->insert('guard_gate_assignments', [
                    'uuid' => Uuid::v4(),
                    'estate_id' => $estateId,
                    'gate_id' => (int) $gate['id'],
                    'guard_user_id' => (int) $guard['user_id'],
                    'shift' => null,
                    'status' => 'active',
                    'created_at' => Clock::nowDb(),
                ]);
            }
        });

        $this->access->audit('admin.guard_gates_updated', 'estate_membership', (int) $guard['id'], [
            'membership_uuid' => (string) $guard['uuid'],
            'gate_uuids' => array_column($gates, 'uuid'),
        ]);

        return ['gate_uuids' => array_column($gates, 'uuid')];
    }

    /**
     * Archive a guard: deactivate the estate membership and clear assignments.
     *
     * `estate_memberships.status` has no `inactive` enum value, so archive maps
     * to `suspended` (the existing status that removes estate/gate access);
     * user/household/membership rows are retained, never hard-deleted.
     *
     * @return array{status: string}
     */
    public function archive(string $guardMembershipUuid): array
    {
        $guard = $this->access->findGuardMembership($guardMembershipUuid);
        $estateId = $this->access->estateId();

        $this->db->transaction(function () use ($guard, $estateId): void {
            $this->db->execute(
                'DELETE FROM guard_gate_assignments WHERE estate_id = :estate_id AND guard_user_id = :guard_user_id',
                ['estate_id' => $estateId, 'guard_user_id' => (int) $guard['user_id']]
            );
            $this->db->update(
                'estate_memberships',
                ['status' => 'suspended', 'updated_at' => Clock::nowDb()],
                ['id' => (int) $guard['id']]
            );
        });

        $this->access->audit('admin.guard_archived', 'estate_membership', (int) $guard['id'], [
            'membership_uuid' => (string) $guard['uuid'],
            'user_uuid' => (string) $guard['user_uuid'],
        ]);

        return ['status' => 'suspended'];
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function validatedGateUuids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $uuids = [];

        foreach ($raw as $uuid) {
            if (!is_string($uuid) || !Uuid::isValid($uuid)) {
                throw new ValidationException(['gate_uuids' => ['One or more selected gates are not valid.']]);
            }

            $uuids[] = $uuid;
        }

        return array_values(array_unique($uuids));
    }
}
