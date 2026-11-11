<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;

/**
 * Limited estate settings allowlist (Increment 4 §2; Increment 8 adds the parking
 * overstay grace).
 *
 * Only `visitor_validity_minutes` (15–1440), `visitor_parking_grace_minutes`
 * (0–120) and `parking_overstay_grace_minutes` (0–120) may be written; any other
 * submitted key is rejected with 422 SETTING_NOT_ALLOWED. Values are stored as
 * JSON scalars exactly like the seeder.
 */
final class SettingsAdminService
{
    /**
     * @var array<string, array{label: string, min: int, max: int, type: string}>
     */
    private const ALLOWLIST = [
        'visitor_validity_minutes' => [
            'label' => 'Default visit validity (minutes)',
            'min' => 15,
            'max' => 1440,
            'type' => 'integer',
        ],
        'visitor_parking_grace_minutes' => [
            'label' => 'Visitor parking grace period (minutes)',
            'min' => 0,
            'max' => 120,
            'type' => 'integer',
        ],
        'parking_overstay_grace_minutes' => [
            'label' => 'Parking overstay grace period (minutes)',
            'min' => 0,
            'max' => 120,
            'type' => 'integer',
        ],
    ];

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly AdminAccess $access
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, AdminAccess::make($context));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $current = $this->currentValues();
        $settings = [];

        foreach (self::ALLOWLIST as $key => $definition) {
            $settings[] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $current[$key],
                'min' => $definition['min'],
                'max' => $definition['max'],
                'type' => $definition['type'],
            ];
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $input): array
    {
        $submitted = $this->submittedValues($input);

        if ($submitted === []) {
            throw new ValidationException(['settings' => ['No supported settings were submitted.']]);
        }

        $errors = [];
        $validated = [];

        foreach ($submitted as $key => $value) {
            $definition = self::ALLOWLIST[$key];
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer === false) {
                $errors[$key] = ['Enter a whole number.'];

                continue;
            }

            if ($integer < $definition['min'] || $integer > $definition['max']) {
                $errors[$key] = [sprintf(
                    'Enter a value between %d and %d.',
                    $definition['min'],
                    $definition['max']
                )];

                continue;
            }

            $validated[$key] = $integer;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $previous = $this->currentValues();
        $changed = [];

        $this->db->transaction(function () use ($validated, $previous, &$changed): void {
            foreach ($validated as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

                if ($encoded === false) {
                    throw new HttpException(500, 'SETTING_ENCODE_FAILED', 'Unable to save the setting.');
                }

                $existing = $this->db->selectOne(
                    'SELECT id FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :setting_key LIMIT 1',
                    ['estate_id' => $this->access->estateId(), 'setting_key' => $key]
                );

                if ($existing !== null) {
                    $this->db->update(
                        'estate_settings',
                        ['setting_value' => $encoded, 'updated_at' => Clock::nowDb()],
                        ['id' => (int) $existing['id']]
                    );
                } else {
                    $now = Clock::nowDb();
                    $this->db->insert('estate_settings', [
                        'estate_id' => $this->access->estateId(),
                        'setting_key' => $key,
                        'setting_value' => $encoded,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $changed[$key] = ['old' => $previous[$key] ?? null, 'new' => $value];
            }
        });

        $this->access->audit('admin.settings_updated', 'estate_settings', null, ['changed' => $changed]);

        return ['changed' => $changed];
    }

    /**
     * @return array<string, int|null>
     */
    private function currentValues(): array
    {
        $rows = $this->db->select(
            'SELECT setting_key, setting_value FROM estate_settings
             WHERE estate_id = :estate_id
               AND setting_key IN (:validity, :grace, :overstay_grace)',
            [
                'estate_id' => $this->access->estateId(),
                'validity' => 'visitor_validity_minutes',
                'grace' => 'visitor_parking_grace_minutes',
                'overstay_grace' => 'parking_overstay_grace_minutes',
            ]
        );

        $stored = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['setting_value'], true);
            $stored[(string) $row['setting_key']] = is_numeric($decoded) ? (int) $decoded : null;
        }

        return [
            'visitor_validity_minutes' => $stored['visitor_validity_minutes']
                ?? max(15, (int) Config::get('visitors.invitation.default_validity_minutes', 240)),
            'visitor_parking_grace_minutes' => $stored['visitor_parking_grace_minutes'] ?? 0,
            'parking_overstay_grace_minutes' => $stored['parking_overstay_grace_minutes'] ?? 30,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function submittedValues(array $input): array
    {
        $source = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : $input;
        $submitted = [];

        foreach ($source as $key => $value) {
            if (!is_string($key) || in_array($key, ['_token', 'settings'], true)) {
                continue;
            }

            if (!array_key_exists($key, self::ALLOWLIST)) {
                throw new HttpException(
                    422,
                    'SETTING_NOT_ALLOWED',
                    'That setting is not editable from the admin console.',
                    ['errors' => ['settings' => ['That setting is not editable from the admin console.']]]
                );
            }

            $submitted[$key] = $value;
        }

        return $submitted;
    }
}
