<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Provider payment webhooks (Inc-13 contract §8 #8/#9).
 *
 * CSRF-exempt by reviewed allowlist; authenticated by provider HMAC signature +
 * timestamp replay window. Confirmation is idempotent through the
 * billing_payments provider-txn unique key.
 */
final class PaymentWebhookController
{
    public function mpesa(Request $request, array $params, Context $context): Response
    {
        return $this->handle('mpesa', $request, $context);
    }

    public function card(Request $request, array $params, Context $context): Response
    {
        return $this->handle('card', $request, $context);
    }

    private function handle(string $provider, Request $request, Context $context): Response
    {
        $signature = strtolower(trim((string) ($request->header('X-Payment-Signature')
            ?? $request->header('X-Webhook-Signature') ?? '')));
        $timestamp = trim((string) ($request->header('X-Payment-Timestamp')
            ?? $request->header('X-Webhook-Timestamp') ?? ''));

        try {
            $this->audit($context, 'billing.payment_webhook_received', $provider, []);
            $result = PaymentLinkService::make($context)->handleWebhook(
                $provider,
                (string) ($request->rawBody() ?? ''),
                $signature,
                $timestamp
            );
            $this->audit($context, 'billing.payment_webhook_verified', $provider, []);

            return Response::json([
                'success' => true,
                'status' => (string) ($result['status'] ?? 'confirmed'),
                'duplicate' => (bool) ($result['duplicate'] ?? false),
                'payment_uuid' => $result['payment_uuid'] ?? null,
            ], 200);
        } catch (HttpException $exception) {
            $this->audit($context, 'billing.payment_webhook_rejected', $provider, [
                'status' => $exception->status(),
                'code' => $exception->errorCode(),
            ]);

            return Response::json([
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->publicMessage(),
                ],
            ], $exception->status());
        } catch (Throwable) {
            $this->audit($context, 'billing.payment_webhook_rejected', $provider, ['status' => 500, 'code' => 'WEBHOOK_FAILED']);

            return Response::json([
                'success' => false,
                'error' => ['code' => 'WEBHOOK_FAILED', 'message' => 'The payment callback could not be processed.'],
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(Context $context, string $action, string $provider, array $metadata): void
    {
        try {
            $context->audit->record($action, 'billing_payment', null, ['provider' => $provider] + $metadata);
        } catch (Throwable) {
            // Never fail a provider callback solely because audit write failed.
        }
    }
}
