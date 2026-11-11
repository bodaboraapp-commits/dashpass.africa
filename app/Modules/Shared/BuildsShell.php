<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Core\Context;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Modules\Notifications\NotificationService;

/**
 * Builds the shared web shell data required by the app layout (contract §8.2).
 *
 * Kept in one place so every authenticated page exposes the same identity,
 * estate, CSRF and flash state to the PWA views.
 */
trait BuildsShell
{
    /**
     * @return array<string, mixed>
     */
    private function shell(Context $context, string $title, string $activeNav): array
    {
        $user = $context->auth->user() ?? [];
        $flashError = Session::pullFlash('error');
        $flashSuccess = Session::pullFlash('success');
        $estate = $context->tenant->active();
        $appearance = 'default';

        if (is_array($estate) && isset($estate['estate_id'])) {
            $row = $context->db->selectOne(
                'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
                ['estate_id' => (int) $estate['estate_id'], 'key' => 'ui.appearance_default']
            );
            $decoded = $row === null ? null : json_decode((string) $row['setting_value'], true);
            $appearance = in_array($decoded, ['default', 'light', 'dark'], true) ? $decoded : 'default';
        }

        return [
            'title' => $title,
            'appearance_default' => $appearance,
            'user' => [
                'uuid' => (string) ($user['uuid'] ?? ''),
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
                'email' => $user['email'] ?? null,
            ],
            'estate' => $context->tenant->active(),            'memberships' => $context->tenant->memberships(),
            'activeNav' => $activeNav,
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'flashError' => is_string($flashError) ? $flashError : null,
            'flashSuccess' => is_string($flashSuccess) ? $flashSuccess : null,
            'permissions' => $context->permission->slugs(),
        ] + NotificationService::make($context)->shellData()
          + NotificationService::make($context)->incidentSirenData();
    }
}
