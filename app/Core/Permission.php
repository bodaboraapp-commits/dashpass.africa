<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Granular permission checks driven by role-to-permission rows.
 *
 * Authorisation never checks a role name directly (Blueprint §9); roles are
 * templates and every decision is made against a permission slug.
 */
final class Permission
{
    /** @var array<int, string>|null */
    private ?array $estateSlugs = null;

    /** @var array<int, string>|null */
    private ?array $platformSlugs = null;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function slugs(): array
    {
        if ($this->estateSlugs !== null) {
            return $this->estateSlugs;
        }

        $membership = $this->tenant->active();

        if ($membership === null) {
            return $this->estateSlugs = [];
        }

        $rows = $this->db->select(
            'SELECT p.slug
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
             ORDER BY p.slug ASC',
            ['role_id' => (int) $membership['role_id']]
        );

        return $this->estateSlugs = array_values(array_map(
            static fn (array $row): string => (string) $row['slug'],
            $rows
        ));
    }

    /**
     * @return array<int, string>
     */
    public function platformSlugs(): array
    {
        if ($this->platformSlugs !== null) {
            return $this->platformSlugs;
        }

        $roleIds = array_map(
            static fn (array $row): int => (int) $row['role_id'],
            $this->tenant->platformRoles()
        );

        if ($roleIds === []) {
            return $this->platformSlugs = [];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $rows = $this->db->select(
            'SELECT DISTINCT p.slug
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id IN (' . $placeholders . ')
             ORDER BY p.slug ASC',
            $roleIds
        );

        return $this->platformSlugs = array_values(array_map(
            static fn (array $row): string => (string) $row['slug'],
            $rows
        ));
    }

    public function can(string $slug): bool
    {
        return in_array($slug, $this->slugs(), true);
    }

    public function platformCan(string $slug): bool
    {
        return in_array($slug, $this->platformSlugs(), true);
    }

    /**
     * @throws HttpException 403 when the permission is missing.
     */
    public function authorize(string $slug): void
    {
        if ($this->can($slug)) {
            return;
        }

        Logger::security('Permission denied', [
            'permission' => $slug,
            'actor_id' => $this->tenant->active()['membership_id'] ?? null,
        ]);

        throw new HttpException(
            403,
            'PERMISSION_DENIED',
            'You do not have permission to perform this action.'
        );
    }

    /**
     * @throws HttpException 403 when the platform permission is missing.
     */
    public function authorizePlatform(string $slug): void
    {
        if ($this->platformCan($slug)) {
            return;
        }

        Logger::security('Platform permission denied', ['permission' => $slug]);

        throw new HttpException(
            403,
            'PERMISSION_DENIED',
            'You do not have permission to perform this action.'
        );
    }

    /**
     * Invalidate cached permissions after an estate switch.
     */
    public function reset(): void
    {
        $this->estateSlugs = null;
    }
}
