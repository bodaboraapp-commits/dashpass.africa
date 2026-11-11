<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use App\Core\Config;
use App\Core\Logger;
use Throwable;

/**
 * Resend email provider (https://resend.com).
 *
 * POST `{RESEND_API_URL}/emails` with `Authorization: Bearer <key>` from
 * configuration; 2xx is success, every other status or a network error becomes a
 * safe {@see MailSendException} (`MAIL_SEND_FAILED`). The key, recipients and
 * provider response body are never written to logs or exceptions.
 *
 * The HTTP transport is injectable so tests can exercise payload construction and
 * error mapping without any network access; production uses cURL (or PHP streams
 * when cURL is unavailable).
 */
final class ResendMailProvider implements MailProvider
{
    /**
     * @var callable(string, array<string, string>, string, int): array{status: int, body: string}|null
     */
    private $transport;

    private readonly string $apiKey;

    private readonly string $apiUrl;

    private readonly int $timeout;

    /**
     * @param callable(string, array<string, string>, string, int): array{status: int, body: string}|null $transport
     */
    public function __construct(
        ?callable $transport = null,
        ?string $apiKey = null,
        ?string $apiUrl = null,
        ?int $timeout = null
    ) {
        $this->transport = $transport;
        $this->apiKey = trim($apiKey ?? (string) Config::get('mail.resend.key', ''));
        $this->apiUrl = rtrim(trim($apiUrl ?? (string) Config::get('mail.resend.url', 'https://api.resend.com')), '/');
        $configuredTimeout = $timeout ?? (int) Config::get('mail.timeout', 10);
        $this->timeout = max(1, $configuredTimeout);
    }

    public function send(MailMessage $message): array
    {
        if ($this->apiKey === '') {
            // Disabled no-op: missing configuration must never be fatal.
            Logger::warning('Resend API key is not configured; email alerts are disabled.');

            return [
                'message_id' => '',
                'provider' => 'resend',
                'status' => 'disabled',
            ];
        }

        $payload = [
            'from' => $this->fromAddress(),
            'to' => $message->to,
            'subject' => $message->subject,
            'text' => $message->text,
        ];

        if ($message->html !== null && trim($message->html) !== '') {
            $payload['html'] = $message->html;
        }

        if ($message->replyTo !== null) {
            $payload['reply_to'] = $message->replyTo;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw MailSendException::unreachable('resend');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->dispatch($this->apiUrl . '/emails', $headers, $body, $this->timeout);
        } catch (MailSendException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw MailSendException::unreachable('resend');
        }

        $status = $response['status'];

        if ($status < 200 || $status >= 300) {
            throw MailSendException::rejected('resend', $status);
        }

        $decoded = json_decode($response['body'], true);
        $messageId = is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : '';

        return [
            'message_id' => $messageId,
            'provider' => 'resend',
            'status' => 'sent',
        ];
    }

    public function name(): string
    {
        return 'resend';
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function dispatch(string $url, array $headers, string $body, int $timeout): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($url, $headers, $body, $timeout);

            if (!is_array($response) || !isset($response['status'], $response['body'])) {
                throw MailSendException::unreachable('resend');
            }

            return ['status' => (int) $response['status'], 'body' => (string) $response['body']];
        }

        return function_exists('curl_init')
            ? $this->dispatchWithCurl($url, $headers, $body, $timeout)
            : $this->dispatchWithStreams($url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function dispatchWithCurl(string $url, array $headers, string $body, int $timeout): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw MailSendException::unreachable('resend');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            curl_close($curl);
            throw MailSendException::unreachable('resend');
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function dispatchWithStreams(string $url, array $headers, string $body, int $timeout): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            throw MailSendException::unreachable('resend');
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function fromAddress(): string
    {
        $address = trim((string) Config::get('mail.from.address', 'onboarding@resend.dev'));
        $name = trim((string) Config::get('mail.from.name', ''));

        if ($address === '') {
            $address = 'onboarding@resend.dev';
        }

        return $name === '' ? $address : sprintf('%s <%s>', $name, $address);
    }
}
