<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Platform\PlatformAccess;
use App\Modules\Shared\BuildsShell;

/**
 * Account screen: identity, phone and active estate context.
 *
 * Also exposes the platform-operator hooks for the account view (`platform_access`,
 * `platform_permissions`, `user.platform_access`) so the "Platform console" link is
 * visible to active platform memberships without leaking platform permissions into
 * the estate-scoped `permissions` payload or middleware.
 */
final class AccountController
{
    use BuildsShell;

    public function webAccount(Request $request, array $params, Context $context): Response
    {
        $data = $this->shell($context, 'Account', 'account');
        $platformAccess = PlatformAccess::userHasPlatformAccess($context);
        $platformPermissions = PlatformAccess::platformPermissionsFor($context);

        $data['platform_access'] = $platformAccess;
        $data['platform_permissions'] = $platformPermissions;
        $data['user'] = is_array($data['user'] ?? null) ? $data['user'] : [];
        $data['user']['platform_access'] = $platformAccess;

        return View::render('app/account', $data, 'app');
    }
}
