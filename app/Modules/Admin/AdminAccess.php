<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Phone;

/**
 * Shared, estate-scoped lookups and presentation helpers for the admin portal.
 *
 * All ids come from the authenticated Context.foreign-estate/foreign-household
 * uuids resolve to a generic 404 so other estates cannot be enumerated.
 */
final class AdminAccess
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db);
    }

    public function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    public function userId(): int
    {
        return (int) $this->context->auth->requireUser()['id'];
    }

    public function estateName(): string
    {
        return (string) ($this->context->tenant->requireEstate()['estate_name'] ?? '');
    }

    public function timezone(): string
    {
        return (string) ($this->context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function buildingTypes(): array
    {
        return [
            ['value' => 'apartment_block', 'label' => 'Apartment block'],
            ['value' => 'street', 'label' => 'Street'],
            ['value' => 'cluster', 'label' => 'Cluster'],
            ['value' => 'zone', 'label' => 'Zone'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function householdTypes(): array
    {
        return [
            ['value' => 'apartment', 'label' => 'Apartment'],
            ['value' => 'maisonette', 'label' => 'Maisonette'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function buildingTypeValues(): array
    {
        return array_column($this->buildingTypes(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public function householdTypeValues(): array
    {
        return array_column($this->householdTypes(), 'value');
    }

    /**
     * Allowlisted resident roles, resolved by roles.name (no hardcoded ids).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function residentRoles(): array
    {
        $allowed = ['resident', 'tenant', 'household_member'];
        $rows = $this->db->select(
            "SELECT name FROM roles WHERE scope = 'estate' AND name IN ('resident', 'tenant', 'household_member')"
        );
        $existing = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        $roles = [];

        foreach ($allowed as $name) {
            $roles[] = [
                'value' => $name,
                'label' => $this->roleLabel($name),
                'available' => in_array($name, $existing, true),
            ];
        }

        return $roles;
    }

    /**
     * @return array<int, string>
     */
    public function residentRoleValues(): array
    {
        return ['resident', 'tenant', 'household_member'];
    }

    public function roleLabel(string $name): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $name));
    }

    public function householdStatusLabel(string $status): string
    {
        return $status === 'inactive' ? 'Archived' : 'Active';
    }

    public function householdStatusTone(string $status): string
    {
        return $status === 'inactive' ? 'neutral' : 'ok';
    }

    public function membershipStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'suspended' => 'Suspended',
            'pending' => 'Pending',
            'inactive' => 'Inactive',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function membershipStatusTone(string $status): string
    {
        return match ($status) {
            'active' => 'ok',
            'suspended' => 'danger',
            'pending' => 'warn',
            default => 'neutral',
        };
    }

    public function memberStatusLabel(string $status): string
    {
        return $status === 'inactive' ? 'Inactive' : 'Active';
    }

    public function memberStatusTone(string $status): string
    {
        return $status === 'inactive' ? 'neutral' : 'ok';
    }

    public function gateStatusLabel(string $status): string
    {
        return $status === 'inactive' ? 'Archived' : 'Active';
    }

    public function gateStatusTone(string $status): string
    {
        return $status === 'inactive' ? 'neutral' : 'ok';
    }

    public function iso(?string $utc): ?string
    {
        $moment = Clock::fromDb($utc);

        return $moment?->format('Y-m-d\TH:i:s.v\Z');
    }

    public function maskedPhone(?string $phone): string
    {
        $value = trim((string) $phone);

        return $value === '' ? '' : Phone::mask($value);
    }

    /**
     * Normalise the `?status=` filter to active|inactive|all (default active).
     */
    public function statusFilter(?string $requested): string
    {
        return in_array($requested, ['active', 'inactive', 'all'], true) ? (string) $requested : 'active';
    }

    /* ------------------------------------------------------------------ */
    /* Estate-scoped lookups (404 for anything foreign)                    */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function findBuilding(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, building_type, created_at
             FROM buildings WHERE estate_id = :estate_id AND uuid = :uuid LIMIT 1',
            ['estate_id' => $this->estateId(), 'uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('BUILDING_NOT_FOUND', 'That building is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function findHousehold(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT h.id, h.uuid, h.estate_id, h.building_id, h.unit_number, h.household_type, h.status,
                    h.created_at, b.uuid AS building_uuid, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.uuid = :uuid LIMIT 1',
            ['estate_id' => $this->estateId(), 'uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function findGate(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, location, status, created_at
             FROM gates WHERE estate_id = :estate_id AND uuid = :uuid LIMIT 1',
            ['estate_id' => $this->estateId(), 'uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('GATE_NOT_FOUND', 'That gate is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function findMembership(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT em.id, em.uuid, em.user_id, em.role_id, em.status, em.created_at,
                    u.uuid AS user_uuid, u.full_name, u.phone, u.status AS user_status,
                    r.name AS role_name
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id AND em.uuid = :uuid LIMIT 1',
            ['estate_id' => $this->estateId(), 'uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('RESIDENT_NOT_FOUND', 'That estate member is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function findGuardMembership(string $uuid): array
    {
        $row = $this->db->selectOne(
            "SELECT em.id, em.uuid, em.user_id, em.role_id, em.status, em.created_at,
                    u.uuid AS user_uuid, u.full_name, u.phone,
                    r.name AS role_name
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id AND em.uuid = :uuid AND r.name = 'security_guard' LIMIT 1",
            ['estate_id' => $this->estateId(), 'uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('GUARD_NOT_FOUND', 'That guard is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function findHouseholdMember(string $householdUuid, string $memberUuid): array
    {
        $household = $this->findHousehold($householdUuid);
        $row = $this->db->selectOne(
            'SELECT hm.id, hm.uuid, hm.user_id, hm.household_id, hm.relationship, hm.is_primary, hm.status,
                    u.uuid AS user_uuid, u.full_name, u.phone
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id AND hm.estate_id = :estate_id AND hm.uuid = :uuid LIMIT 1',
            ['household_id' => (int) $household['id'], 'estate_id' => $this->estateId(), 'uuid' => $memberUuid]
        );

        if ($row === null) {
            throw HttpException::notFound('MEMBER_NOT_FOUND', 'That household member is not available.');
        }

        return $row;
    }

    /* ------------------------------------------------------------------ */
    /* Selectors (active records only where the contract requires)        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildingsForSelect(): array
    {
        return $this->db->select(
            'SELECT uuid, name FROM buildings WHERE estate_id = :estate_id ORDER BY name ASC',
            ['estate_id' => $this->estateId()]
        );
    }

    /**
     * Active households only: archived households are excluded from selectors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function householdsForSelect(): array
    {
        $rows = $this->db->select(
            "SELECT h.uuid, h.unit_number, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.status = 'active'
             ORDER BY h.unit_number ASC",
            ['estate_id' => $this->estateId()]
        );

        return array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'unit_number' => (string) $row['unit_number'],
                'label' => trim((string) $row['unit_number']
                    . ((string) ($row['building_name'] ?? '') === '' ? '' : ' · ' . (string) $row['building_name'])),
            ],
            $rows
        );
    }

    /**
     * Gates for selectors. Archived gates are never offered for new assignments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gatesForSelect(): array
    {
        return $this->db->select(
            "SELECT uuid, name, status FROM gates
             WHERE estate_id = :estate_id AND status = 'active'
             ORDER BY name ASC",
            ['estate_id' => $this->estateId()]
        );
    }

    /**
     * @param array<int, string> $uuids
     * @return array<int, array<string, mixed>> Active estate gates matching the uuids.
     */
    public function activeGatesByUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, static fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')));

        if ($uuids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $rows = $this->db->select(
            "SELECT id, uuid, name FROM gates
             WHERE estate_id = ? AND status = 'active' AND uuid IN (" . $placeholders . ')
             ORDER BY name ASC',
            array_merge([$this->estateId()], $uuids)
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByPhone(string $phone): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, full_name, phone, status FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => $phone]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function membershipForUser(int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT em.id, em.uuid, em.role_id, em.status, r.name AS role_name
             FROM estate_memberships em
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id AND em.user_id = :user_id LIMIT 1',
            ['estate_id' => $this->estateId(), 'user_id' => $userId]
        );
    }

    public function roleId(string $name): ?int
    {
        $value = $this->db->scalar(
            "SELECT id FROM roles WHERE scope = 'estate' AND name = :name LIMIT 1",
            ['name' => $name]
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function audit(string $action, string $entityType, ?int $entityId = null, array $metadata = []): void
    {
        $this->context->audit->record($action, $entityType, $entityId, $metadata, $this->estateId());
    }

    /**
     * Validate input for a form and return only whitelisted fields.
     *
     * @param array<string, mixed> $input
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    public function validate(array $input, array $rules, array $labels = []): array
    {
        return \App\Core\Validator::validate($input, $rules, $labels);
    }
}
