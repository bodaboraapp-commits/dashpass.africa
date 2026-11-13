<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Integrations\WhatsApp\WhatsAppCloudProvider;

/**
 * Estate WhatsApp Cloud API settings (owner request 2026-11-13).
 *
 * The Estate Admin enters the Meta/WhatsApp Cloud API details for their estate:
 * phone number id, WhatsApp Business Account id, permanent access token, app
 * secret, webhook verify token, approved template name/language and an enabled
 * switch. Access token, app secret and verify token are encrypted at rest with
 * Crypto (per-field context bound to the estate) and never rendered back in
 * plaintext; the settings screen only shows whether each secret is set.
 *
 * The channel is available for the estate when the row is enabled, a phone
 * number id and an access token are present. This lets an estate use WhatsApp
 * without any global env credentials.
 */
final class WhatsAppSettingsService
{
    public const FORM_MARKER = 'whatsapp_form';

    public const SECRET_KEYS = [
        'whatsapp_access_token',
        'whatsapp_app_secret',
        'whatsapp_verify_token',
    ];

    private const COLUMNS = [
        'whatsapp_phone_number_id' => 'phone_number_id',
        'whatsapp_waba_id' => 'waba_id',
        'whatsapp_access_token' => 'access_token_encrypted',
        'whatsapp_app_secret' => 'app_secret_encrypted',
        'whatsapp_verify_token' => 'verify_token_encrypted',
        'whatsapp_template_name' => 'template_name',
        'whatsapp_template_language' => 'template_language',
        'whatsapp_enabled' => 'enabled',
    ];

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(int $estateId): ?array
    {
        if ($estateId <= 0) {
            return null;
        }

        try {
            return $this->context->db->selectOne(
                'SELECT * FROM estate_whatsapp_settings WHERE estate_id = :estate_id LIMIT 1',
                ['estate_id' => $estateId]
            );
        } catch (\Throwable) {
            // Pre-migration deployments must keep working (channel stays unavailable).
            return null;
        }
    }

    /**
     * Safe values for the settings screen: no secret is ever returned.
     *
     * @return array<string, mixed>
     */
    public function masked(int $estateId): array
    {
        $row = $this->row($estateId);
        $enabled = $row !== null && (int) ($row['enabled'] ?? 0) === 1;

        return [
            'configured' => $this->isConfigured($estateId),
            'enabled' => $enabled,
            'phone_number_id' => $row === null ? '' : (string) ($row['phone_number_id'] ?? ''),
            'waba_id' => $row === null ? '' : (string) ($row['waba_id'] ?? ''),
            'template_name' => $row === null ? 'estate_notification' : (string) ($row['template_name'] ?? 'estate_notification'),
            'template_language' => $row === null ? 'en' : (string) ($row['template_language'] ?? 'en'),
            'access_token_set' => $row !== null && trim((string) ($row['access_token_encrypted'] ?? '')) !== '',
            'app_secret_set' => $row !== null && trim((string) ($row['app_secret_encrypted'] ?? '')) !== '',
            'verify_token_set' => $row !== null && trim((string) ($row['verify_token_encrypted'] ?? '')) !== '',
            'updated_at' => $row === null ? null : ($row['updated_at'] ?? null),
        ];
    }

    /**
     * Decrypted credentials for sending (never exposed to views).
     *
     * @return array{phone_number_id:string, waba_id:string, access_token:string, app_secret:string, verify_token:string, template_name:string, template_language:string}|null
     */
    public function credentials(int $estateId): ?array
    {
        $row = $this->row($estateId);

        if ($row === null) {
            return null;
        }

        $accessToken = $this->decryptSecret($row, 'access_token_encrypted', 'access_token', $estateId);

        if (trim((string) ($row['phone_number_id'] ?? '')) === '' || $accessToken === '') {
            return null;
        }

        return [
            'phone_number_id' => trim((string) $row['phone_number_id']),
            'waba_id' => trim((string) ($row['waba_id'] ?? '')),
            'access_token' => $accessToken,
            'app_secret' => $this->decryptSecret($row, 'app_secret_encrypted', 'app_secret', $estateId),
            'verify_token' => $this->decryptSecret($row, 'verify_token_encrypted', 'verify_token', $estateId),
            'template_name' => trim((string) ($row['template_name'] ?? 'estate_notification'))
                ?: 'estate_notification',
            'template_language' => trim((string) ($row['template_language'] ?? 'en')) ?: 'en',
        ];
    }

    public function isConfigured(int $estateId): bool
    {
        $row = $this->row($estateId);

        if ($row === null || (int) ($row['enabled'] ?? 0) !== 1) {
            return false;
        }

        return trim((string) ($row['phone_number_id'] ?? '')) !== ''
            && trim((string) ($row['access_token_encrypted'] ?? '')) !== '';
    }

