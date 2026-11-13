<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Core\Config;

/**
 * WhatsApp Cloud API client (Meta Graph API).
 *
 * Sends an approved template message with optional body parameters. Templates
 * are required for business-initiated messages, so notifications always go
 * through the estate's configured template.
 *
 * The HTTP transports are injectable so tests never hit the network:
 * - send:  `fn (string $url, array $headers, string $body): array{status:int, body:string}`
 * - read:  `fn (string $url, array $headers): array{status:int, body:string}`
 *
 * Security: the access token is only sent in the Authorization header and is
 * never logged or included in exception messages.
 */
final class WhatsAppCloudProvider
{
    public const GRAPH_VERSION = 'v21.0';

    /** @var callable(string, array<string,string>, string): array{status:int, body:string} */
    private $transport;

    /** @var callable(string, array<string,string>): array{status:int, body:string} */
    private $getTransport;

    public function __construct(?callable $transport = null, ?callable $getTransport = null)
    {
        $this->transport = $transport ?? self::defaultTransport(...);
        $this->getTransport = $getTransport ?? self::defaultGetTransport(...);
    }

    /**
     * @param array{phone_number_id:string, access_token:string, template_name?:string, template_language?:string} $credentials
     * @param array<int, string> $bodyParameters
     *
     * @return array{message_id: string|null}
     */
    public function sendTemplate(array $credentials, string $to, array $bodyParameters = []): array
    {
        $phoneNumberId = trim((string) ($credentials['phone_number_id'] ?? ''));
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($phoneNumberId === '' || $accessToken === '') {
            throw new WhatsAppSendException('whatsapp_not_configured');
        }

        $recipient = preg_replace('/\D+/', '', $to) ?? '';

        if (strlen($recipient) < 10) {
            throw new WhatsAppSendException('whatsapp_invalid_phone');
        }

        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/'
            . rawurlencode($phoneNumberId) . '/messages';

        $template = [
            'name' => trim((string) ($credentials['template_name'] ?? 'estate_notification')),
            'language' => ['code' => trim((string) ($credentials['template_language'] ?? 'en'))],
        ];

        if ($bodyParameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => $value],
                    array_values($bodyParameters)
                ),
            ]];
        }

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => $template,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new WhatsAppSendException('whatsapp_payload_encode_failed');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $response = ($this->transport)($url, $headers, $payload);
        } catch (WhatsAppSendException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $decoded = json_decode($body, true);
        $messageId = is_array($decoded)
            ? trim((string) ($decoded['messages'][0]['id'] ?? ''))
            : '';

        if ($status < 200 || $status >= 300 || (is_array($decoded) && isset($decoded['error']))) {
            throw new WhatsAppSendException(
                'whatsapp_provider_rejected_' . $status,
                $status
            );
        }

        return ['message_id' => $messageId !== '' ? $messageId : null];
    }

    /**
     * Read-only connection check for the Estate Admin "Test connection" button.
     *
     * Calls the Graph API phone-number node (no message is sent) to prove the
     * saved phone number ID + access token work, and returns the number/name Meta
     * reports so the admin can eyeball that it is the right line.
     *
     * @param array{phone_number_id:string, access_token:string, template_name?:string, template_language?:string} $credentials
     * @return array{display_phone_number:string, verified_name:string, quality_rating:string}
     */
    public function testConnection(array $credentials): array
    {
        $phoneNumberId = trim((string) ($credentials['phone_number_id'] ?? ''));
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($phoneNumberId === '' || $accessToken === '') {
            throw new WhatsAppSendException('whatsapp_not_configured');
        }

        $fake = $this->fakeConnectionResult();

        if ($fake !== null) {
            return $fake;
        }

        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/'
            . rawurlencode($phoneNumberId)
            . '?fields=id,display_phone_number,verified_name,quality_rating';
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        try {
            $response = ($this->getTransport)($url, $headers);
        } catch (WhatsAppSendException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $failed = $status < 200 || $status >= 300 || (is_array($decoded) && isset($decoded['error']));

        if ($failed) {
            $code = match (true) {
                $status === 401, $status === 403 => 'whatsapp_credentials_invalid',
                $status === 400, $status === 404 => 'whatsapp_phone_number_not_found',
                default => 'whatsapp_provider_rejected_' . $status,
            };

            throw new WhatsAppSendException($code, $status);
        }

        $payload = is_array($decoded) ? $decoded : [];

        return [
            'display_phone_number' => trim((string) ($payload['display_phone_number'] ?? '')),
            'verified_name' => trim((string) ($payload['verified_name'] ?? '')),
            'quality_rating' => trim((string) ($payload['quality_rating'] ?? '')),
        ];
    }

    /**
     * Test-only canned connection results (non-production only), following the
     * existing WHATSAPP_FAKE_SEND precedent so integration tests stay offline.
     *
     * @return array{display_phone_number:string, verified_name:string, quality_rating:string}|null
     */
    private function fakeConnectionResult(): ?array
    {
        if ((string) Config::get('app.env', 'production') === 'production') {
            return null;
        }

        $fake = getenv('WHATSAPP_FAKE_CONNECTION');

        if (!is_string($fake) || $fake === '') {
            return null;
        }

        if ($fake === 'ok') {
            return [
                'display_phone_number' => '+254700000020',
                'verified_name' => 'MtaaPASS Test Number',
                'quality_rating' => 'GREEN',
            ];
        }

        if ($fake === 'unauthorized') {
            throw new WhatsAppSendException('whatsapp_credentials_invalid', 401);
        }

        if ($fake === 'not_found') {
            throw new WhatsAppSendException('whatsapp_phone_number_not_found', 404);
        }

        throw new WhatsAppSendException('whatsapp_transport_failed');
    }

    /**
     * Default cURL transport. Runs only in production paths; tests inject a fake.
     *
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    public static function defaultTransport(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new WhatsAppSendException('whatsapp_curl_unavailable');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        if ($error !== '') {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * Default cURL GET transport for read-only connection checks.
     *
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    public static function defaultGetTransport(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new WhatsAppSendException('whatsapp_curl_unavailable');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false || $error !== '') {
            throw new WhatsAppSendException('whatsapp_transport_failed');
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
