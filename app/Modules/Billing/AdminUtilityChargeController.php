<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Admin\AdminBaseController;

/**
 * Estate-admin utility bills overview (Inc-13 contract §8 #15).
 *
 * Read-only first slice: own-estate utility schedules/charges/invoices; no new
 * permission slug (existing estate billing capability gates the route).
 */
final class AdminUtilityChargeController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $estateId = (int) ($context->tenant->requireEstate()['estate_id']);

        $schedules = $context->db->select(
            "SELECT uuid, name, utility_type, flat_amount_cents, tariff_cents_per_unit, status,
                    next_run_at, last_run_at, last_period_key
             FROM billing_schedules
             WHERE estate_id = :estate_id AND scope = 'utility_bill'
             ORDER BY id",
            ['estate_id' => $estateId]
        );
        $invoices = $context->db->select(
            "SELECT i.uuid, i.invoice_number, i.status, i.period_key, i.amount_cents, i.currency,
                    i.due_at, h.unit_number
             FROM billing_invoices i
             LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             WHERE i.estate_id = :estate_id AND i.kind = 'utility'
             ORDER BY i.id DESC LIMIT 100",
            ['estate_id' => $estateId]
        );

        $data = $this->shell($context, 'Utility bills', 'utility') + ['schedules' => $schedules, 'invoices' => $invoices];
        if (View::exists('admin/utility-bills')) {
            return $this->render('admin/utility-bills', $data);
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Utility bills</title></head>'
            . '<body><main><h1>Utility bills</h1><p>Own-estate utility schedules and generated bills.</p>'
            . '<h2>Schedules</h2><ul>';

        foreach ($schedules as $schedule) {
            $html .= '<li>' . e((string) ($schedule['name'] ?? $schedule['utility_type'] ?? 'Utility'))
                . ' · ' . e((string) $schedule['status'])
                . ' · next ' . e((string) ($schedule['next_run_at'] ?? '—')) . '</li>';
        }

        $html .= '</ul><h2>Bills</h2><ul>';

        foreach ($invoices as $invoice) {
            $html .= '<li>' . e((string) $invoice['invoice_number'])
                . ' · ' . e((string) ($invoice['unit_number'] ?? '—'))
                . ' · ' . e((string) $invoice['status'])
                . ' · ' . e((string) $invoice['amount_cents']) . ' ' . e((string) $invoice['currency']) . '</li>';
        }

        return Response::html($html . '</ul></main></body></html>');
    }
}
