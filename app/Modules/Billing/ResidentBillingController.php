<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Payments\PaymentLinkService;
use App\Modules\Shared\BuildsShell;

/**
 * Resident "My bills" read surfaces (Inc-13 contract §8 #10-12).
 *
 * Ownership is auth + active household membership: no new permission node/link
 * (links stay 217). Foreign/other-household uuids return a generic 404. Pay-now
 * links are issued by the payment slice; until a gateway is configured the
 * response is the contract's graceful disabled state.
 */
final class ResidentBillingController
{
    use BuildsShell;

    public function index(Request $request, array $params, Context $context): Response
    {
        $this->assertResidentOnly($context);
        $householdIds = $this->householdIds($context);
        $invoices = $householdIds === [] ? [] : $context->db->select(
            "SELECT i.uuid, i.invoice_number, i.status, i.amount_cents, i.currency, i.due_at,
                    i.period_key, i.kind, h.unit_number
             FROM billing_invoices i
             LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             WHERE i.kind = 'utility' AND i.household_id IN (" . implode(',', array_fill(0, count($householdIds), '?')) . ')
             ORDER BY i.due_at DESC, i.id DESC LIMIT 100',
            $householdIds
        );

        $data = $this->shell($context, 'My bills', 'home') + ['invoices' => $invoices];
        if (View::exists('app/bills')) {
            return View::render('app/bills', $data, 'app');
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>My bills</title></head>'
            . '<body><main><h1>My bills</h1><p>Utility bills for your household.</p><ul>';

        foreach ($invoices as $invoice) {
            $html .= '<li><a href="/app/bills/' . e(rawurlencode((string) $invoice['uuid'])) . '">'
                . e((string) $invoice['invoice_number']) . '</a> · '
                . e((string) $invoice['status']) . ' · ' . e((string) $invoice['amount_cents']) . ' '
                . e((string) $invoice['currency']) . '</li>';
        }

        return Response::html($html . '</ul><p><a href="/app">Back to app</a></p></main></body></html>');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->assertResidentOnly($context);
        $uuid = (string) ($params['uuid'] ?? '');
        $householdIds = $this->householdIds($context);
        $invoice = null;

        if ($householdIds !== [] && $uuid !== '') {
            $placeholders = [];
            $params = ['uuid' => $uuid];

            foreach ($householdIds as $index => $householdId) {
                $placeholders[] = ':h' . $index;
                $params['h' . $index] = $householdId;
            }

            $invoice = $context->db->selectOne(
                "SELECT i.*, h.unit_number FROM billing_invoices i
                 LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
                 WHERE i.uuid = :uuid AND i.kind = 'utility'
                   AND i.household_id IN (" . implode(',', $placeholders) . ')
                 LIMIT 1',
                $params
            );
        }

        if ($invoice === null) {
            throw HttpException::notFound('BILL_NOT_FOUND', 'That bill is not available.');
        }

        $data = $this->shell($context, 'Bill', 'home') + ['invoice' => $invoice];
        if (View::exists('app/bill')) {
            return View::render('app/bill', $data, 'app');
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Bill</title></head>'
            . '<body><main><h1>' . e((string) $invoice['invoice_number']) . '</h1>'
            . '<p>Status: ' . e((string) $invoice['status']) . '</p>'
            . '<p>Amount: ' . e((string) $invoice['amount_cents']) . ' ' . e((string) $invoice['currency']) . '</p>'
            . '<p>Due: ' . e((string) ($invoice['due_at'] ?? '—')) . '</p>'
            . '<form method="post" action="/app/bills/' . e(rawurlencode((string) $invoice['uuid'])) . '/pay-link">'
            . csrf_field() . '<button type="submit">Pay now</button></form>'
            . '<p><a href="/app/bills">Back to bills</a></p></main></body></html>';

        return Response::html($html);
    }

    public function payLink(Request $request, array $params, Context $context): Response
    {
        $this->assertResidentOnly($context);
        $uuid = (string) ($params['uuid'] ?? '');
        $householdIds = $this->householdIds($context);
        $placeholders = [];
        $params = ['uuid' => $uuid];

        foreach ($householdIds as $index => $householdId) {
            $placeholders[] = ':h' . $index;
            $params['h' . $index] = $householdId;
        }

        $owned = $householdIds !== [] && $uuid !== '' && $context->db->selectOne(
            "SELECT id FROM billing_invoices WHERE uuid = :uuid AND kind = 'utility'
             AND household_id IN (" . implode(',', $placeholders) . ') LIMIT 1',
            $params
        ) !== null;

        if (!$owned) {
            throw HttpException::notFound('BILL_NOT_FOUND', 'That bill is not available.');
        }

        try {
            $link = PaymentLinkService::make($context)->createForInvoice($uuid, (string) $request->input('provider', 'mpesa'));
        } catch (HttpException $exception) {
            return Response::json([
                'success' => false,
                'error' => ['code' => $exception->errorCode(), 'message' => $exception->publicMessage()],
            ], $exception->status());
        }

        if ($request->expectsJson()) {
            return Response::json(['success' => true, 'data' => $link], 200);
        }

        return Response::redirect((string) $link['url']);
    }

    /**
     * Resident bills are for resident/tenant/household-member roles only; guards
     * and parking managers (and platform roles) must not use this surface.
     */
    private function assertResidentOnly(Context $context): void
    {
        $membership = $context->tenant->active();

        if ($membership === null) {
            throw HttpException::forbidden('RESIDENT_ONLY', 'Bills are available to household members only.');
        }

        $role = $context->db->selectOne('SELECT name FROM roles WHERE id = :id LIMIT 1', ['id' => (int) $membership['role_id']]);
        $name = (string) ($role['name'] ?? '');

        if (!in_array($name, ['resident', 'tenant', 'household_member'], true)) {
            throw HttpException::forbidden('RESIDENT_ONLY', 'Bills are available to household members only.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function householdIds(Context $context): array
    {
        $userId = (int) ($context->auth->id() ?? 0);

        if ($userId <= 0) {
            return [];
        }

        $rows = $context->db->select(
            "SELECT DISTINCT hm.household_id
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.status = 'active'
             WHERE hm.user_id = :user_id AND hm.status = 'active'",
            ['user_id' => $userId]
        );

        return array_values(array_map(static fn (array $row): int => (int) $row['household_id'], $rows));
    }
}
