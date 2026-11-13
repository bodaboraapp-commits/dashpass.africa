<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

/**
 * Shared shell data for the public marketing pages (About / Blog / Help).
 *
 * These pages are public (no auth) and render inside the guest layout so the
 * header nav (PASS · PAY · ABOUT · BLOG · HELP?) and the standard guest footer
 * are consistent with the landing page.
 */
trait BuildsGuestMarketingPage
{
    /**
     * @param array<string, mixed> $extra View-specific data (never overrides the shell keys here).
     */
    private function renderMarketingPage(string $view, string $title, string $description, array $extra = []): Response
    {
        $shell = [
            'title' => $title,
            'description' => $description,
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'guestNav' => true,
        ];

        return View::render($view, array_merge($shell, $extra), 'guest');
    }
}
