<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Integrations\Mail\LogMailProvider;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailSendException;
use App\Integrations\Mail\SmtpMailProvider;
use Tests\TestCase;

/**
 * Extra mail edge cases added by parallel QA (T47) on top of the T35b suites:
 *
 *  - SMTP failure mapping for greeting 554 and RCPT 550 (the existing suite maps
 *    MAIL FROM 550 only),
 *  - HELO fallback when the server rejects EHLO,
 *  - MIME-encoded UTF-8 Subject header,
 *  - transport selection (ssl://465, tcp://587, tcp://plain) + timeout and
 *    unreachable-socket mapping through the injected connector (the existing
 *    transcript tests never exercise the connector),
 *  - LogMailProvider's exact redacted record shape (no body, credentials, extra
 *    fields or API key material).
 *
 * Transport seams only: no network connection and no real credentials are ever
 * used; every credential value below is an obvious test sentinel.
 */
final class MailEdgeCaseTest extends TestCase
{
    private const PASSWORD = 'edge-smtp-secret-value';

    private const RESEND_KEY = 're_edge_secret_key_value';

    /** @var array<int, string> */
    private array $commands = [];

    /**
     * @param array<int, string> $responses
     * @return callable(?string): string
     */
    private function transcript(array $responses): callable
    {
        $queue = $responses;
        $this->commands = [];

        return function (?string $command) use (&$queue): string {
            $this->commands[] = $command ?? '[greeting]';
            $response = array_shift($queue);

            if ($response === null) {
                throw new \RuntimeException('Unexpected SMTP command: ' . ($command ?? '[greeting]'));
            }

            return $response;
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function config(string $encryption = 'ssl', array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.test',
            'port' => $encryption === 'ssl' ? 465 : 587,
            'username' => 'resend',
            'password' => self::PASSWORD,
            'encryption' => $encryption,
            'timeout' => 5,
        ], $overrides);
    }

    private function message(string $subject = 'Test subject'): MailMessage
    {
        return new MailMessage(['receiver@example.test'], $subject, "Hello,\nBody line");
    }

    /**
     * @return array<int, string>
     */
    private function successScript(): array
    {
        return [
            '220 smtp.test ESMTP ready',
            "250-smtp.test\r\n250-SIZE 10485760\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME",
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Sender OK',
            '250 2.1.5 Recipient OK',
            '354 Start mail input',
            '250 2.0.0 Ok: queued as EDGE123',
            '221 2.0.0 Bye',
        ];
    }

    public function testGreetingRejectionIsMappedToMailSendFailed(): void
    {
        $provider = new SmtpMailProvider($this->transcript(['554 5.3.2 Service unavailable']), $this->config());

        try {
            $provider->send($this->message());
            $this->fail('A 554 SMTP greeting must throw.');
        } catch (MailSendException $exception) {
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
            $this->assertSame(554, $exception->httpStatus());
            $this->assertSame('smtp', $exception->provider());
            $this->assertStringNotContainsString('Service unavailable', $exception->getMessage(), 'Server text must never leak.');
            $this->assertStringNotContainsString('smtp.test', $exception->getMessage());
        }
    }

    public function testRcptRejectionIsMappedWithoutLeakingTheRecipient(): void
    {
        $script = $this->successScript();
        // Replace the RCPT TO success (index 6 after greeting/EHLO/auth) with 550.
        $script[6] = '550 5.1.1 Recipient rejected';

        $provider = new SmtpMailProvider($this->transcript($script), $this->config());

        try {
            $provider->send($this->message());
            $this->fail('A rejected RCPT TO must throw.');
        } catch (MailSendException $exception) {
            $this->assertSame(550, $exception->httpStatus());
            $this->assertStringNotContainsString('receiver@example.test', $exception->getMessage(), 'Recipients must never leak into exceptions.');
            $this->assertStringNotContainsString('Recipient rejected', $exception->getMessage());
        }
    }

    public function testHeloFallbackIsUsedWhenEhloIsRejected(): void
    {
        $script = [
            '220 smtp.test ESMTP',
            '500 5.5.1 Command not recognized',
            '250 smtp.test hello',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '250 Sender OK',
            '250 Recipient OK',
            '354 Start mail input',
            '250 2.0.0 Ok: queued as HELO123',
            '221 Bye',
        ];

        $provider = new SmtpMailProvider($this->transcript($script), $this->config());
        $delivery = $provider->send($this->message());

        $this->assertSame('sent', $delivery['status']);
        $this->assertSame('HELO123', $delivery['message_id']);
        $this->assertStringStartsWith('EHLO ', (string) $this->commands[1]);
        $this->assertStringStartsWith('HELO ', (string) $this->commands[2], 'EHLO rejection must fall back to HELO.');
        $this->assertSame('AUTH LOGIN', $this->commands[3], 'Authentication continues after the HELO fallback.');
    }

