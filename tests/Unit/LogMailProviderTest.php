<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Integrations\Mail\LogMailProvider;
use App\Integrations\Mail\MailMessage;
use Tests\TestCase;

/**
 * Development log mail adapter (email-pwa-greeting-contract §1.2): writes one
 * redacted JSON line per send to the configured log path — masked recipients,
 * redacted subject, no body, no credentials.
 */
final class LogMailProviderTest extends TestCase
{
    private string $logPath = '';

    /** @var mixed */
    private $originalPath = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalPath = Config::get('mail.log_path');
        $this->logPath = 'storage/temporary/mail-test-' . bin2hex(random_bytes(6)) . '.log';
        Config::set('mail.log_path', $this->logPath);
    }

    public function tearDown(): void
    {
        $absolute = BASE_PATH . '/' . $this->logPath;

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        Config::set('mail.log_path', $this->originalPath);

        parent::tearDown();
    }

    private function readLines(): array
    {
        $absolute = BASE_PATH . '/' . $this->logPath;

        if (!is_file($absolute)) {
            return [];
        }

        $lines = file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $line): array => (array) json_decode($line, true), $lines);
    }

    public function testSendWritesOneRedactedJsonLine(): void
    {
        $provider = new LogMailProvider();
        $this->assertSame('log', $provider->name());

        $delivery = $provider->send(new MailMessage(
            ['jane.smith@example.com', 'ops@example.org'],
            'MtaaPass: Visitor checked in — Test Visitor PIN 123456 code 654321',
            "Body must never be logged.\nPIN 123456",
            null,
            null,
            ['event' => 'visitor.checked_in']
        ));

        $this->assertSame('log', $delivery['provider']);
        $this->assertSame('sent', $delivery['status']);
        $this->assertStringStartsWith('log_', (string) $delivery['message_id']);

        $lines = $this->readLines();
        $this->assertCount(1, $lines);
        $record = $lines[0];

        $this->assertSame('log', (string) $record['driver']);
        $this->assertSame('sent', (string) $record['status']);
        $this->assertSame(['j***@example.com', 'o***@example.org'], $record['to']);
        $this->assertSame(['event'], $record['metadata_keys']);

        $raw = (string) json_encode($record);

        // No raw address, body, PIN/OTP digits or credentials may reach the log.
        $this->assertStringNotContainsString('jane.smith@example.com', $raw);
        $this->assertStringNotContainsString('ops@example.org', $raw);
        $this->assertStringNotContainsString('Body must never be logged', $raw);
        $this->assertStringNotContainsString('123456', $raw);
        $this->assertStringNotContainsString('654321', $raw);
        $this->assertStringContainsString('[redacted]', (string) $record['subject']);
    }

    public function testRepeatedSendsAppendLines(): void
    {
        $provider = new LogMailProvider();
        $provider->send(new MailMessage(['a@example.com'], 'First subject', 'Body'));
        $provider->send(new MailMessage(['b@example.com'], 'Second subject', 'Body'));

        $lines = $this->readLines();
        $this->assertCount(2, $lines);
        $this->assertSame(['a***@example.com'], $lines[0]['to']);
        $this->assertSame(['b***@example.com'], $lines[1]['to']);
    }

    public function testSendingWithoutConfiguredRecipientsIsAnInvalidMessage(): void
    {
        try {
            new MailMessage([], 'Subject', 'Body');
            $this->fail('The value object must reject an empty recipient list.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
