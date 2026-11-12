<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\ValidationException;
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
            'channel_status' => [
                'sms' => ['configured' => (bool) ($settings['notifications.channels.status']['sms_gateway_configured'] ?? false)],
                'whatsapp' => ['configured' => (bool) ($settings['notifications.channels.status']['whatsapp_gateway_configured'] ?? false)],
            ],
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        $data = $this->shell($context, 'Notification channels', 'settings') + $data;
        if (View::exists('platform/settings-notifications')) {
            return $this->render('platform/settings-notifications', $data);
        }

        return $this->page('Notification channels', 'Global channel availability', $data, '/platform/settings/notifications');
    }

    public function saveNotifications(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $raw = $request->input('channels', []);
        $raw = is_array($raw) ? $raw : [$raw];
        $allowed = ['email', 'in_app', 'sms', 'whatsapp'];

        foreach ($raw as $channel) {
            if (!is_string($channel) || !in_array($channel, $allowed, true)) {
                \App\Core\Session::flash('errors', ['channels' => ['Choose only email, in-app, SMS or WhatsApp.']]);
                \App\Core\Session::flash('old', $request->all());

                return Response::redirect('/platform/settings/notifications');
            }
        }

        $smsGateway = (string) \App\Core\Config::get('sms.driver', 'log') !== 'log'
            && (string) \App\Core\Config::get('sms.driver', 'mock') !== 'mock'
            && trim((string) (getenv('SMS_API_KEY') ?: '')) !== '';
        $whatsAppGateway = trim((string) (getenv('WHATSAPP_API_KEY') ?: '')) !== '';
        $channels = [
            'email' => true,
            'in_app' => true,
            'sms' => in_array('sms', $raw, true) && $smsGateway,
            'whatsapp' => in_array('whatsapp', $raw, true) && $whatsAppGateway,
        ];
        $this->putSetting($context, 'notifications.channels.available', $channels);
        $this->putSetting($context, 'notifications.channels.status', [
            'sms_gateway_configured' => $smsGateway,
            'whatsapp_gateway_configured' => $whatsAppGateway,
            'test_mode' => !$smsGateway && !$whatsAppGateway,
        ]);
        $this->auditSettings($context, 'notifications.channels.available');

        return Response::redirect('/platform/settings/notifications');
    }

    public function payments(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $settings = $this->settings($context);

        $providers = is_array($settings['payments.providers'] ?? null) ? $settings['payments.providers'] : [];
        $ttl = (int) ($settings['payments.links.ttl_minutes'] ?? 1440);
        $data = $this->shell($context, 'Payment providers', 'settings') + [
            'payments' => $providers + [
                'mpesa' => ['enabled' => false, 'mode' => 'test'],
                'card' => ['enabled' => false, 'mode' => 'test'],
                'status' => $settings['payments.providers.status'] ?? [],
                'link_expiry_days' => max(1, min(7, (int) ceil($ttl / 1440))),
            ],
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];
        if (View::exists('platform/settings-payments')) {
            return $this->render('platform/settings-payments', $data);
        }

        return $this->page('Payment providers', 'Provider, mode and payment-link settings', $data, '/platform/settings/payments');
    }

    public function savePayments(Request $request, array $params, Context $context): Response
    {
        $this->requireSuperAdmin($context);
        $provider = trim((string) $request->input('provider', ''));
        $mode = trim((string) $request->input('mode', 'test'));
        $methods = $request->input('methods', $request->input('provider', null));
        $methods = is_array($methods) ? $methods : ($methods === null ? [] : [$methods]);
        $daysRaw = $request->input('link_expiry_days', $request->input('link_expiry_hours', 24));
        $errors = [];

        if ($provider !== '' && !in_array($provider, ['mpesa', 'card'], true)) {
            $errors['provider'] = ['Choose M-Pesa or card.'];
        }

        if (!in_array($mode, ['test', 'live'], true)) {
            $errors['mode'] = ['Mode must be test or live.'];
        }

        foreach ($methods as $method) {
            if (!in_array($method, ['mpesa', 'card'], true)) {
                $errors['methods'] = ['Choose only M-Pesa or card.'];
            }
        }

        if (filter_var($daysRaw, FILTER_VALIDATE_INT) === false) {
            $errors['link_expiry_days'] = ['Link expiry must be a whole number of days.'];
        } else {
            $days = (int) $daysRaw;
            // Input may be days (1–7) or hours (1–168); normalise both.
            $ttlMinutes = $days <= 7 ? $days * 1440 : $days * 60;
            if ($ttlMinutes < 5 || $ttlMinutes > 10080) {
                $errors['link_expiry_days'] = ['Link expiry must be between 5 minutes and 7 days.'];
            }
        }

        if ($errors !== []) {
            \App\Core\Session::flash('errors', $errors);
            \App\Core\Session::flash('old', $request->all());

            return Response::redirect('/platform/settings/payments');
        }

        $settings = $this->settings($context);
        $providers = is_array($settings['payments.providers'] ?? null) ? $settings['payments.providers'] : [];
        $previous = is_array($settings['payments.providers'] ?? null) ? $settings['payments.providers'] : [];
        $enabledMethods = array_values(array_unique(array_intersect($methods, ['mpesa', 'card'])));
        $providers = [
            'mpesa' => [
                'enabled' => in_array('mpesa', $enabledMethods, true),
                'mode' => $provider === 'mpesa' || $provider === '' ? $mode : (string) ($previous['mpesa']['mode'] ?? $mode),
            ],
            'card' => [
                'enabled' => in_array('card', $enabledMethods, true),
                'mode' => $provider === 'card' || $provider === '' ? $mode : (string) ($previous['card']['mode'] ?? $mode),
            ],
        ];
        $this->putSetting($context, 'payments.providers', $providers);
        $this->putSetting($context, 'payments.providers.status', [
            'mpesa_configured' => trim((string) (getenv('MPESA_CONSUMER_SECRET') ?: '')) !== '',
            'card_configured' => trim((string) (getenv('CARD_PROVIDER_SECRET') ?: '')) !== '',
            'test_mode' => $mode === 'test',
        ]);
        // Single authoritative key consumed by PaymentLinkService::ttlMinutes().
        $this->putSetting($context, 'payments.links.ttl_minutes', $ttlMinutes);
        $this->auditSettings($context, 'payments.providers');

        return Response::redirect('/platform/settings/payments');
    }

    private function auditSettings(Context $context, string $key): void
    {
        try {
            $context->audit->record('billing.settings_updated', 'platform_settings', null, ['key' => $key]);
        } catch (\Throwable) {
            // Persisting settings must not fail solely because audit failed.
        }
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

        $context->db->execute(
            'INSERT INTO platform_settings (`uuid`, `key`, `value`, `updated_by_user_id`, `created_at`, `updated_at`)
             VALUES (:uuid, :key, :value, :updated_by, :created_at, :updated_at)',
            [
                'uuid' => Uuid::v4(),
                'key' => $key,
                'value' => $encoded,
                'updated_by' => $context->auth->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
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