    public function testNonAsciiSubjectIsMimeEncoded(): void
    {
        $subject = 'MtaaPass: Visitor checked in — Café';
        $provider = new SmtpMailProvider($this->transcript($this->successScript()), $this->config());

        $provider->send($this->message($subject));
        $payload = (string) $this->commands[8];

        $this->assertStringContainsString('Subject: ', $payload);

        if (function_exists('mb_encode_mimeheader')) {
            $this->assertStringContainsString('=?UTF-8?', $payload, 'A non-ASCII subject must be MIME-encoded.');
            $this->assertStringNotContainsString('—', $payload, 'Raw multi-byte subject text must not be sent as a header.');
        } else {
            // No mbstring: the provider degrades to the raw value (documented).
            $this->assertStringContainsString($subject, $payload);
        }
    }

    public function testConnectorSelectsTransportFromEncryptionAndPassesTimeout(): void
    {
        $cases = [
            ['ssl', 465, 'ssl://smtp.test:465'],
            ['tls', 587, 'tcp://smtp.test:587'],
            ['none', 2525, 'tcp://smtp.test:2525'],
        ];

        foreach ($cases as [$encryption, $port, $expectedRemote]) {
            $captured = [];
            $connector = static function (string $remote, int $timeout, $context) use (&$captured) {
                $captured = [
                    'remote' => $remote,
                    'timeout' => $timeout,
                    'context' => is_resource($context),
                ];

                return false;
            };

            $provider = new SmtpMailProvider(
                null,
                $this->config($encryption, ['port' => $port, 'timeout' => 7]),
                $connector
            );

            try {
                $provider->send($this->message());
                $this->fail('A refused connector socket must throw for ' . $encryption . '.');
            } catch (MailSendException $exception) {
                $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
                $this->assertNull($exception->httpStatus(), 'A socket failure is a network error, not an HTTP status.');
                $this->assertSame('smtp', $exception->provider());
            }

            $this->assertSame($expectedRemote, $captured['remote'] ?? null, 'Wrong transport URL for encryption ' . $encryption . '.');
            $this->assertSame(7, $captured['timeout'] ?? null, 'The configured timeout must reach the connector.');
            $this->assertTrue((bool) ($captured['context'] ?? false), 'A stream context (certificate verification) must be passed.');
        }
    }

    public function testMissingCredentialsNeverOpenAConnection(): void
    {
        $called = false;
        $connector = static function (string $remote, int $timeout, $context) use (&$called) {
            $called = true;

            return false;
        };

        $provider = new SmtpMailProvider(null, $this->config('ssl', ['password' => '']), $connector);

        try {
            $provider->send($this->message());
            $this->fail('Sending without SMTP credentials must fail safely.');
        } catch (MailSendException $exception) {
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
            $this->assertSame('smtp', $exception->provider());
        }

        $this->assertFalse($called, 'Misconfigured SMTP must never attempt a network connection.');
    }

    public function testLogRecordHasAnExactRedactedShapeAndNoCredentials(): void
    {
        $original = (array) Config::get('mail', []);
        $logPath = 'storage/temporary/mail-edge-' . bin2hex(random_bytes(5)) . '.log';

        Config::set('mail', array_merge($original, [
            'resend' => ['key' => self::RESEND_KEY, 'url' => 'https://api.resend.com'],
            'smtp' => [
                'host' => 'smtp.test',
                'port' => 465,
                'username' => 'resend',
                'password' => self::PASSWORD,
                'encryption' => 'ssl',
                'timeout' => 5,
            ],
            'log_path' => $logPath,
        ]));

        $absolute = BASE_PATH . '/' . $logPath;

        try {
            $delivery = (new LogMailProvider())->send(new MailMessage(
                ['jane.smith@example.com'],
                'Visitor checked in — PIN 123456 token 654321',
                "Secret body with OTP 998877\nPIN 123456"
            ));

            $this->assertSame('log', $delivery['provider']);
            $this->assertSame('sent', $delivery['status']);

            $lines = is_file($absolute)
                ? (file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
                : [];
            $this->assertCount(1, $lines, 'Exactly one log line must be written per send.');

            $record = (array) json_decode((string) $lines[0], true);

            // The record is deliberately minimal: no body, headers or credential fields.
            $this->assertSame(
                ['time', 'driver', 'message_id', 'to', 'subject', 'metadata_keys', 'status'],
                array_keys($record),
                'The log record must contain only the documented redacted fields.'
            );
            $this->assertSame(['j***@example.com'], $record['to']);
            $this->assertSame('sent', $record['status']);
            $this->assertSame('log', $record['driver']);
            $this->assertStringContainsString('[redacted]', (string) $record['subject']);

            $raw = (string) json_encode($record);

            foreach (['jane.smith', self::RESEND_KEY, self::PASSWORD, 'Secret body', '998877'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, 'The mail log must never contain ' . $secret . '.');
            }
        } finally {
            Config::set('mail', $original);

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }
}
