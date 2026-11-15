<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Config;
use App\Core\Context;
use App\Core\HttpException;

/**
 * Meta WhatsApp Embedded Signup (owner request 2026-11-15).
 *
 * The Estate Admin clicks "Connect WhatsApp with Meta" in Admin > Settings. We
 * redirect to Meta's OAuth/Embedded Signup dialog (no third-party JavaScript, so
 * the strict CSP is untouched), then exchange the returned code for a business
 * access token server-side and save it encrypted for the estate.
 *
 * Flow:
 *  1. authorizationUrl(state) -> Meta dialog with config_id + redirect_uri.
 *  2. Meta redirects back with ?code=... (and WABA/phone ids when provided).
 *  3. connect() exchanges the code, resolves the WABA / phone number id from the
 *     Graph API when they are not supplied, then persists the encrypted token.
 *
 * The HTTP transport is injectable for tests:
 *   fn (string $url, array $headers): array{status:int, body:string}
 */
final class WhatsAppEmbeddedSignupService
{
    /** @var callable(string, array<string,string>): array{status:int, body:string} */
    private $transport;

    public function __construct(
        private readonly Context $context,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? self::defaultGet(...);
    }

    public static function make(Context $context, ?callable $transport = null): self
    {
        return new self($context, $transport);
    }

    public function configured(): bool
    {
        return trim((string) Config::get('whatsapp.app_id', '')) !== ''
            && trim((string) Config::get('whatsapp.app_secret', '')) !== ''
            && trim((string) Config::get('whatsapp.es_config_id', '')) !== '';
    }

    public function authorizationUrl(string $state): string
    {
        if (!$this->configured()) {
            throw new HttpException(
                422,
                'WHATSAPP_SIGNUP_NOT_CONFIGURED',
                'Meta Embedded Signup is not configured on this deployment. Enter the credentials manually below.'
            );
        }

        $version = (string) Config::get('whatsapp.graph_version', 'v21.0');
        $query = http_build_query([
            'client_id' => (string) Config::get('whatsapp.app_id', ''),
            'config_id' => (string) Config::get('whatsapp.es_config_id', ''),
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $this->redirectUri(),
            'override_default_response_type' => 'true',
            'extras' => json_encode(['setup' => (object) [], 'sessionInfoVersion' => '3']),
        ]);

        return 'https://www.facebook.com/' . $version . '/dialog/oauth?' . $query;
    }

    public function redirectUri(): string
    {
        return url('/admin/settings/whatsapp/callback');
    }

    /**
     * Complete the connection for an estate.
     *
     * @return array{phone_number_id: string, waba_id: string, connected: bool}
     */
    public function connect(int $estateId, string $code, string $wabaId = '', string $phoneNumberId = ''): array
    {
        if (!$this->configured()) {
            throw new HttpException(422, 'WHATSAPP_SIGNUP_NOT_CONFIGURED', 'Meta Embedded Signup is not configured.');
        }

        $code = trim($code);

        if ($code === '') {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Meta did not return a connection code. Try connecting again.');
        }

        $token = $this->exchangeCode($code);
        $wabaId = trim($wabaId);
        $phoneNumberId = trim($phoneNumberId);

        if ($wabaId === '') {
            $wabaId = $this->firstAssignedWaba($token);
        }

        if ($phoneNumberId === '') {
            $phoneNumberId = $this->firstPhoneNumberId($token, $wabaId);
        }

        if ($phoneNumberId === '') {
            throw new HttpException(
                422,
                'WHATSAPP_CONNECT_INCOMPLETE',
                'Meta connected the business but no WhatsApp phone number was found. Add the phone number in Meta and connect again.'
            );
        }

        WhatsAppSettingsService::make($this->context)->saveConnected($estateId, $phoneNumberId, $wabaId, $token);

        return ['phone_number_id' => $phoneNumberId, 'waba_id' => $wabaId, 'connected' => true];
    }

    /**
     * Exchange the OAuth code for a (long-lived) business access token.
     */
    public function exchangeCode(string $code): string
    {
        $version = (string) Config::get('whatsapp.graph_version', 'v21.0');
        $appId = (string) Config::get('whatsapp.app_id', '');
        $secret = (string) Config::get('whatsapp.app_secret', '');

        $shortUrl = 'https://graph.facebook.com/' . $version . '/oauth/access_token?'
            . http_build_query([
                'client_id' => $appId,
                'client_secret' => $secret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);

        $short = $this->graphGet($shortUrl);
        $shortToken = trim((string) ($short['access_token'] ?? ''));

        if ($shortToken === '') {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Meta rejected the WhatsApp connection. Try connecting again.');
        }

        $longUrl = 'https://graph.facebook.com/' . $version . '/oauth/access_token?'
            . http_build_query([
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $secret,
                'fb_exchange_token' => $shortToken,
            ]);

        $long = $this->graphGet($longUrl);
        $longToken = trim((string) ($long['access_token'] ?? ''));

        return $longToken !== '' ? $longToken : $shortToken;
    }

    private function firstAssignedWaba(string $token): string
    {
        $version = (string) Config::get('whatsapp.graph_version', 'v21.0');
        $response = $this->graphGet('https://graph.facebook.com/' . $version . '/me/assigned_whatsapp_business_accounts', $token);
        $first = $response['data'][0]['id'] ?? '';

        return trim((string) $first);
    }

    private function firstPhoneNumberId(string $token, string $wabaId): string
    {
        if ($wabaId === '') {
            return '';
        }

        $version = (string) Config::get('whatsapp.graph_version', 'v21.0');
        $response = $this->graphGet('https://graph.facebook.com/' . $version . '/' . rawurlencode($wabaId) . '/phone_numbers', $token);
        $first = $response['data'][0]['id'] ?? '';

        return trim((string) $first);
    }

    /**
     * @return array<string, mixed>
     */
    private function graphGet(string $url, string $accessToken = ''): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        try {
            $response = ($this->transport)($url, $headers);
        } catch (\Throwable) {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Could not reach Meta to finish the WhatsApp connection.');
        }

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($status < 200 || $status >= 300 || !is_array($decoded) || isset($decoded['error'])) {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Meta rejected the WhatsApp connection. Try connecting again.');
        }

        return $decoded;
    }

    /**
     * Default cURL GET transport (tests inject a fake).
     *
     * @param array<string, string> $headers
     *
     * @return array{status:int, body:string}
     */
    public static function defaultGet(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'HTTP transport is unavailable.');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Could not reach Meta.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new HttpException(422, 'WHATSAPP_CONNECT_FAILED', 'Could not reach Meta.');
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
