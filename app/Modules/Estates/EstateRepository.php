<?php

declare(strict_types=1);

namespace App\Modules\Estates;

use App\Core\Database;

/**
 * Estate-scoped read model and payload shaping.
 *
 * Every query is filtered by an estate id that the caller obtained from the
 * resolved tenant context; no method accepts a browser-supplied estate id.
 */
final class EstateRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * Public membership payload used by `/auth/me`, `/estates` and the dashboard.
     *
     * @param array<string, mixed> $membership TenantContext::memberships() row.
     * @return array<string, mixed>
     */
    public static function membershipShape(array $membership, ?int $activeEstateId = null): array
    {
        return [
            'estate_uuid' => (string) $membership['estate_uuid'],
            'estate_name' => (string) $membership['estate_name'],
            'estate_slug' => (string) $membership['estate_slug'],
            'estate_type' => (string) $membership['estate_type'],
            'estate_timezone' => (string) $membership['estate_timezone'],
            'role_name' => (string) $membership['role_name'],
            'is_active' => $activeEstateId !== null
                && (int) $membership['estate_id'] === $activeEstateId,
        ];
    }

    /**
     * @param array<string, mixed> $membership TenantContext::active() row.
     * @return array<string, mixed>
     */
    public static function activeEstateShape(array $membership): array
    {
        return [
            'estate_uuid' => (string) $membership['estate_uuid'],
            'estate_name' => (string) $membership['estate_name'],
            'estate_slug' => (string) $membership['estate_slug'],
            'estate_type' => (string) $membership['estate_type'],
            'estate_timezone' => (string) $membership['estate_timezone'],
            'role_name' => (string) $membership['role_name'],
        ];
    }

    /**
     * Estate summary shape (contract §8.1).
     *
     * @param array<string, mixed> $membership TenantContext::active() row.
     * @return array<string, mixed>
     */
    public function summary(array $membership): array
    {
        $estateId = (int) $membership['estate_id'];
        $counts = $this->counts($estateId);
        $plan = $this->planFor($estateId);

        return [
            'estate_uuid' => (string) $membership['estate_uuid'],
            'estate_name' => (string) $membership['estate_name'],
            'estate_slug' => (string) $membership['estate_slug'],
            'estate_type' => (string) $membership['estate_type'],
            'estate_timezone' => (string) $membership['estate_timezone'],
            'role_name' => (string) $membership['role_name'],
            'gate_count' => $counts['gates'],
            'household_count' => $counts['households'],
            'member_count' => $counts['members'],
            'plan_name' => $plan['plan_name'] ?? null,
            'subscription_status' => $plan['subscription_status'] ?? null,
        ];
    }

    /**
     * Decoded estate settings map.
     *
     * @return array<string, mixed>
     */
    public function settings(int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT setting_key, setting_value
             FROM estate_settings
             WHERE estate_id = :estate_id
             ORDER BY setting_key ASC',
            ['estate_id' => $estateId]
        );

        $settings = [];

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $raw = $row['setting_value'];

            if ($raw === null) {
                $settings[$key] = null;

                continue;
            }

            $decoded = json_decode((string) $raw, true);
            $settings[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $raw;
        }

        return $settings;
    }

    /**
     * Gates visible (read-only) for the estate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gates(int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT name, status
             FROM gates
             WHERE estate_id = :estate_id
             ORDER BY name ASC',
            ['estate_id' => $estateId]
        );

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ],
            $rows
        );
    }

    /**
     * @return array{households: int, buildings: int, gates: int, members: int}
     */
    public function counts(int $estateId): array
    {
        return [
            'households' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM households WHERE estate_id = :estate_id',
                ['estate_id' => $estateId]
            ),
            'buildings' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM buildings WHERE estate_id = :estate_id',
                ['estate_id' => $estateId]
            ),
            'gates' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM gates WHERE estate_id = :estate_id',
                ['estate_id' => $estateId]
            ),
            'members' => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM household_members WHERE estate_id = :estate_id AND status = 'active'",
                ['estate_id' => $estateId]
            ),
        ];
    }

    /**
     * @return array{plan_name: ?string, subscription_status: ?string}|null
     */
    public function planFor(int $estateId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT p.name AS plan_name, s.status AS subscription_status
             FROM subscriptions s
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.estate_id = :estate_id
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 1',
            ['estate_id' => $estateId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'plan_name' => $row['plan_name'] === null ? null : (string) $row['plan_name'],
            'subscription_status' => $row['subscription_status'] === null ? null : (string) $row['subscription_status'],
        ];
    }
}
