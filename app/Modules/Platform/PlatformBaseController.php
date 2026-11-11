<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;

/**
 * Base controller for the platform (super-admin) portal.
 *
 * Deliberately NOT the estate admin gate: platform users have no estate tenant
 * context. Route middleware will be `['auth']` and each handler enforces the
 * platform area gate + the specific `platform.*` permission (the estate
 * `permission:` middleware cannot authorise platform slugs).
 */
abstract class PlatformBaseController
{
    use PlatformShell;

    protected function access(Context $context): PlatformAccess
    {
        return PlatformAccess::make($context);
    }

    /**
     * At least one `platform.*` permission is required (403 otherwise).
     */
    protected function requirePlatformArea(Context $context): void
    {
        $this->access($context)->requireArea();
    }

    protected function requirePlatformPermission(Context $context, string $permission): void
    {
        $this->access($context)->requirePermission($permission);
    }
}
