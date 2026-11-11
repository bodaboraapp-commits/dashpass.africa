<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\View;
use App\Modules\Platform\PlatformBaseController;

/**
 * Super-admin global billing settings (Inc-13 contract §2.4/§8).
 *
 * Non-secret settings only (platform_settings); provider credentials stay in env.
 * Reuses the platform area gate; global settings are super-admin only.
 */
final class PlatformBillingSettingsController extends PlatformBaseController
{
    private const SUPER_ADMIN_ROLE = 'platform_super_admin';

    public function notifications(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $settings = $this->settings($context);
        $data = [
            'channels' => $settings['notifications.channels.available'] ?? [
                'email' => true,
                'in_app' => true,
                'sms' => false,
                'whatsapp' => false,
            ],
            'sms_driver' => (string) \App\Core\Config::get('sms.driver', 'log'),
        ];

        return $this->page('Notification channels', 'Global channel availability', $data, '/platform/settings/notifications');
    }

    public function saveNotifications(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $channels = [
            // Email and in-app are always available and cannot be disabled here.
            'email' => true,
            'in_app' => true,
            // Optional channels only become available when a real gateway is configured.
            'sms' => $request->input('sms') !== null && (string) \App\Core\Config::get('sms.driver', 'log') !== 'log',
            'whatsapp' => $request->input('whatsapp') !== null,
        ];
        $this->putSetting($context, 'notifications.channels.available', $channels);

        return Response::redirect('/platform/settings/notifications');
    }

    public function payments(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $settings = $this->settings($context);

        return $this->page('Payment providers', 'Provider, mode and payment-link settings', [
            'payments' => $settings['payments.providers'] ?? [
                'mpesa' => ['enabled' => false, 'mode' => 'test'],
                'card' => ['enabled' => false, 'mode' => 'test'],
                'link_expiry_hours' => 24,
            ],
        ], '/platform/settings/payments');
    }

    public function savePayments(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $mode = (string) $request->input('mode', 'test');
        $mode = in_array($mode, ['test', 'live', 'disabled'], true) ? $mode : 'test';
        $expiry = (int) $request->input('link_expiry_hours', 24);
        $expiry = max(1, min(168, $expiry));
        $this->putSetting($context, 'payments.providers', [
            'mpesa' => [
                'enabled' => $request->input('mpesa_enabled') !== null,
                'mode' => $mode,
            ],
            'card' => [
                'enabled' => $request->input('card_enabled') !== null,
                'mode' => $mode,
            ],
            'link_expiry_hours' => $expiry,
        ]);

        return Response::redirect('/platform/settings/payments');
    }

    private function requireSuperAdmin(Context $context): void
    {
        $this->requirePlatformArea($context);

        if (!in_array(self::SUPER_ADMIN_ROLE, $this->access($context)->roleNames(), true)) {
            throw HttpException::forbidden('PERMISSION_DENIED', 'Global billing settings require a platform super admin.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Context $context): array
    {
        $rows = $context->db->select('SELECT `key`, `value` FROM platform_settings');
        $settings = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $settings[(string) $row['key']] = is_array($decoded) ? $decoded : $row['value'];
        }

        return $settings;
    }

    private function putSetting(Context $context, string $key, mixed $value): void
    {
        $existing = $context->db->selectOne('SELECT id FROM platform_settings WHERE `key` = :k LIMIT 1', ['k' => $key]);
        $now = \App\Core\Clock::nowDb();
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($existing !== null) {
            $context->db->update('platform_settings', [
                'value' => $encoded,
                'updated_by_user_id' => $context->auth->id(),
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);

            return;
        }

        $context->db->insert('platform_settings', [
            'uuid' => Uuid::v4(),
            'key' => $key,
            'value' => $encoded,
            'updated_by_user_id' => $context->auth->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $title, string $lede, array $data, string $action): Response
    {
        $rows = '';

        foreach ($data as $key => $value) {
            $rows .= '<section><h2>' . e(ucwords(str_replace('_', ' ', (string) $key))) . '</h2><pre>'
                . e((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                . '</pre></section>';
        }

        return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title></head>'
            . '<body><main><h1>' . e($title) . '</h1><p>' . e($lede) . '</p>' . $rows
            . '<form method="post" action="' . e($action) . '">' . csrf_field()
            . '<button type="submit">Save</button></form>'
            . '<p><a href="/platform">Back to platform</a></p></main></body></html>');
    }
}
