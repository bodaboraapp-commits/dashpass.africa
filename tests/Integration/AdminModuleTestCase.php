<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use App\Core\Session;

/**
 * Shared helpers for the estate/admin management portal tests
 * (docs/estate-admin-management-contract.md §1–§2, §7).
 *
 * Admin is web-form only (no JSON APIs this increment), so helpers deal with
 * rendered HTML pages, redirects (PRG) and flash/validation errors.
 */
abstract class AdminModuleTestCase extends VisitorModuleTestCase
{
    protected const MANAGER = '+254700000002';       // estate_manager, Greenview
    protected const SUPERVISOR = '+254700000003';    // security_supervisor
    protected const PARKING_MANAGER = '+254700000008'; // parking_manager
    protected const AUDITOR = '+254700000009';       // auditor

    /* ------------------------------------------------------------------ */
    /* Requests                                                           */
    /* ------------------------------------------------------------------ */

    protected function adminGet(string $path, string $phone = self::GRACE): Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->get($path);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function adminPost(string $path, array $body, string $phone = self::GRACE, bool $csrf = true): Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->post($path, $body, $csrf);
    }

    /**
     * Assert an admin mutation was rejected. Admin controllers are web forms:
     * a validation failure is either an inline 422 page or a PRG redirect whose
     * next page renders the flash error. Accept either, and require a marker.
     *
     * @param array<int, string> $markers
     */
    protected function assertAdminRejected(Response $response, string $formPath, array $markers): void
    {
        if ($response->status() === 422) {
            foreach ($markers as $marker) {
                if (str_contains($response->body(), $marker)) {
                    return;
                }
            }

            $this->fail('The 422 response must contain one of: ' . implode(', ', $markers));
        }

        if ($response->status() === 302) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $formPath);

            foreach ($markers as $marker) {
                if (str_contains($follow->body(), $marker)) {
                    return;
                }
            }

            $this->fail('The redirected page must render one of: ' . implode(', ', $markers) . ' (got ' . $location . ').');
        }

        $this->fail('Expected an admin validation rejection (422 or PRG 302), got HTTP ' . $response->status() . '.');
    }

    /**
     * A successful admin POST (PRG redirect to a page).
     */
    protected function assertAdminRedirect(Response $response, ?string $prefix = null): void
    {
        $this->assertStatus(302, $response, 'A successful admin form POST must redirect (PRG).');

        if ($prefix !== null) {
            $this->assertContains($prefix, (string) $response->header('Location'), 'Unexpected admin redirect target.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    protected function seededBuilding(string $name = 'Block A'): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, building_type FROM buildings WHERE estate_id = :estate_id AND name = :name LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'name' => $name]
        );

        if ($row === null) {
            $this->fail('Seeded building "' . $name . '" not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function seededHouseholdRow(string $unit = 'A-001'): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, estate_id, building_id, unit_number, household_type, status
             FROM households WHERE estate_id = :estate_id AND unit_number = :unit LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'unit' => $unit]
        );

        if ($row === null) {
            $this->fail('Seeded household ' . $unit . ' not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function membershipRow(string $phone, string $estateSlug = self::ESTATE_SLUG): array
    {
        $row = $this->db->selectOne(
            'SELECT m.id, m.uuid, m.status, u.id AS user_id, u.full_name, u.phone
             FROM estate_memberships m
             INNER JOIN users u ON u.id = m.user_id
             INNER JOIN estates e ON e.id = m.estate_id
             WHERE u.phone = :phone AND e.slug = :slug
             ORDER BY m.id DESC LIMIT 1',
            ['phone' => \App\Core\Phone::normalize($phone), 'slug' => $estateSlug]
        );

        if ($row === null) {
            $this->fail('Membership for ' . $phone . ' @ ' . $estateSlug . ' not found.');
        }

        return $row;
    }

    protected function roleId(string $slug): int
    {
        return (int) $this->db->scalar('SELECT id FROM roles WHERE name = :slug LIMIT 1', ['slug' => $slug]);
    }

    protected function activeHouseholdCount(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM households WHERE estate_id = :estate_id AND status = 'active'",
            ['estate_id' => (int) $this->estate()['id']]
        );
    }

    /**
     * Remember a session flash value if present (admin PRG tests only).
     */
    protected function pullFlash(string $key): mixed
    {
        return Session::pullFlash($key);
    }
}
