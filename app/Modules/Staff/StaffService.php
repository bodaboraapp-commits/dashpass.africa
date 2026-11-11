<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Phone;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Core\Validator;

/**
 * Household staff directory service (Increment 6 §3).
 *
 * Residents manage staff for households they hold an active membership in; the
 * admin directory is estate-wide. Phones are stored in E.164 and displayed masked.
 * Staff records are never hard-deleted — deactivate/reactivate only. Every
 * mutation audits uuid/type metadata (never names or phones).
 */
final class StaffService
{
    /** @var array<int, string> */
    private const STAFF_TYPES = ['domestic', 'driver', 'gardener', 'nanny', 'cook', 'security', 'other'];

    /** @var array<string, string> */
    private const STAFF_TYPE_LABELS = [
        'domestic' => 'Domestic worker',
        'driver' => 'Driver',
        'gardener' => 'Gardener',
        'nanny' => 'Nanny',
        'cook' => 'Cook',
        'security' => 'Security',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly StaffRepository $staff
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new StaffRepository($context->db));
    }

    /* ------------------------------------------------------------------ */
    /* Options / labels                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function staffTypes(): array
    {
        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => self::STAFF_TYPE_LABELS[$value] ?? ucfirst($value)],
            self::STAFF_TYPES
        );
    }

    /**
     * @return array<int, string>
     */
    public function staffTypeValues(): array
    {
        return self::STAFF_TYPES;
    }

    public static function staffTypeLabel(string $value): string
    {
        return self::STAFF_TYPE_LABELS[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    public static function statusLabel(string $status): string
    {
        return $status === 'inactive' ? 'Inactive' : 'Active';
    }

    public static function statusTone(string $status): string
    {
        return $status === 'inactive' ? 'neutral' : 'ok';
    }

    /**
     * @return array<int, array{uuid: string, label: string}>
     */
    public function householdsForSelect(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();
        $rows = $this->staff->householdsForUser((int) $user['id'], $estateId);

        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => self::householdLabel($row),
        ], $rows);
    }

    /**
     * @return array<int, array{uuid: string, label: string}>
     */
    public function adminHouseholdsForSelect(): array
    {
        return array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'label' => self::householdLabel($row),
            ],
            $this->staff->estateHouseholds($this->estateId())
        );
    }

    /* ------------------------------------------------------------------ */
    /* Lists                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOwn(): array
    {
        $user = $this->context->auth->requireUser();

        return array_map(
            fn (array $row): array => $this->present($row),
            $this->staff->forUser((int) $user['id'], $this->estateId())
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEstate(): array
    {
        return array_map(
            fn (array $row): array => $this->present($row),
            $this->staff->forEstate($this->estateId())
        );
    }

    /* ------------------------------------------------------------------ */
    /* Mutations                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = $this->validate($input);
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();
        $householdUuid = isset($clean['household_uuid']) && is_string($clean['household_uuid'])
            ? trim($clean['household_uuid'])
            : '';

        if ($householdUuid === '') {
            // Convenience for single-household residents when the form omits the
            // selector: default to the only active household.
            $households = $this->staff->householdsForUser((int) $user['id'], $estateId);

            if (count($households) !== 1) {
                throw new ValidationException([
                    'household_uuid' => ['Select the household this staff member works for.'],
                ]);
            }

            $householdUuid = (string) $households[0]['uuid'];
        }

        $household = $this->staff->findUserHousehold($householdUuid, (int) $user['id'], $estateId);

        if ($household === null) {
            throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available for your account.');
        }

        if ((string) $household['status'] !== 'active') {
            throw new HttpException(422, 'HOUSEHOLD_INACTIVE', 'Archived households cannot receive staff.');
        }

        $this->assertNoDuplicate($estateId, (int) $household['id'], (string) $clean['phone']);

        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $id = $this->staff->insert([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'household_id' => (int) $household['id'],
            'user_id' => null,
            'full_name' => (string) $clean['full_name'],
            'phone' => (string) $clean['phone'],
            'staff_type' => (string) $clean['staff_type'],
            'notes' => isset($clean['notes']) && $clean['notes'] !== null ? (string) $clean['notes'] : null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('staff.created', $id, [
            'staff_uuid' => $uuid,
            'household_uuid' => (string) $household['uuid'],
            'staff_type' => (string) $clean['staff_type'],
        ]);

        $row = $this->staff->findForEstate($uuid, $estateId);

        return $this->present($row ?? ['uuid' => $uuid, 'full_name' => $clean['full_name'], 'phone' => $clean['phone']]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $staffUuid, array $input): array
    {
        $row = $this->findOwn($staffUuid);
        $clean = $this->validate($input, false);
        $estateId = $this->estateId();
        $this->assertNoDuplicate(
            $estateId,
            (int) $row['household_id'],
            (string) $clean['phone'],
            (int) $row['id']
        );

        $fields = [
            'full_name' => (string) $clean['full_name'],
            'phone' => (string) $clean['phone'],
            'staff_type' => (string) $clean['staff_type'],
            'notes' => isset($clean['notes']) && $clean['notes'] !== null ? (string) $clean['notes'] : null,
            'updated_at' => Clock::nowDb(),
        ];
        $this->staff->update((int) $row['id'], $fields);

        $this->audit('staff.updated', (int) $row['id'], [
            'staff_uuid' => (string) $row['uuid'],
            'household_uuid' => (string) $row['household_uuid'],
            'changed' => array_keys($fields),
        ]);

        $fresh = $this->staff->findForEstate((string) $row['uuid'], $estateId);

        return $this->present($fresh ?? $row);
    }

    /**
     * @return array{status: string}
     */
    public function deactivate(string $staffUuid): array
    {
        $row = $this->findOwn($staffUuid);

        if ((string) $row['status'] !== 'inactive') {
            $this->staff->update((int) $row['id'], ['status' => 'inactive', 'updated_at' => Clock::nowDb()]);
            $this->audit('staff.deactivated', (int) $row['id'], [
                'staff_uuid' => (string) $row['uuid'],
                'household_uuid' => (string) $row['household_uuid'],
            ]);
        }

        return ['status' => 'inactive'];
    }

    /**
     * @return array{status: string}
     */
    public function reactivate(string $staffUuid): array
    {
        $row = $this->findOwn($staffUuid);

        if ((string) $row['status'] === 'inactive') {
            if ((string) $row['household_status'] !== 'active') {
                throw new HttpException(422, 'HOUSEHOLD_INACTIVE', 'Archived households cannot have active staff.');
            }

            $this->assertNoDuplicate(
                $this->estateId(),
                (int) $row['household_id'],
                (string) $row['phone'],
                (int) $row['id']
            );

            $this->staff->update((int) $row['id'], ['status' => 'active', 'updated_at' => Clock::nowDb()]);
            $this->audit('staff.updated', (int) $row['id'], [
                'staff_uuid' => (string) $row['uuid'],
                'household_uuid' => (string) $row['household_uuid'],
                'changed' => ['status'],
            ]);
        }

        return ['status' => 'active'];
    }

    /* ------------------------------------------------------------------ */
    /* Presentation                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'phone_masked' => Phone::mask((string) ($row['phone'] ?? '')),
            'staff_type' => (string) ($row['staff_type'] ?? 'other'),
            'staff_type_label' => self::staffTypeLabel((string) ($row['staff_type'] ?? 'other')),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'household_label' => self::householdLabel($row),
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'created_at' => Clock::fromDb(isset($row['created_at']) ? (string) $row['created_at'] : null)?->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * @param array<string, mixed> $household
     */
    public static function householdLabel(array $household): string
    {
        $unit = (string) ($household['unit_number'] ?? '');
        $building = (string) ($household['building_name'] ?? '');

        return $building === '' ? $unit : $unit . ' · ' . $building;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $requireHousehold = true): array
    {
        $rules = [
            'full_name' => 'required|string|min:2|max:180',
            'phone' => 'required|phone',
            'staff_type' => 'required|in:' . implode(',', self::STAFF_TYPES),
            'notes' => 'nullable|string|max:500',
            'household_uuid' => 'nullable|uuid',
        ];

        return Validator::validate($input, $rules, [
            'full_name' => 'full name',
            'phone' => 'phone number',
            'staff_type' => 'staff type',
            'notes' => 'notes',
            'household_uuid' => 'household',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findOwn(string $staffUuid): array
    {
        $user = $this->context->auth->requireUser();
        $row = $this->staff->findForUser($staffUuid, (int) $user['id'], $this->estateId());

        if ($row === null) {
            throw HttpException::notFound('STAFF_NOT_FOUND', 'That staff record is not available for your account.');
        }

        return $row;
    }

    private function assertNoDuplicate(int $estateId, int $householdId, string $phone, ?int $excludeId = null): void
    {
        if ($this->staff->findActiveByPhone($estateId, $householdId, $phone, $excludeId) !== null) {
            throw new HttpException(
                422,
                'STAFF_EXISTS',
                'That phone number is already registered to active staff in this household.',
                ['errors' => ['phone' => ['That phone number is already registered to active staff in this household.']]]
            );
        }
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, ?int $entityId, array $metadata): void
    {
        $this->context->audit->record($action, 'household_staff', $entityId, $metadata, $this->estateId());
    }
}
