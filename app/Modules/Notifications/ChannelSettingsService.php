<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Config;
use App\Core\Context;

/**
 * Channel availability resolver (Inc-13 P2).
 *
 * Global: email + in-app are always available; SMS/WhatsApp only when a real
 * gateway is configured via env. Estate: may select a subset of globally
 * available channels (email/in-app always remain available).
 */
final class ChannelSettingsService
{
    public const CHANNELS = ['email', 'in_app', 'sms', 'whatsapp'];

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array<string,bool>
     */
    public function globalChannels(): array
    {
        $stored = $this->platformSetting('notifications.channels.available', []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'email' => true,
            'in_app' => true,
            'sms' => (bool) ($stored['sms'] ?? false) && $this->smsGatewayConfigured(),
            'whatsapp' => (bool) ($stored['whatsapp'] ?? false) && $this->whatsappGatewayConfigured(),
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function estateChannels(int $estateId): array
    {
        $global = $this->globalChannels();
        $row = $estateId <= 0 ? null : $this->context->db->selectOne(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $estateId, 'key' => 'billing.notification_channels']
        );
        $selected = $row === null ? null : json_decode((string) $row['setting_value'], true);
        $selected = is_array($selected) ? array_values(array_filter($selected, 'is_string')) : null;
        $channels = [
            'email' => true,
            'in_app' => true,
            'sms' => $global['sms'],
            'whatsapp' => $global['whatsapp'] || $this->whatsappEstateConfigured($estateId),
        ];

        if ($selected !== null) {
            foreach (['sms', 'whatsapp'] as $channel) {
                $channels[$channel] = $channels[$channel] && in_array($channel, $selected, true);
            }
        }

        return $channels;
    }

    /**
     * Estate-level WhatsApp Cloud API credentials (owner request 2026-11-13):
     * an Estate Admin can enable WhatsApp for their estate without global env
     * credentials. Global env credentials still enable the channel everywhere.
     */
    public function whatsappEstateConfigured(int $estateId): bool
    {
        return $estateId > 0 && WhatsAppSettingsService::make($this->context)->isConfigured($estateId);
    }

    public function canSend(int $estateId, string $channel): bool
    {
        return (bool) ($this->estateChannels($estateId)[$channel] ?? false);
    }

    public function skipReason(string $channel): string
    {
        return match ($channel) {
            'sms' => 'sms_not_configured',
            'whatsapp' => 'whatsapp_not_configured',
            'email' => 'email_not_configured',
            default => $channel . '_disabled',
        };
    }

    public function smsGatewayConfigured(): bool
    {
        $driver = strtolower((string) Config::get('sms.driver', 'log'));

        return !in_array($driver, ['log', 'mock'], true)
            && trim((string) (getenv('SMS_API_KEY') ?: '')) !== '';
    }

    public function whatsappGatewayConfigured(): bool
    {
        return trim((string) (getenv('WHATSAPP_API_KEY') ?: '')) !== ''
            || trim((string) (getenv('WHATSAPP_GATEWAY') ?: '')) !== '';
    }

    private function platformSetting(string $key, mixed $default = null): mixed
    {
        $row = $this->context->db->selectOne('SELECT `value` FROM platform_settings WHERE `key` = :key LIMIT 1', ['key' => $key]);

        if ($row === null) {
            return $default;
        }

        $decoded = json_decode((string) $row['value'], true);

        return $decoded === null ? $row['value'] : $decoded;
    }
}
