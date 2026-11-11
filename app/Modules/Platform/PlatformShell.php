<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Shared platform-portal shell + flash plumbing (T64 frozen shell keys).
 *
 * Keys added to every platform view: title, user {full_name, phone}, permissions
 * (platform slugs), csrfToken, appVersion, isDev, activeNav, platform_roles,
 * flash_success, flash_error. Page-specific frozen keys are merged by controllers.
 */
trait PlatformShell
{
    /**
     * @return array<string, mixed>
     */
    protected function shell(Context $context, string $title, string $activeNav): array
    {
        $user = $context->auth->user() ?? [];
        $access = PlatformAccess::make($context);
        $flashSuccess = Session::pullFlash('success');
        $flashError = Session::pullFlash('error');

        return [
            'title' => $title,
            'user' => [
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
            ],
            'permissions' => $access->permissions(),
            'platform_roles' => $access->roleNames(),
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'activeNav' => $activeNav,
            'flash_success' => is_string($flashSuccess) ? $flashSuccess : null,
            'flash_error' => is_string($flashError) ? $flashError : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data): Response
    {
        return View::render($view, $data, 'platform');
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function pullErrors(): array
    {
        $errors = Session::pullFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pullOld(): array
    {
        $old = Session::pullFlash('old', []);

        return is_array($old) ? $old : [];
    }

    protected function flashSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function flashError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function backTo(string $path): Response
    {
        return Response::redirect($path);
    }
}
