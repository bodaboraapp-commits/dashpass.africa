<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Public signed payment page (Inc-13 contract §8 #7).
 *
 * The opaque token is HMAC-signed/expiring and verified server-side; no session
 * or card data reaches this app. Invalid/expired tokens and terminal invoices
 * render safe terminal states with no pay action.
 */
final class PublicPaymentController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $token = trim((string) ($params['token'] ?? ''));
        $service = PaymentLinkService::make($context);
        $payload = $token === '' ? null : $service->verifyToken($token);

        if ($payload === null) {
            return View::render('public/pay', [
                'title' => 'Payment',
                'available' => false,
                'currency' => 'KES',
            ], 'guest')->withStatus(404);
        }

        $invoice = $context->db->selectOne(
            'SELECT uuid, invoice_number, amount_cents, currency, status, due_at FROM billing_invoices WHERE uuid = :uuid LIMIT 1',
            ['uuid' => (string) ($payload['invoice_uuid'] ?? '')]
        );

        if ($invoice === null
            || (int) $invoice['amount_cents'] !== (int) ($payload['amount_cents'] ?? 0)
            || strtoupper((string) $invoice['currency']) !== (string) ($payload['currency'] ?? '')) {
            return View::render('public/pay', [
                'title' => 'Payment',
                'available' => false,
                'currency' => (string) ($payload['currency'] ?? 'KES'),
            ], 'guest')->withStatus(410);
        }

        $payable = !in_array((string) $invoice['status'], ['paid', 'void', 'draft'], true);

        return View::render('public/pay', [
            'title' => 'Pay invoice ' . (string) $invoice['invoice_number'],
            'available' => $payable,
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => (string) $invoice['currency'],
            'methods' => [],
        ], 'guest');
    }
}
