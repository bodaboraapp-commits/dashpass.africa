<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;

/**
 * Shared base for every admin controller.
 *
 * Route middleware stays exactly as the frozen contract §1 requires, but every
 * `/admin*` action additionally passes the admin-area gate: the caller must hold
 * at least one management permission. This keeps seeded residents (who hold
 * estate.view/household.view/household.member.manage) out of the estate-wide
 * admin lists while still letting `estate_manager` in through household/guard
 * management and keeping it bounded by each route's own permission.
 */
abstract class AdminBaseController
{
    use AdminShell;

    /** @var array<int, string> */
    private const ADMIN_AREA_PERMISSIONS = [
        'household.manage',
        'estate.user.manage',
        'guard.manage',
        'gate.manage',
        'estate.settings.manage',
    ];

    protected function requireAdminArea(Context $context): void
    {
        foreach (self::ADMIN_AREA_PERMISSIONS as $slug) {
            if ($context->permission->can($slug)) {
                return;
            }
        }

        throw new HttpException(
            403,
            'PERMISSION_DENIED',
            'You do not have permission to perform this action.'
        );
    }

    /**
     * Household membership mutations are an admin/manager action; the seeded
     * resident role also holds household.member.manage, so require a broader
     * management permission as well (defence in depth).
     */
    protected function requireMemberManagement(Context $context): void
    {
        if ($context->permission->can('household.manage') || $context->permission->can('estate.user.manage')) {
            return;
        }

        throw new HttpException(
            403,
            'PERMISSION_DENIED',
            'You do not have permission to perform this action.'
        );
    }
}
