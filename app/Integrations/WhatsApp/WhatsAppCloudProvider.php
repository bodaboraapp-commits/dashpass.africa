<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

/**
 * WhatsApp Cloud API client (Meta Graph API).
 *
 * Sends an approved template message with optional body parameters. Templates
 * are required for business-initiated messages, so notifications always go
 * through the estate's configured template.
 *
 * The HTTP transport is injectable so tests never hit the network:
 * `fn (string $url, array $headers, string $body): array{status:int, body:string}`.
 *
 * Security: the access token is only sent in the Authorization header and is
 * never logged or included in exception messages.
 */
final class WhatsAppCloudProvider
{
    public const GRAPH_VERSION = 'v21.0';

    /** @var callable(string, array<string,string>, string): array{status:int, body:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? self::defaultTransport(...);
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
}
