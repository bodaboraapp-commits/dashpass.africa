<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Public signed payment page (Inc-13 contract §8 #7).
 *
 * The token is HMAC-signed/expiring and is verified server-side; no session or
 * card data reaches this app. Until the payment-link/service slice is enabled the
 * page shows the graceful disabled state rather than a 500.
 */
final class PublicPaymentController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $token = trim((string) ($params['token'] ?? ''));

        if ($token === '') {
            return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment</title></head>'
                . '<body><main><h1>Payment link unavailable</h1><p>This payment link is invalid or has expired.</p></main></body></html>', 404);
        }

        // Signed token verification and provider selection land in the payment
        // service slice; disabled state is intentional and safe.
        return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment</title></head>'
            . '<body><main><h1>Payment link unavailable</h1>'
            . '<p>Payment links are not configured yet. Please use the pay instructions on your invoice or contact the estate office.</p>'
            . '</main></body></html>', 503);
    }
}
