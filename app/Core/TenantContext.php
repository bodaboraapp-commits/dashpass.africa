<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tenant context resolver.
 *
 * The active estate is always derived from the authenticated user's active
 * memberships. A browser-supplied estate id is never trusted; switching estate
 * requires a membership that exists in the database for this user.
 */
final class TenantContext
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $memberships = null;

    /** @var array<string, mixed>|null */
    private ?array $active = null;

    private bool $activeResolved = false;

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth
    ) {
    }

    /**
     * Active estate memberships for the current user, joined with estate and
     * role metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function memberships(): array
    {
        if ($this->memberships !== null) {
            return $this->memberships;
        }

        $user = $this->auth->user();

        if ($user === null) {
            return $this->memberships = [];
        }

        return $this->memberships = $this->db->select(
            'SELECT em.id AS membership_id,
                    em.estate_id,
                    em.role_id,
                    em.status AS membership_status,
                    em.created_at AS membership_created_at,
                    e.uuid AS estate_uuid,
                    e.name AS estate_name,
                    e.slug AS estate_slug,
                    e.estate_type,
                    e.timezone AS estate_timezone,
                    e.status AS estate_status,
                    r.name AS role_name,
                    r.scope AS role_scope
             FROM estate_memberships em
             INNER JOIN estates e ON e.id = em.estate_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.user_id = :user_id
               AND em.status = :membership_status
               AND e.status = :estate_status
             ORDER BY em.created_at ASC, em.id ASC',
            [
                'user_id' => (int) $user['id'],
                'membership_status' => 'active',
                'estate_status' => 'active',
            ]
        );
    }

    /**
     * Platform-scope roles (support, operations, finance, super admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public function platformRoles(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            return [];
        }

        return $this->db->select(
            'SELECT pm.id AS membership_id, pm.role_id, pm.status AS membership_status, r.name AS role_name, r.scope
             FROM platform_memberships pm
             INNER JOIN roles r ON r.id = pm.role_id
             WHERE pm.user_id = :user_id
               AND pm.status = :status
             ORDER BY pm.id ASC',
            ['user_id' => (int) $user['id'], 'status' => 'active']
        );
    }

    public function hasEstateAccess(int $estateId): bool
    {
        foreach ($this->memberships() as $membership) {
            if ((int) $membership['estate_id'] === $estateId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The estate context for this request. Defaults to the first membership in
     * the session so single-estate residents never see a picker.
     *
     * @return array<string, mixed>|null
     */
    public function active(): ?array
    {
        if ($this->activeResolved) {
            return $this->active;
        }

        $this->activeResolved = true;
        $memberships = $this->memberships();

        if ($memberships === []) {
            return $this->active = null;
        }

        $sessionEstateId = Session::get('estate_id');

        if ($sessionEstateId !== null) {
            foreach ($memberships as $membership) {
                if ((int) $membership['estate_id'] === (int) $sessionEstateId) {
                    return $this->active = $membership;
                }
            }

            // The session pointed at an estate the user no longer belongs to.
            Session::forget('estate_id');
        }

        $first = $memberships[0];
        Session::put('estate_id', (int) $first['estate_id']);

        return $this->active = $first;
    }

    public function estateId(): ?int
    {
        $active = $this->active();

        return $active === null ? null : (int) $active['estate_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function requireEstate(): array
    {
        $active = $this->active();

        if ($active === null) {
            throw new HttpException(
                403,
                'ESTATE_CONTEXT_REQUIRED',
                'Your account does not have access to an active estate. Contact your estate administrator.'
            );
        }

        return $active;
    }

    public function roleName(): ?string
    {
        return $this->active()['role_name'] ?? null;
    }

    /**
     * Switch the active estate. Only estates the user is an active member of
     * are visible; an unknown or unauthorised estate is reported as not found
     * so estate identifiers cannot be enumerated.
     *
     * @return array<string, mixed>
     */
    public function switchTo(string $estateUuid): array
    {
        if (!Uuid::isValid($estateUuid)) {
            throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available for your account.');
        }

        foreach ($this->memberships() as $membership) {
            if (hash_equals((string) $membership['estate_uuid'], $estateUuid)) {
                Session::put('estate_id', (int) $membership['estate_id']);
                $this->active = $membership;
                $this->activeResolved = true;

                return $membership;
            }
        }

        throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available for your account.');
    }

    /**
     * Guard against cross-tenant access through a request parameter: the
     * requested tenant must be the resolved active tenant.
     */
    public function assertScope(int $requestedEstateId): int
    {
        $activeEstateId = $this->estateId();

        if ($activeEstateId === null || $requestedEstateId !== $activeEstateId) {
            Logger::security('Cross-tenant access attempt blocked', [
                'requested_estate_id' => $requestedEstateId,
                'active_estate_id' => $activeEstateId,
                'actor_id' => $this->auth->id(),
            ]);

            throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available for your account.');
        }

        return $activeEstateId;
    }

    public function clear(): void
    {
        Session::forget('estate_id');
        $this->memberships = null;
        $this->active = null;
        $this->activeResolved = false;
    }
}
