<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use Throwable;

/**
 * Dependency-free SMTP email provider (Resend SMTP in production).
 *
 * Uses PHP streams only:
 *  - implicit TLS on port 465 (`ssl://`),
 *  - STARTTLS upgrade on port 587 (`tls`), or plain TCP (`none`),
 *  - multiline EHLO/HELO capability parsing,
 *  - AUTH LOGIN with an AUTH PLAIN fallback,
 *  - MAIL FROM / RCPT TO / DATA with CRLF framing and dot-stuffing.
 *
 * Certificate verification uses PHP's secure stream-context defaults. Every
 * failure becomes a generic {@see MailSendException} (`MAIL_SEND_FAILED`) with at
 * most a stage + numeric status: credentials, recipients and server text are never
 * put in exceptions or logs.
 *
 * Two injection seams keep the protocol testable without a network:
 *  - `$io`: transcript mode, called with each client chunk and returning the
 *    server response (null chunk = read greeting),
 *  - `$connector`: replaces `stream_socket_client()` in real mode.
 */
final class SmtpMailProvider implements MailProvider
{
    /** @var array{host: string, port: int, username: string, password: string, encryption: string, timeout: int} */
    private array $config;

    private readonly string $fromAddress;

    private readonly string $fromName;

    private readonly ?string $domain;

    /**
     * @var (callable(?string): string)|null
     */
    private $io;

    /**
     * @var (callable(string, int, resource): (resource|false))|null
     */
    private $connector;

    /** @var resource|null */
    private $socket = null;

    private bool $transcript = false;

    /**
     * @param (callable(?string): string)|null $io
     * @param array<string, mixed>|null $config Overrides `config('mail.smtp')`.
     * @param (callable(string, int, resource): (resource|false))|null $connector
     */
    public function __construct(?callable $io = null, ?array $config = null, ?callable $connector = null)
    {
        $smtp = $config ?? (array) Config::get('mail.smtp', []);
        $timeout = (int) ($smtp['timeout'] ?? Config::get('mail.timeout', 10));

        $this->config = [
            'host' => trim((string) ($smtp['host'] ?? '')),
            'port' => max(1, (int) ($smtp['port'] ?? 465)),
            'username' => (string) ($smtp['username'] ?? ''),
            'password' => (string) ($smtp['password'] ?? ''),
            'encryption' => strtolower(trim((string) ($smtp['encryption'] ?? 'ssl'))),
            'timeout' => max(1, $timeout),
        ];

        $from = (array) Config::get('mail.from', []);
        $this->fromAddress = trim((string) ($from['address'] ?? 'onboarding@resend.dev'));
        $this->fromName = trim((string) ($from['name'] ?? ''));
        $this->domain = $this->clientDomain();
        $this->io = $io;
        $this->connector = $connector;
    }

    public function name(): string
    {
        return 'smtp';
    }

    /**
     * True when host + SMTP credentials are present (used by MailManager).
     */
    public function hasCredentials(): bool
    {
        return $this->config['host'] !== ''
            && $this->config['username'] !== ''
            && $this->config['password'] !== ''
            && in_array($this->config['encryption'], ['ssl', 'tls', 'none'], true);
    }

    /**
     * Connect + EHLO + (STARTTLS) + AUTH only; never sends a message.
     *
     * @return array{status: string, host: string, port: int, encryption: string}
     */
    public function check(): array
    {
        try {
            $this->open();
            $this->authenticate();
            $this->quit();

            return [
                'status' => 'ok',
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => $this->config['encryption'],
            ];
        } catch (MailSendException $exception) {
            $this->close();

            throw $exception;
        } catch (Throwable) {
            $this->close();

            throw MailSendException::unreachable('smtp');
        }
    }

