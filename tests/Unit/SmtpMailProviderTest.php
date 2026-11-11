<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailSendException;
use App\Integrations\Mail\SmtpMailProvider;
use Tests\TestCase;

/**
 * SMTP provider protocol tests (email-pwa-greeting-contract §1.2, T35 update):
 * dependency-free SMTP over injected transcript I/O — implicit TLS (465) vs
 * STARTTLS (587), multiline EHLO parsing, AUTH LOGIN + AUTH PLAIN fallback,
 * MAIL FROM/RCPT TO/DATA framing with dot-stuffing, failure mapping with no
 * credential/response leakage. No network is ever contacted.
 */
final class SmtpMailProviderTest extends TestCase
{
    private const PASSWORD = 'super-secret-smtp-key-value';

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
                // Fail loudly if the provider sends more commands than scripted.
                throw new \RuntimeException('Unexpected SMTP command: ' . ($command ?? '[greeting]'));
            }

            return $response;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $encryption = 'ssl'): array
    {
        return [
            'host' => 'smtp.test',
            'port' => $encryption === 'ssl' ? 465 : 587,
            'username' => 'resend',
            'password' => self::PASSWORD,
            'encryption' => $encryption,
            'timeout' => 5,
        ];
    }

    private function message(): MailMessage
    {
        return new MailMessage(
            ['receiver@example.test'],
            'Test subject',
            "Hello,\nThis is a test.\n.dot-stuffed line\n".'
            .END'
        );
    }

    /**
     * @param array<int, string> $afterAuth
     * @return array<int, string>
     */
    private function successScript(array $afterAuth = [], string $authFlow = 'login'): array
    {
        $script = [];

        // open(): greeting + EHLO (multiline capability response).
        $script[] = "220 smtp.test ESMTP ready";
        $script[] = "250-smtp.test\r\n250-SIZE 10485760\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME";

        if ($authFlow === 'login') {
            $script[] = '334 VXNlcm5hbWU6';
            $script[] = '334 UGFzc3dvcmQ6';
            $script[] = '235 2.7.0 Authentication successful';
        } elseif ($authFlow === 'plain') {
            $script[] = '504 5.5.4 Unrecognized authentication type';
            $script[] = '235 2.7.0 Authentication successful';
        }

        $script[] = '250 2.1.0 Sender OK';      // MAIL FROM
        $script[] = '250 2.1.5 Recipient OK';   // RCPT TO
        $script[] = '354 Start mail input';      // DATA
        $script[] = '250 2.0.0 Ok: queued as ABC123XYZ'; // DATA body
        $script[] = '221 2.0.0 Bye';             // QUIT

        return array_merge($script, $afterAuth);
    }

    public function testImplicitTlsSendFlowUsesEhloAuthAndFraming(): void
    {
        $provider = new SmtpMailProvider($this->transcript($this->successScript()), $this->config('ssl'));

        $delivery = $provider->send($this->message());

        $this->assertSame('smtp', $delivery['provider']);
        $this->assertSame('sent', $delivery['status']);
        $this->assertSame('ABC123XYZ', $delivery['message_id']);
        $this->assertSame('smtp', $provider->name());

        $joined = implode("\n", $this->commands);

        $this->assertStringStartsWith('EHLO ', (string) $this->commands[1]);
        $this->assertSame('AUTH LOGIN', $this->commands[2]);
        // Implicit TLS has no STARTTLS command.
        $this->assertStringNotContainsString('STARTTLS', $joined);
        $this->assertStringContainsString('MAIL FROM:<', $joined);
        $this->assertStringContainsString('RCPT TO:<receiver@example.test>', $joined);
        $this->assertSame('DATA', $this->commands[7]);
        $this->assertSame('QUIT', $this->commands[9]);
    }

    public function testDataPayloadUsesHeadersCrlfAndDotStuffing(): void
    {
        $provider = new SmtpMailProvider($this->transcript($this->successScript()), $this->config('ssl'));
        $provider->send($this->message());

        $payload = $this->commands[8];

        $this->assertStringContainsString('From: ', $payload);
        $this->assertStringContainsString('To: receiver@example.test', $payload);
        $this->assertStringContainsString('Subject: Test subject', $payload);
        $this->assertStringContainsString('MIME-Version: 1.0', $payload);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $payload);
        $this->assertStringContainsString("\r\n", $payload, 'The DATA payload must use CRLF framing.');
        $this->assertStringContainsString('..dot-stuffed line', $payload, 'A leading dot must be dot-stuffed.');
        $this->assertStringEndsWith(".\r\n", $payload, 'The DATA payload must terminate with a single dot line.');

        // The SMTP password must never be sent in clear anywhere.
        $this->assertStringNotContainsString(self::PASSWORD, implode("\n", $this->commands));
    }

    public function testStartTlsFlowUpgradesBeforeAuthentication(): void
    {
        // tls (STARTTLS): greeting, EHLO, STARTTLS/220, EHLO again, then auth+send.
        $script = [
            '220 smtp.test ESMTP',
            "250-smtp.test\r\n250-AUTH LOGIN PLAIN\r\n250 OK",
            '220 2.0.0 Ready to start TLS',
            "250 smtp.test\r\n250 AUTH LOGIN PLAIN",
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '250 Sender OK',
            '250 Recipient OK',
            '354 Start mail input',
            '250 2.0.0 Ok: queued as TLS999',
            '221 Bye',
        ];

        $provider = new SmtpMailProvider($this->transcript($script), $this->config('tls'));
        $delivery = $provider->send($this->message());

        $this->assertSame('TLS999', $delivery['message_id']);
        $this->assertSame('STARTTLS', $this->commands[2]);
        $this->assertStringStartsWith('EHLO ', (string) $this->commands[3], 'EHLO must be repeated after STARTTLS.');
    }

    public function testEhloMultilineCapabilitiesAreParsed(): void
    {
        // The AUTH capability is advertised in a multiline EHLO; the provider still
        // proceeds to AUTH LOGIN and succeeds.
        $provider = new SmtpMailProvider($this->transcript($this->successScript()), $this->config('ssl'));

        $delivery = $provider->send($this->message());
        $this->assertSame('sent', $delivery['status']);
    }

    public function testAuthPlainFallbackIsUsedWhenLoginIsRejected(): void
    {
        $provider = new SmtpMailProvider($this->transcript($this->successScript([], 'plain')), $this->config('ssl'));

        $delivery = $provider->send($this->message());

        $this->assertSame('sent', $delivery['status']);
        $this->assertSame('AUTH LOGIN', $this->commands[2]);
        $this->assertStringStartsWith('AUTH PLAIN ', (string) $this->commands[3]);

        // The PLAIN token is base64("\0user\0password") and never decoded in logs.
        $plain = substr((string) $this->commands[3], strlen('AUTH PLAIN '));
        $this->assertSame("\0resend\0" . self::PASSWORD, base64_decode($plain, true));
    }

    public function testAuthFailureIsMappedWithoutLeakingCredentials(): void
    {
        $script = [
            '220 smtp.test ESMTP',
            "250-smtp.test\r\n250 AUTH LOGIN PLAIN\r\n250 OK",
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '535 5.7.8 Authentication credentials invalid',
        ];

        $provider = new SmtpMailProvider($this->transcript($script), $this->config('ssl'));

        try {
            $provider->send($this->message());
            $this->fail('A 535 auth failure must throw.');
        } catch (MailSendException $exception) {
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
            $this->assertSame(535, $exception->httpStatus());
            $this->assertSame('smtp', $exception->provider());

            // No credentials, recipients or provider response text in the message.
            $message = $exception->getMessage();
            $this->assertStringNotContainsString(self::PASSWORD, $message);
            $this->assertStringNotContainsString(base64_encode(self::PASSWORD), $message);
            $this->assertStringNotContainsString('receiver@example.test', $message);
            $this->assertStringNotContainsString('Authentication credentials invalid', $message);
        }
    }

    public function testUnreachableServerIsMappedToMailSendFailed(): void
    {
        // Empty response = no SMTP code -> unreachable.
        $provider = new SmtpMailProvider(function (?string $command): string {
            return '';
        }, $this->config('ssl'));

        try {
            $provider->send($this->message());
            $this->fail('An unreachable SMTP server must throw.');
        } catch (MailSendException $exception) {
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
            $this->assertNull($exception->httpStatus());
        }
    }

    public function testProviderRejectionAtMailFromIsMapped(): void
    {
        $script = [
            '220 smtp.test ESMTP',
            "250-smtp.test\r\n250 AUTH LOGIN PLAIN\r\n250 OK",
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '550 5.1.1 Sender rejected',
        ];

        $provider = new SmtpMailProvider($this->transcript($script), $this->config('ssl'));

        try {
            $provider->send($this->message());
            $this->fail('A rejected MAIL FROM must throw.');
        } catch (MailSendException $exception) {
            $this->assertSame(550, $exception->httpStatus());
            $this->assertStringNotContainsString('Sender rejected', $exception->getMessage());
        }
    }

    public function testCheckAuthenticatesAndQuitsWithoutSending(): void
    {
        $script = [
            '220 smtp.test ESMTP',
            "250-smtp.test\r\n250 AUTH LOGIN PLAIN\r\n250 OK",
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 2.7.0 Authentication successful',
            '221 Bye',
        ];

        $provider = new SmtpMailProvider($this->transcript($script), $this->config('ssl'));
        $result = $provider->check();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('smtp.test', $result['host']);
        $this->assertSame(465, $result['port']);
        $this->assertSame('ssl', $result['encryption']);
        $this->assertNotContains('DATA', $this->commands);
        $this->assertSame('QUIT', $this->commands[count($this->commands) - 1]);
    }

    public function testCheckWithoutCredentialsThrows(): void
    {
        $config = $this->config('ssl');
        $config['password'] = '';
        $provider = new SmtpMailProvider(null, $config);

        $this->assertFalse($provider->hasCredentials());

        try {
            $provider->check();
            $this->fail('check() without credentials must fail.');
        } catch (MailSendException $exception) {
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
        }
    }

    public function testHasCredentialsRequiresHostUsernamePasswordAndValidEncryption(): void
    {
        $this->assertTrue((new SmtpMailProvider(null, $this->config('ssl')))->hasCredentials());
        $this->assertTrue((new SmtpMailProvider(null, $this->config('tls')))->hasCredentials());

        foreach ([
            ['host' => '', 'port' => 465, 'username' => 'u', 'password' => 'p', 'encryption' => 'ssl', 'timeout' => 5],
            ['host' => 'x', 'port' => 465, 'username' => '', 'password' => 'p', 'encryption' => 'ssl', 'timeout' => 5],
            ['host' => 'x', 'port' => 465, 'username' => 'u', 'password' => '', 'encryption' => 'ssl', 'timeout' => 5],
            ['host' => 'x', 'port' => 465, 'username' => 'u', 'password' => 'p', 'encryption' => 'bogus', 'timeout' => 5],
        ] as $config) {
            $this->assertFalse((new SmtpMailProvider(null, $config))->hasCredentials());
        }
    }
}
