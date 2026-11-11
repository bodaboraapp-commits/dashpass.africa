<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Modules\Platform\PlatformBaseController;

/**
 * Platform billing automation summary + signed pay-link creation entry point
 * (Inc-13 contract §8 #5/#6). Full schedule management lands with the P2/P3
 * service slices; this page exposes the current schedule state and last run
 * outcomes from audit-only history.
 */
final class PlatformBillingAutomationController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.invoice.view');

        $schedules = $context->db->select(
            "SELECT bs.uuid, bs.scope, bs.status, bs.next_run_at, bs.last_run_at, bs.last_period_key,
                    e.name AS estate_name
             FROM billing_schedules bs
             LEFT JOIN estates e ON e.id = bs.estate_id
             ORDER BY bs.next_run_at ASC LIMIT 100"
        );
        $lastRun = $context->db->selectOne(
            "SELECT metadata, created_at FROM audit_logs WHERE action = 'billing.automation_run' ORDER BY id DESC LIMIT 1"
        );

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Billing automation</title></head>'
            . '<body><main><h1>Billing automation</h1><p>Schedules, next runs and last automation outcome.</p>'
            . '<p><a href="/platform/billing">Back to billing</a></p>'
            . '<h2>Schedules</h2><ul>';

        foreach ($schedules as $schedule) {
            $html .= '<li>' . e((string) ($schedule['estate_name'] ?? 'Platform'))
                . ' · ' . e((string) $schedule['scope'])
                . ' · ' . e((string) $schedule['status'])
                . ' · next ' . e((string) ($schedule['next_run_at'] ?? '—')) . '</li>';
        }

        $html .= '</ul><h2>Last run</h2><pre>'
            . e($lastRun === null ? 'No automation run yet.' : (string) json_encode($lastRun, JSON_PRETTY_PRINT))
            . '</pre></main></body></html>';

        return Response::html($html);
    }

    public function createPayLink(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');

        $uuid = trim((string) $request->input('invoice_uuid', ''));
        $invoice = $uuid === '' ? null : $context->db->selectOne(
            "SELECT id, uuid, amount_cents, currency, status FROM billing_invoices WHERE uuid = :uuid LIMIT 1",
            ['uuid' => $uuid]
        );

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That invoice is not available.');
        }

        // PaymentLinkService/provider adapters land in the P4 payment slice; until a
        // gateway is configured this is the contract's graceful disabled state.
        return Response::json([
            'success' => false,
            'error' => ['code' => 'PAYMENT_LINK_NOT_CONFIGURED', 'message' => 'Payment links are not configured yet.'],
            'data' => ['invoice_uuid' => (string) $invoice['uuid']],
        ], 503);
    }
}
