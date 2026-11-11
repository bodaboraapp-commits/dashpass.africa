<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Shared admin-page shell + flash plumbing.
 *
 * Key added to every admin view (T28 layout consumes these): title, user,
 * estate, activeNav, csrfToken, appVersion, isDev, permissions,
 * flash_success, flash_error. Page-specific keys come from the frozen T27↔T28
 * contract §7 and are merged by each controller.
 */
trait AdminShell
{
    /**
     * @return array<string, mixed>
     */
    private function shell(Context $context, string $title, string $activeNav): array
    {
        $user = $context->auth->user() ?? [];
        $flashSuccess = Session::pullFlash('success');
        $flashError = Session::pullFlash('error');

        return [
            'title' => $title,
            'user' => [
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
            ],
            'estate' => $context->tenant->active(),
            'activeNav' => $activeNav,
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'permissions' => $context->permission->slugs(),
            'flash_success' => is_string($flashSuccess) ? $flashSuccess : null,
            'flash_error' => is_string($flashError) ? $flashError : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $view, array $data): Response
    {
        return View::render($view, $data, 'admin');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pullErrors(): array
    {
        $errors = Session::pullFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullOld(): array
    {
        $old = Session::pullFlash('old', []);

        return is_array($old) ? $old : [];
    }

    private function flashSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    private function flashError(string $message): void
    {
        Session::flash('error', $message);
    }

    private function backTo(string $path): Response
    {
        return Response::redirect($path);
    }
}
