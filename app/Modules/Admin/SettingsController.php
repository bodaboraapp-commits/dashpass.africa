<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Integrations\WhatsApp\WhatsAppSendException;
use Throwable;
use App\Modules\Notifications\WhatsAppSettingsService;

/**
 * Admin estate settings screen (frozen contract §7): settings, errors.
 */
final class SettingsController extends AdminBaseController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        $activeEstate = $context->tenant->active();
        $estateId = is_array($activeEstate) ? (int) ($activeEstate['estate_id'] ?? 0) : 0;
        $billingValues = [];
        if ($estateId > 0) {
            $rows = $context->db->select(
                "SELECT setting_key, setting_value FROM estate_settings
                 WHERE estate_id = :estate_id
                   AND (setting_key LIKE 'billing.%' OR setting_key = 'ui.appearance_default')",
                ['estate_id' => $estateId]
            );
            foreach ($rows as $row) {
                $billingValues[(string) $row['setting_key']] = json_decode((string) $row['setting_value'], true);
            }
        }

        $data = $this->shell($context, 'Estate settings', 'settings') + [
            'settings' => SettingsAdminService::make($context)->all(),
            'billing' => $billingValues,
            'billing_meta' => EstateBillingSettingsService::make($context)->metadata(),
            'whatsapp' => WhatsAppSettingsService::make($context)->masked($estateId),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/settings', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            $input = $this->normaliseSettingsInput($request->all());
            $whatsappHandled = WhatsAppSettingsService::make($context)->update($input);
            $billingHandled = EstateBillingSettingsService::make($context)->update($input);
            $legacy = [];

            foreach ($input as $key => $value) {
                if (!is_string($key) || str_starts_with($key, 'whatsapp_')) {
                    continue;
                }

                if (!str_starts_with($key, 'billing.') && $key !== 'ui.appearance_default') {
                    $legacy[$key] = $value;
                }
            }

            if ((!$billingHandled && !$whatsappHandled) || $legacy !== []) {
                SettingsAdminService::make($context)->update($legacy);
            }

            $this->flashSuccess('Estate settings updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/settings');
    }

    /**
     * Owner request (2026-11-13): "Test connection" button in the WhatsApp section.
     *
     * Read-only probe of the Meta Graph API phone-number node: proves the saved
     * phone number ID + access token work and reports the display number/verified
     * name. No WhatsApp message is sent and secrets are never echoed back.
     */
    public function testWhatsApp(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        $activeEstate = $context->tenant->active();
        $estateId = is_array($activeEstate) ? (int) ($activeEstate['estate_id'] ?? 0) : 0;
        $settings = WhatsAppSettingsService::make($context);
        $provider = $estateId > 0 ? $settings->providerForEstate($estateId) : null;
        $credentials = $estateId > 0 ? $settings->credentials($estateId) : null;

        $audit = static function (Context $context, int $estateId, string $result, ?string $reason = null): void {
            if ($estateId <= 0) {
                return;
            }

            try {
                $context->audit->record('estate.whatsapp_connection_tested', 'estate', $estateId, [
                    'result' => $result,
                    'reason' => $reason,
                ], $estateId);
            } catch (Throwable) {
                // Auditing must never break the settings screen.
            }
        };

        if ($provider === null || $credentials === null) {
            $this->flashError('Save the WhatsApp phone number ID and permanent access token first, then test the connection.');
            $audit($context, $estateId, 'not_configured');

            return $this->backTo('/admin/settings');
        }

        try {
            $result = $provider->testConnection($credentials);
            $display = trim((string) ($result['display_phone_number'] ?? ''));
            $verified = trim((string) ($result['verified_name'] ?? ''));
            $label = $display !== '' ? $display : 'the configured number';

            if ($verified !== '') {
                $label .= ' (' . $verified . ')';
            }

            $message = 'WhatsApp connection OK - ' . $label . '.';

            if (trim((string) ($credentials['template_name'] ?? '')) === '') {
                $message .= ' Set the approved template name before enabling the channel.';
            }

            $this->flashSuccess($message);
            $audit($context, $estateId, 'ok');
        } catch (WhatsAppSendException $exception) {
            $reason = $exception->getMessage();
            $message = match ($reason) {
                'whatsapp_credentials_invalid' => 'Meta rejected the access token. Check the permanent token and its WhatsApp permissions.',
                'whatsapp_phone_number_not_found' => 'Meta could not find that phone number ID. Copy the ID from the Meta app dashboard and save it again.',
                'whatsapp_transport_failed', 'whatsapp_curl_unavailable' => 'Could not reach Meta right now. Check the server connection and try again.',
                'whatsapp_not_configured' => 'Save the WhatsApp phone number ID and permanent access token first.',
                default => 'The WhatsApp connection test failed. Check the Meta credentials and try again.',
            };

            $this->flashError($message);
            $audit($context, $estateId, 'failed', $reason);
        } catch (Throwable) {
            $this->flashError('The WhatsApp connection test failed. Check the Meta credentials and try again.');
            $audit($context, $estateId, 'failed', 'unexpected_error');
        }

        return $this->backTo('/admin/settings');
    }

    /**
     * HTML field names containing dots are normalised by PHP to underscores
     * (`billing.utility_basis.water` -> `billing_utility_basis_water`). Map those
     * back to the dotted contract keys before handing settings to the services.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normaliseSettingsInput(array $input): array
    {
        $map = [
            'ui_appearance_default' => 'ui.appearance_default',
            'billing_notification_channels' => 'billing.notification_channels',
            'billing_payment_methods' => 'billing.payment_methods',
            'billing_schedule_defaults' => 'billing.schedule_defaults',
        ];
        $normalised = [];
        $prefixes = [
            'billing_utility_basis_' => 'billing.utility_basis.',
            'billing_utility_flat_cents_' => 'billing.utility_flat_cents.',
            'billing_utility_unit_rate_cents_' => 'billing.utility_unit_rate_cents.',
        ];

        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                $normalised[$key] = $value;
                continue;
            }
            if (isset($map[$key])) {
                $normalised[$map[$key]] = $value;
                continue;
            }
            $matched = false;
            foreach ($prefixes as $prefix => $contractPrefix) {
                if (str_starts_with($key, $prefix)) {
                    $normalised[$contractPrefix . substr($key, strlen($prefix))] = $value;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}
