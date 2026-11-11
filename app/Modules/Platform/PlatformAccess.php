<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;

/**
 * Platform (super-admin) access helpers.
 *
 * Platform users are NOT estate users: authorisation uses the platform permission
 * namespace (`Permission::platformSlugs()` / `platformCan()`, backed by active
 * platform memberships), never the estate tenant context or admin-area gate.
 * Every platform mutation is audited with `estate_id = NULL` (platform scope) and
 * uuid/code/status metadata only.
 */
final class PlatformAccess
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

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->context->permission->platformSlugs();
    }

    /**
     * @return array<int, array<string, mixed>> Active platform memberships + role names.
     */
    public function roles(): array
    {
        return $this->context->tenant->platformRoles();
    }

    /**
     * @return array<int, string>
     */
    public function roleNames(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['role_name'],
            $this->roles()
        ));
    }

    public function can(string $permission): bool
    {
        return $this->context->permission->platformCan($permission);
    }

    /**
     * Platform area gate: the caller must hold at least one `platform.*`
     * permission (active platform membership + role grants).
     *
     * @throws HttpException 403 when the caller is not a platform operator.
     */
    public function requireArea(): void
    {
        if ($this->permissions() !== []) {
            return;
        }

        Logger::security('Platform area denied', ['actor_id' => $this->context->auth->id()]);

        throw new HttpException(
            403,
            'PERMISSION_DENIED',
            'You do not have permission to access the platform console.'
        );
    }

    /**
     * @throws HttpException 403 when the platform permission is missing.
     */
    public function requirePermission(string $permission): void
    {
        $this->context->permission->authorizePlatform($permission);
    }

    /**
     * True when the authenticated user holds an active platform membership with at
     * least one platform permission (the platform-area gate). Used by the estate
     * account screen to expose the "Platform console" link without leaking platform
     * permissions into the estate permission namespace.
     */
    public static function userHasPlatformAccess(Context $context): bool
    {
        if ($context->tenant->platformRoles() === []) {
            return false;
        }

        return $context->permission->platformSlugs() !== [];
    }

    /**
     * Platform permission slugs for the authenticated user (empty for non-operators).
     *
     * @return array<int, string>
     */
    public static function platformPermissionsFor(Context $context): array
    {
        if ($context->tenant->platformRoles() === []) {
            return [];
        }

        return $context->permission->platformSlugs();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->context->auth->user();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function audit(string $action, string $entityType, ?int $entityId = null, array $metadata = []): void
    {
        $this->context->audit->record($action, $entityType, $entityId, $metadata, null);
    }

    public function database(): Database
    {
        return $this->db;
    }
}