    public function send(MailMessage $message): array
    {
        try {
            $this->open();
            $this->authenticate();

            $this->expect($this->turn('MAIL FROM:<' . $this->fromAddress . '>'), [250], 'MAIL FROM');

            foreach ($message->to as $recipient) {
                $this->expect($this->turn('RCPT TO:<' . $recipient . '>'), [250, 251], 'RCPT TO');
            }

            $this->expect($this->turn('DATA'), [354], 'DATA');

            $generatedId = $this->messageId();
            $payload = $this->buildPayload($message, $generatedId);
            $response = $this->turn($payload);
            $this->expect($response, [250], 'DATA body');

            $messageId = $this->extractMessageId($response, $generatedId);
            $this->quit();

            return [
                'message_id' => $messageId,
                'provider' => 'smtp',
                'status' => 'sent',
            ];
        } catch (MailSendException $exception) {
            $this->close();

            throw $exception;
        } catch (Throwable $exception) {
            $this->close();
            Logger::warning('SMTP delivery failed with an unexpected error.', [
                'provider' => 'smtp',
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);

            throw MailSendException::unreachable('smtp');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Connection / protocol                                              */
    /* ------------------------------------------------------------------ */

    private function open(): void
    {
        if ($this->io !== null) {
            $this->transcript = true;
            $this->expect($this->turn(null), [220], 'greeting');
            $this->identify();

            if ($this->config['encryption'] === 'tls') {
                $this->expect($this->turn('STARTTLS'), [220], 'STARTTLS');
                $this->identify();
            }

            return;
        }

        if (!$this->hasCredentials()) {
            throw MailSendException::rejected('smtp', 0);
        }

        $encryption = $this->config['encryption'];
        $remote = ($encryption === 'ssl' ? 'ssl' : 'tcp')
            . '://' . $this->config['host'] . ':' . $this->config['port'];

        $options = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $this->config['host'],
            ],
        ];

        $context = stream_context_create($options);
        $socket = false;

        if ($this->connector !== null) {
            $socket = ($this->connector)($remote, $this->config['timeout'], $context);
        } else {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $this->config['timeout'],
                STREAM_CLIENT_CONNECT,
                $context
            );
        }

        if (!is_resource($socket)) {
            throw MailSendException::unreachable('smtp');
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->config['timeout']);

        $this->expect($this->turn(null), [220], 'greeting');
        $this->identify();

        if ($encryption === 'tls') {
            $this->expect($this->turn('STARTTLS'), [220], 'STARTTLS');

            $enabled = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($enabled !== true) {
                throw MailSendException::rejected('smtp', 0);
            }

            $this->identify();
        }
    }

