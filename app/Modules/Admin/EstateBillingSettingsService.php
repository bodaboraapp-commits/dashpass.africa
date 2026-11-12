<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\ValidationException;
use App\Modules\Notifications\ChannelSettingsService;

/**
 * Estate billing settings (Inc-13 P2), persisted in estate_settings and scoped
 * strictly to the caller's active estate. No new permissions/DB tables.
 */
final class EstateBillingSettingsService
{
    private const UTILITIES = ['water', 'electricity', 'gas', 'other'];
    private const BASES = ['flat', 'metered', 'both'];

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $estateId = $this->estateId();
        $rows = $this->context->db->select(
            "SELECT setting_key, setting_value FROM estate_settings
             WHERE estate_id = :estate_id AND (setting_key LIKE 'billing.%' OR setting_key = 'ui.appearance_default')",
            ['estate_id' => $estateId]
        );
        $out = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['setting_value'], true);
            $out[(string) $row['setting_key']] = $decoded === null ? $row['setting_value'] : $decoded;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return bool true when at least one billing key was handled
     */
    public function update(array $input): bool
    {
        $billing = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && (str_starts_with($key, 'billing.') || $key === 'ui.appearance_default')) {
                $billing[$key] = $value;
            }
        }

        if ($billing === []) {
            return false;
        }

        $estateId = $this->estateId();
        $errors = [];
        $values = [];

        if (array_key_exists('ui.appearance_default', $billing)) {
            $appearance = (string) $billing['ui.appearance_default'];

            if (!in_array($appearance, ['default', 'light', 'dark'], true)) {
                $errors['ui.appearance_default'] = ['Choose default, light or dark.'];
            } else {
                $values['ui.appearance_default'] = $appearance;
            }
        }

        foreach (self::UTILITIES as $utility) {
            $basisKey = 'billing.utility_basis.' . $utility;

            if (isset($billing[$basisKey])) {
                $basis = (string) $billing[$basisKey];

                if (!in_array($basis, self::BASES, true)) {
                    $errors[$basisKey] = ['Choose flat, metered or both.'];
                } else {
                    $values[$basisKey] = $basis;
                }
            }

            foreach (['billing.utility_flat_cents.', 'billing.utility_unit_rate_cents.'] as $prefix) {
                $key = $prefix . $utility;

                if (isset($billing[$key])) {
                    $int = filter_var($billing[$key], FILTER_VALIDATE_INT);

                    if ($int === false || $int < 0) {
                        $errors[$key] = ['Enter a non-negative whole number.'];
                    } else {
                        $values[$key] = $int;
                    }
                }
            }
        }

        if (isset($billing['billing.notification_channels'])) {
            $channels = $billing['billing.notification_channels'];
            $channels = is_array($channels) ? array_values(array_filter($channels, 'is_string')) : [$channels];
            $allowed = ChannelSettingsService::make($this->context)->globalChannels();

            foreach ($channels as $channel) {
                if (!isset($allowed[$channel]) || !$allowed[$channel]) {
                    $errors['billing.notification_channels'] = ['Choose only globally enabled channels (email and in-app).'];
                }
            }

            $values['billing.notification_channels'] = array_values(array_unique($channels));
        }

        if (isset($billing['billing.payment_methods'])) {
            $methods = $billing['billing.payment_methods'];
            $methods = is_array($methods) ? array_values(array_filter($methods, 'is_string')) : [$methods];

            foreach ($methods as $method) {
                if (!in_array($method, ['mpesa', 'card'], true)) {
                    $errors['billing.payment_methods'] = ['Choose M-Pesa or card.'];
                }
            }

            $values['billing.payment_methods'] = array_values(array_unique($methods));
        }

        $schedule = $billing['billing.schedule_defaults'] ?? null;
        $schedule = is_array($schedule) ? $schedule : [];
        $ranges = [
            'anchor_day' => [1, 28],
            'due_days' => [1, 60],
            'reminder_before_days' => [0, 30],
        ];

        foreach ($ranges as $field => [$min, $max]) {
            if (array_key_exists($field, $schedule)) {
                $int = filter_var($schedule[$field], FILTER_VALIDATE_INT);

                if ($int === false || $int < $min || $int > $max) {
                    $errors['billing.schedule_defaults.' . $field] = [sprintf('Enter a value between %d and %d.', $min, $max)];
                } else {
                    $schedule[$field] = $int;
                }
            }
        }

        if (array_key_exists('remind_on_due', $schedule)) {
            $schedule['remind_on_due'] = (bool) filter_var($schedule['remind_on_due'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('past_due_escalation', $schedule)) {
            $raw = $schedule['past_due_escalation'];
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

            if (!is_array($decoded)) {
                $errors['billing.schedule_defaults.past_due_escalation'] = ['Enter a JSON list of escalation days.'];
            } else {
                $schedule['past_due_escalation'] = array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
            }
        }

        if ($schedule !== []) {
            $values['billing.schedule_defaults'] = $schedule;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->context->db->transaction(function () use ($estateId, $values): void {
            foreach ($values as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $existing = $this->context->db->selectOne(
                    'SELECT id FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
                    ['estate_id' => $estateId, 'key' => $key]
                );

                if ($existing !== null) {
                    $this->context->db->update('estate_settings', [
                        'setting_value' => $encoded,
                        'updated_at' => Clock::nowDb(),
                    ], ['id' => (int) $existing['id']]);
                } else {
                    $this->context->db->insert('estate_settings', [
                        'estate_id' => $estateId,
                        'setting_key' => $key,
                        'setting_value' => $encoded,
                        'updated_at' => Clock::nowDb(),
                    ]);
                }
            }
        });

        try {
            $this->context->audit->record('billing.settings_updated', 'estate_settings', $estateId, [
                'keys' => array_keys($values),
            ], $estateId);
        } catch (\Throwable) {
            // Settings persistence wins over audit failure.
        }

        return true;
    }

    private function estateId(): int
    {
        $membership = $this->context->tenant->active();

        if ($membership === null) {
            throw new ValidationException(['estate' => ['No active estate context.']]);
        }

        return (int) $membership['estate_id'];
    }
}
