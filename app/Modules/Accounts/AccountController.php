<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Notifications\WhatsAppBroadcastService;
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

        // Owner request (2026-11-15): residents can opt out of WhatsApp broadcasts.
        $data['whatsapp_opted_out'] = false;
        try {
            $user = $context->auth->user();
            $estateId = $context->tenant->estateId();
            if (is_array($user) && $estateId !== null) {
                $data['whatsapp_opted_out'] = WhatsAppBroadcastService::make($context)
                    ->isUserOptedOut((int) $estateId, (int) $user['id']);
            }
        } catch (\Throwable) {
            // Pre-migration deployments simply do not show the preference.
        }

        return View::render('app/account', $data, 'app');
    }

    /**
     * Opt in/out of WhatsApp broadcasts from the Account screen (owner request
     * 2026-11-15). Toggles the resident's preference and returns to Account.
     */
    public function toggleWhatsAppOptOut(Request $request, array $params, Context $context): Response
    {
        try {
            $optedOut = WhatsAppBroadcastService::make($context)->toggleResidentOptOut();
            Session::flash('success', $optedOut
                ? 'You are opted out of WhatsApp broadcasts.'
                : 'You are opted back in to WhatsApp broadcasts.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/account');
    }
}