    public function providerForEstate(int $estateId, ?callable $transport = null): ?WhatsAppCloudProvider
    {
        $credentials = $this->credentials($estateId);

        if (!$this->isConfigured($estateId) || $credentials === null) {
            return null;
        }

        return new WhatsAppCloudProvider($transport);
    }

    /**
     * Save the WhatsApp settings form. Returns true when the submission was a
     * WhatsApp form (so the controller can skip the unrelated legacy settings).
     *
     * @param array<string, mixed> $input
     */
    public function update(array $input): bool
    {
        if (!isset($input[self::FORM_MARKER])) {
            return false;
        }

        $active = $this->context->tenant->active();
        $estateId = is_array($active) ? (int) ($active['estate_id'] ?? 0) : 0;

        if ($estateId <= 0) {
            throw new HttpException(403, 'ESTATE_CONTEXT_REQUIRED', 'Choose an estate first.');
        }

        $phoneNumberId = trim((string) ($input['whatsapp_phone_number_id'] ?? ''));
        $wabaId = trim((string) ($input['whatsapp_waba_id'] ?? ''));
        $templateName = trim((string) ($input['whatsapp_template_name'] ?? 'estate_notification'));
        $templateLanguage = trim((string) ($input['whatsapp_template_language'] ?? 'en'));
        $enabled = ($input['whatsapp_enabled'] ?? '') !== '';

        $errors = [];

        if ($phoneNumberId !== '' && preg_match('/^[0-9]{5,40}$/', $phoneNumberId) !== 1) {
            $errors['whatsapp_phone_number_id'] = ['Enter the numeric WhatsApp phone number ID from Meta.'];
        }

        if ($wabaId !== '' && preg_match('/^[0-9]{5,40}$/', $wabaId) !== 1) {
            $errors['whatsapp_waba_id'] = ['Enter the numeric WhatsApp Business Account ID from Meta.'];
        }

        if ($templateName !== '' && preg_match('/^[a-z0-9_]{3,120}$/', $templateName) !== 1) {
            $errors['whatsapp_template_name'] = ['Use lowercase letters, numbers and underscores for the template name.'];
        }

        if ($templateLanguage !== '' && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $templateLanguage) !== 1) {
            $errors['whatsapp_template_language'] = ['Enter a language code such as en or en_US.'];
        }

        $existing = $this->row($estateId);
        $existingToken = $existing !== null ? (string) ($existing['access_token_encrypted'] ?? '') : '';
        $newToken = trim((string) ($input['whatsapp_access_token'] ?? ''));
        $hasToken = $newToken !== '' || $existingToken !== '';

        if ($enabled && ($phoneNumberId === '' || !$hasToken)) {
            $errors['whatsapp_enabled'] = ['Add the phone number ID and access token before enabling WhatsApp.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = Clock::nowDb();
        $actorId = $this->context->auth->id();
        $payload = [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId !== '' ? $wabaId : null,
            'template_name' => $templateName !== '' ? $templateName : 'estate_notification',
            'template_language' => $templateLanguage !== '' ? $templateLanguage : 'en',
            'enabled' => $enabled ? 1 : 0,
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];

        foreach (self::SECRET_KEYS as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                $payload[self::COLUMNS[$key]] = Crypto::encrypt(
                    $value,
                    'estate-whatsapp-' . $key . ':' . $estateId
                );
            }
        }

        if ($existing === null) {
            $payload['uuid'] = Uuid::v4();
            $payload['estate_id'] = $estateId;
            $payload['created_at'] = $now;
            $this->context->db->insert('estate_whatsapp_settings', $payload);
        } else {
            $this->context->db->update('estate_whatsapp_settings', $payload, ['id' => (int) $existing['id']]);
        }

        try {
            $this->context->audit->record(
                'whatsapp.settings_updated',
                'estate_whatsapp_settings',
                $existing === null ? null : (int) $existing['id'],
                ['estate_id' => $estateId, 'enabled' => $enabled]
            );
        } catch (\Throwable) {
            // Persisting settings must not fail solely because audit failed.
        }

        return true;
    }

    private function decryptSecret(array $row, string $column, string $contextSuffix, int $estateId): string
    {
        $encrypted = trim((string) ($row[$column] ?? ''));

        if ($encrypted === '') {
            return '';
        }

        $plain = Crypto::decrypt($encrypted, 'estate-whatsapp-whatsapp_' . $contextSuffix . ':' . $estateId);

        return $plain === null ? '' : $plain;
    }
}
