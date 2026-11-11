<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Provider payment webhooks (Inc-13 contract §8 #8/#9).
 *
 * These routes are CSRF-exempt by reviewed allowlist; authentication is provider
 * signature + replay protection (implemented in the payment service slice). The
 * controller never trusts client-supplied amounts/invoice state.
 */
final class PaymentWebhookController
{
    public function mpesa(Request $request, array $params, Context $context): Response
    {
        return $this->disabled('mpesa');
    }

    public function card(Request $request, array $params, Context $context): Response
    {
        return $this->disabled('card');
    }

    private function disabled(string $provider): Response
    {
        // No gateway configured yet: acknowledge safely without creating ledger rows.
        return Response::json([
            'success' => false,
            'status' => 'disabled',
            'provider' => $provider,
            'message' => 'Payment provider is not configured.',
        ], 503);
    }
}
