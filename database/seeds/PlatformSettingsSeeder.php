<?php

declare(strict_types=1);

namespace Database\Seeds;

/**
 * Seeds the Increment-13 global (super-admin) configuration defaults.
 *
 * Non-secret only: notification channel availability, reminder defaults, payment
 * provider enablement/mode, payment-link TTL, automation kill-switch and default invoice
 * status. Provider credentials/secrets stay in `.env`. Idempotent by `key` / stable UUID:
 * existing values are refreshed only when they differ (repeated runs are no-ops).
 */
final class PlatformSettingsSeeder extends Seeder
{
    public const UUID_PREFIX = '8a000001-0000-4000-8000-';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'platform_settings_created',
        'fixtures_updated',
    ];

    /**
     * Frozen Inc-13 global defaults (non-secret). Keys are namespaced.
     *
     * @var array<string, mixed>
     */
    private const SETTINGS = [
        'notifications.channels.available' => [
            'email' => true,
            'in_app' => true,
            'sms' => false,
            'whatsapp' => false,
        ],
        'notifications.reminder.defaults' => [
            'days_before' => 3,
            'on_due' => true,
            'past_due' => [7, 14, 30],
        ],
        'payments.providers' => [
            'mpesa' => ['enabled' => false, 'mode' => 'test'],
            'card' => ['enabled' => false, 'mode' => 'test'],
        ],
        'payments.links.ttl_minutes' => 1440,
        'billing.automation.enabled' => true,
        'billing.invoice.default_status' => 'open',
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $index = 0;

        foreach (self::SETTINGS as $key => $value) {
            $index++;
            $uuid = self::UUID_PREFIX . str_pad((string) $index, 12, '0', STR_PAD_LEFT);
            $encoded = $this->json($value);

            $existing = $this->db->selectOne(
                'SELECT `id`, `value` FROM `platform_settings` WHERE `key` = :key OR `uuid` = :uuid LIMIT 1',
                ['key' => $key, 'uuid' => $uuid]
            );

            if ($existing === null) {
                // `key`/`value` are reserved words: use run() with quoted identifiers.
                $this->db->execute(
                    'INSERT INTO `platform_settings` (`uuid`, `key`, `value`, `updated_by_user_id`)'
                    . ' VALUES (:uuid, :key, :value, :updated_by)',
                    ['uuid' => $uuid, 'key' => $key, 'value' => $encoded, 'updated_by' => null]
                );
                $this->count('platform_settings_created');

                continue;
            }

            if ((string) ($existing['value'] ?? '') !== $encoded) {
                $this->db->execute(
                    'UPDATE `platform_settings` SET `value` = :value, `updated_at` = CURRENT_TIMESTAMP(3)'
                    . ' WHERE `id` = :id',
                    ['value' => $encoded, 'id' => (int) $existing['id']]
                );
                $this->count('fixtures_updated');
            }
        }

        return $this->counters();
    }
}