    /**
     * EHLO with a HELO fallback, ignoring capability details (AUTH is probed by
     * sending the command, which works with every server).
     */
    private function identify(): void
    {
        $name = $this->domain ?? 'localhost';
        $response = $this->turn('EHLO ' . $name);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $this->expect($this->turn('HELO ' . $name), [250], 'HELO');
        }
    }

    private function authenticate(): void
    {
        $username = $this->config['username'];
        $password = $this->config['password'];

        if ($username === '' || $password === '') {
            return;
        }

        $login = $this->turn('AUTH LOGIN');

        if ($login['code'] === 334) {
            $this->expect($this->turn(base64_encode($username)), [334], 'AUTH LOGIN username');
            $passwordResponse = $this->turn(base64_encode($password));

            if (in_array($passwordResponse['code'], [235, 503], true)) {
                return;
            }

            throw MailSendException::rejected('smtp', $passwordResponse['code']);
        }

        // AUTH PLAIN fallback (RFC 4616): "\0user\0password" base64-encoded.
        $plain = base64_encode("\0" . $username . "\0" . $password);
        $plainResponse = $this->turn('AUTH PLAIN ' . $plain);

        if (in_array($plainResponse['code'], [235, 503], true)) {
            return;
        }

        throw MailSendException::rejected('smtp', $plainResponse['code']);
    }

    private function quit(): void
    {
        try {
            $this->turn('QUIT');
        } catch (Throwable) {
            // The message was already accepted; a failed QUIT is not an error.
        }

        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->transcript = false;
    }

    /**
     * @return array{code: int, lines: array<int, string>}
     */
    private function turn(?string $command): array
    {
        if ($this->transcript) {
            $raw = (string) ($this->io)($command);

            return $this->parse($raw);
        }

        if (!is_resource($this->socket)) {
            throw MailSendException::unreachable('smtp');
        }

        if ($command !== null) {
            $written = @fwrite($this->socket, str_replace("\n", "\r\n", rtrim($command, "\r\n")) . "\r\n");

            if ($written === false) {
                throw MailSendException::unreachable('smtp');
            }
        }

        return $this->readResponse();
    }

    /**
     * @return array{code: int, lines: array<int, string>}
     */
    private function readResponse(): array
    {
        $lines = [];

        while (true) {
            $line = @fgets($this->socket, 4096);

            if ($line === false || $line === '') {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];

                throw ($meta['timed_out'] ?? false)
                    ? MailSendException::unreachable('smtp')
                    : MailSendException::unreachable('smtp');
            }

            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                continue;
            }

            if ($matches[2] === ' ') {
                break;
            }
        }

        return $this->parse(implode("\r\n", $lines));
    }

    /**
     * @return array{code: int, lines: array<int, string>}
     */
    private function parse(string $raw): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($raw)) ?: [];
        $code = 0;

        foreach ($lines as $line) {
            if (preg_match('/^(\d{3})([ -])/', $line, $matches) === 1) {
                $code = (int) $matches[1];
            }
        }

        if ($code === 0) {
            throw MailSendException::unreachable('smtp');
        }

        return ['code' => $code, 'lines' => array_values(array_filter($lines, static fn (string $line): bool => $line !== ''))];
    }

    /**
     * @param array{code: int, lines: array<int, string>} $response
     * @param array<int, int> $expected
     */
    private function expect(array $response, array $expected, string $stage): void
    {
        if (!in_array($response['code'], $expected, true)) {
            throw new MailSendException(
                sprintf('MAIL_SEND_FAILED: SMTP %s failed (status %d).', $stage, $response['code']),
                $response['code'],
                'smtp'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Message framing                                                    */
    /* ------------------------------------------------------------------ */

    private function buildPayload(MailMessage $message, string $messageId): string
    {
        $headers = [
            'Date: ' . Clock::now()->format(DATE_RFC2822),
            'From: ' . $this->formatAddress($this->fromAddress, $this->fromName),
            'To: ' . implode(', ', $message->to),
            'Subject: ' . $this->encodeHeader($message->subject),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo;
        }

        $body = str_replace(["\r\n", "\r"], "\n", $message->text);
        $body = rtrim($body, "\n");
        $lines = $body === '' ? [] : explode("\n", $body);

        // RFC 5321 dot-stuffing: a body line starting with "." gets an extra ".".
        $stuffed = array_map(
            static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line,
            $lines
        );

        return implode("\r\n", $headers)
            . "\r\n\r\n"
            . implode("\r\n", $stuffed)
            . "\r\n.\r\n";
    }

    private function formatAddress(string $address, string $name): string
    {
        return $name === '' ? $address : sprintf('%s <%s>', $name, $address);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }

        return $value;
    }

    private function messageId(): string
    {
        return bin2hex(random_bytes(16)) . '@' . ($this->domain ?? 'localhost');
    }

    /**
     * @param array{code: int, lines: array<int, string>} $response
     */
    private function extractMessageId(array $response, string $fallback): string
    {
        foreach ($response['lines'] as $line) {
            if (preg_match('/queued as ([A-Za-z0-9._-]+)/i', $line, $matches) === 1) {
                return $matches[1];
            }

            if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return $fallback;
    }

    private function clientDomain(): ?string
    {
        $host = function_exists('gethostname') ? gethostname() : false;

        if (is_string($host) && $host !== '' && preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1) {
            return $host;
        }

        return null;
    }
}
