<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Uuid;
use PDOException;
use Throwable;

/**
 * Signed, expiring, stateless payment links + provider webhook confirmation
 * (Inc-13 P4 contract §5/§8/§9).
 *
 * Tokens are HMAC-SHA256 over a compact JSON payload (invoice uuid, provider,
 * amount/currency, expiry, nonce); no payment-link table is used and no card data
 * is collected in-app. Webhooks are CSRF-exempt by route allowlist and
 * authenticated by HMAC + timestamp window; confirmation writes idempotently to
 * `billing_payments` (UNIQUE provider/provider_txn_id).
 */
final class PaymentLinkService
{
    public const TTL_DEFAULT_MINUTES = 1440;
    public const TTL_MAX_MINUTES = 10080;
    private const REPLAY_WINDOW_SECONDS = 300;

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    public function linkSecret(): string
    {
        $configured = trim((string) (getenv('PAYMENT_LINK_SECRET') ?: ''));
        $fallback = trim((string) Config::get('app.key', ''));

        return $configured !== '' ? $configured : $fallback;
    }

    public function webhookSecret(?string $provider = null): string
    {
        $providerKey = strtoupper((string) $provider) . '_WEBHOOK_SECRET';
        $candidates = [$providerKey, 'PAYMENT_WEBHOOK_SECRET', 'PAYMENTS_TEST_WEBHOOK_SECRET'];

        foreach ($candidates as $key) {
            $value = trim((string) (getenv($key) ?: ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function ttlMinutes(): int
    {
        $row = $this->context->db->selectOne(
            "SELECT `value` FROM platform_settings WHERE `key` = 'payments.links.ttl_minutes' LIMIT 1"
        );
        $value = $row === null ? self::TTL_DEFAULT_MINUTES : (int) json_decode((string) $row['value'], true);

        return max(5, min(self::TTL_MAX_MINUTES, $value > 0 ? $value : self::TTL_DEFAULT_MINUTES));
    }

    /**
     * @return array{configured:bool,providers:array<string,mixed>}
     */
    public function providers(): array
    {
        $row = $this->context->db->selectOne(
            "SELECT `value` FROM platform_settings WHERE `key` = 'payments.providers' LIMIT 1"
        );
        $providers = $row === null ? [] : json_decode((string) $row['value'], true);
        $providers = is_array($providers) ? $providers : [];
        $configured = false;

        foreach (['mpesa', 'card'] as $provider) {
            $mode = (string) ($providers[$provider]['mode'] ?? 'disabled');

            if ($mode === 'test' || $mode === 'live') {
                $configured = true;
            }
        }

        return ['configured' => $configured && $this->linkSecret() !== '', 'providers' => $providers];
    }

    /**
     * @return array{url:string,token:string,expires_at:string,provider:string}
     */
    public function createForInvoice(string $invoiceUuid, string $provider): array
    {
        $provider = strtolower(trim($provider));

        if (!in_array($provider, ['mpesa', 'card'], true)) {
            throw new HttpException(422, 'INVALID_PAYMENT_PROVIDER', 'Choose a supported payment provider.');
        }

        $meta = $this->providers();

        if (!$meta['configured']) {
            throw new HttpException(503, 'PAYMENT_LINK_NOT_CONFIGURED', 'Payment links are not configured yet.');
        }

        $mode = (string) ($meta['providers'][$provider]['mode'] ?? 'disabled');

        if (!in_array($mode, ['test', 'live'], true)) {
            throw new HttpException(503, 'PAYMENT_LINK_NOT_CONFIGURED', 'That payment method is not available.');
        }

        $invoice = $this->context->db->selectOne(
            "SELECT id, uuid, estate_id, amount_cents, currency, status FROM billing_invoices WHERE uuid = :uuid LIMIT 1",
            ['uuid' => $invoiceUuid]
        );

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That invoice is not available.');
        }

        if (in_array((string) $invoice['status'], ['paid', 'void', 'draft'], true)) {
            throw new HttpException(409, 'INVOICE_CLOSED', 'That invoice is not payable.');
        }

        $expiry = Clock::now()->modify('+' . $this->ttlMinutes() . ' minutes');
        $payload = [
            'invoice_uuid' => (string) $invoice['uuid'],
            'provider' => $provider,
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => strtoupper((string) $invoice['currency']),
            'exp' => $expiry->getTimestamp(),
            'nonce' => bin2hex(random_bytes(12)),
        ];
        $token = $this->sign($payload);
        $this->audit('billing.payment_link_created', (int) $invoice['id'], [
            'invoice_uuid' => (string) $invoice['uuid'],
            'provider' => $provider,
            'mode' => $mode,
            'expires_at' => Clock::toDb($expiry),
        ], (int) $invoice['estate_id']);

        return [
            'url' => '/pay/' . $token,
            'token' => $token,
            'expires_at' => Clock::toDb($expiry),
            'provider' => $provider,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyToken(string $token): ?array
    {
        $secret = $this->linkSecret();

        if ($secret === '' || $token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) <= Clock::now()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /**
     * Verify a provider callback and record an idempotent payment.
     *
     * @return array{status:string,payment_uuid?:string,duplicate?:bool}
     */
    public function handleWebhook(string $provider, string $rawBody, string $signature, string $timestamp): array
    {
        $secret = $this->webhookSecret($provider);

        if ($secret === '') {
            throw new HttpException(503, 'PAYMENT_PROVIDER_DISABLED', 'Payment provider is not configured.');
        }

        if ($signature === '' || $timestamp === '') {
            throw new HttpException(401, 'WEBHOOK_SIGNATURE_INVALID', 'A valid provider signature and timestamp are required.');
        }

        if (!ctype_digit($timestamp) || abs(Clock::now()->getTimestamp() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            throw new HttpException(401, 'WEBHOOK_TIMESTAMP_INVALID', 'The provider timestamp is outside the replay window.');
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expected, strtolower($signature))) {
            throw new HttpException(401, 'WEBHOOK_SIGNATURE_INVALID', 'The provider signature is invalid.');
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            throw new HttpException(400, 'WEBHOOK_PAYLOAD_INVALID', 'The provider payload is not valid JSON.');
        }

        $invoiceUuid = trim((string) ($payload['invoice_uuid'] ?? ''));
        $providerTxn = trim((string) ($payload['provider_txn_id'] ?? ($payload['reference'] ?? '')));
        $amount = (int) ($payload['amount_cents'] ?? 0);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
        $providerStatus = strtolower(trim((string) ($payload['status'] ?? 'confirmed')));

        if ($providerTxn === '' || $invoiceUuid === '' || $amount <= 0 || $currency === '') {
            throw new HttpException(422, 'WEBHOOK_PAYLOAD_INVALID', 'The provider payload is missing required fields.');
        }

        $invoice = $this->context->db->selectOne(
            'SELECT * FROM billing_invoices WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $invoiceUuid]
        );

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That invoice is not available.');
        }

        if (strtoupper((string) $invoice['currency']) !== $currency) {
            throw new HttpException(422, 'PAYMENT_AMOUNT_MISMATCH', 'The payment currency does not match the invoice.');
        }

        if (!in_array($providerStatus, ['confirmed', 'failed'], true)) {
            $providerStatus = 'confirmed';
        }

        $method = $provider === 'card' ? 'card' : 'mpesa';
        $recordedBy = (int) ($this->context->db->scalar(
            "SELECT id FROM users WHERE phone = '+254700000020' LIMIT 1"
        ) ?? 1);

        try {
            $paymentUuid = Uuid::v4();
            $paymentId = $this->context->db->transaction(function () use (
                $invoice,
                $paymentUuid,
                $provider,
                $providerTxn,
                $amount,
                $currency,
                $providerStatus,
                $method,
                $recordedBy,
                $payload
            ): int {
                $existing = $this->context->db->selectOne(
                    'SELECT uuid FROM billing_payments WHERE provider = :provider AND provider_txn_id = :txn LIMIT 1',
                    ['provider' => $provider, 'txn' => $providerTxn]
                );

                if ($existing !== null) {
                    return 0;
                }

                $now = Clock::nowDb();
                $id = $this->context->db->insert('billing_payments', [
                    'uuid' => $paymentUuid,
                    'estate_id' => (int) $invoice['estate_id'],
                    'invoice_id' => (int) $invoice['id'],
                    'method' => $method,
                    'reference' => mb_substr($providerTxn, 0, 80),
                    'provider' => mb_substr($provider, 0, 40),
                    'provider_txn_id' => mb_substr($providerTxn, 0, 120),
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'status' => $providerStatus === 'failed' ? 'failed' : 'confirmed',
                    'provider_status' => $providerStatus,
                    'paid_at' => $providerStatus === 'confirmed' ? $now : null,
                    'recorded_by_user_id' => $recordedBy,
                    'metadata' => json_encode([
                        'via' => 'webhook',
                        'provider_status' => $providerStatus,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($providerStatus === 'confirmed') {
                    $confirmed = (int) ($this->context->db->scalar(
                        "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_payments
                         WHERE invoice_id = :invoice_id AND status = 'confirmed'",
                        ['invoice_id' => (int) $invoice['id']]
                    ) ?? 0);

                    if ($confirmed >= (int) $invoice['amount_cents']
                        && in_array((string) $invoice['status'], ['open', 'past_due'], true)) {
                        $this->context->db->update('billing_invoices', [
                            'status' => 'paid',
                            'paid_at' => $now,
                            'updated_at' => $now,
                        ], ['id' => (int) $invoice['id']]);
                    }
                }

                return $id;
            });

            if ($paymentId === 0) {
                return ['status' => 'duplicate', 'duplicate' => true];
            }

            $this->audit('billing.payment_confirmed', $paymentId, [
                'invoice_uuid' => (string) $invoice['uuid'],
                'provider' => $provider,
                'amount_cents' => $amount,
                'currency' => $currency,
                'provider_txn_id' => mb_substr($providerTxn, 0, 120),
                'provider_status' => $providerStatus,
            ], (int) $invoice['estate_id']);

            return ['status' => 'confirmed', 'payment_uuid' => $paymentUuid];
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return ['status' => 'duplicate', 'duplicate' => true];
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload): string
    {
        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->linkSecret(), true));

        return $encoded . '.' . $signature;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, int $entityId, array $metadata, int $estateId): void
    {
        try {
            $this->context->audit->record($action, 'billing_payment', $entityId, $metadata, $estateId);
        } catch (Throwable $exception) {
            Logger::warning('Payment audit failed', ['action' => $action]);
        }
    }
}
