<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
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
