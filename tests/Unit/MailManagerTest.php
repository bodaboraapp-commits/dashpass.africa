<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Integrations\Mail\LogMailProvider;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailProvider;
use App\Integrations\Mail\SmtpMailProvider;
use Tests\TestCase;

/**
 * Mail manager driver selection and configuration parsing
 * (email-pwa-greeting-contract §1.1/§1.2, T35 SMTP update).
 *
 * The test-only provider injection is also verified because every alert test
 * relies on it.
 */
final class MailManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    private array $mailConfig = [
        'driver' => 'log',
        'from' => ['address' => 'onboarding@resend.dev', 'name' => 'MtaaPass'],
        'reply_to' => '',
        'alert_to' => [],
        'resend' => ['key' => '', 'url' => 'https://api.resend.com'],
        'smtp' => [
            'host' => '',
            'port' => 465,
            'username' => '',
            'password' => '',
            'encryption' => 'ssl',
            'timeout' => 10,
        ],
        'timeout' => 10,
        'log_path' => 'storage/logs/mail.log',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->original = (array) Config::get('mail', []);
        Config::set('mail', $this->mailConfig);
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->original);
        parent::tearDown();
    }

    public function testDriversListIncludesLogSmtpAndResend(): void
    {
        $this->assertSame(['log', 'smtp', 'resend'], MailManager::DRIVERS);
    }

    public function testUnknownDriverFallsBackToLog(): void
    {
        Config::set('mail.driver', 'sendgrid');
        $manager = new MailManager();

        $this->assertSame('log', $manager->driver());
        $this->assertInstanceOf(LogMailProvider::class, $manager->make());
    }

    public function testLogDriverIsAlwaysConfiguredAndMakesLogProvider(): void
    {
        Config::set('mail.driver', 'log');
        $manager = new MailManager();

        $this->assertTrue($manager->isConfigured());
        $this->assertInstanceOf(LogMailProvider::class, $manager->make());
        $this->assertSame('log', $manager->provider()->name());
    }

    public function testSmtpDriverRequiresHostUsernamePasswordAndEncryption(): void
    {
        Config::set('mail.driver', 'smtp');
        $manager = new MailManager();

        // Nothing configured: not configured, and make() falls back to log safely.
        $this->assertFalse($manager->isConfigured());
        $this->assertInstanceOf(LogMailProvider::class, $manager->make());

        Config::set('mail.smtp', [
            'host' => 'smtp.resend.com',
            'port' => 465,
            'username' => 'resend',
            'password' => 'test-key',
            'encryption' => 'ssl',
            'timeout' => 10,
        ]);

        $configured = new MailManager();
        $this->assertTrue($configured->isConfigured());
        $this->assertSame('smtp', $configured->driver());
        $this->assertInstanceOf(SmtpMailProvider::class, $configured->make());
    }

    public function testSmtpDriverRejectsInvalidEncryptionConfiguration(): void
    {
        Config::set('mail.driver', 'smtp');
        Config::set('mail.smtp', [
            'host' => 'smtp.example.test',
            'port' => 465,
            'username' => 'user',
            'password' => 'secret',
            'encryption' => 'rot13',
            'timeout' => 10,
        ]);

        $manager = new MailManager();
        $this->assertFalse($manager->isConfigured(), 'An unknown encryption mode must not be considered configured.');
        $this->assertInstanceOf(LogMailProvider::class, $manager->make());
    }

    public function testResendDriverRequiresAnApiKey(): void
    {
        Config::set('mail.driver', 'resend');
        Config::set('mail.resend', ['key' => '', 'url' => 'https://api.resend.com']);

        $manager = new MailManager();
        $this->assertFalse($manager->isConfigured());

        // A missing key must not throw: the provider becomes a disabled no-op.
        $provider = $manager->make();
        $this->assertSame('resend', $provider->name());

        Config::set('mail.resend', ['key' => 're_test_key', 'url' => 'https://api.resend.com']);
        $this->assertTrue((new MailManager())->isConfigured());
    }

    public function testInjectedProviderIsUsedDirectly(): void
    {
        $recording = new class implements MailProvider {
            /** @var array<int, MailMessage> */
            public array $sent = [];

            public function send(MailMessage $message): array
            {
                $this->sent[] = $message;

                return ['message_id' => 'injected_1', 'provider' => 'test', 'status' => 'sent'];
            }

            public function name(): string
            {
                return 'test';
            }
        };

        $manager = MailManager::withProvider($recording);
        $this->assertTrue($manager->isConfigured());
        $this->assertSame($recording, $manager->provider());
        $this->assertSame($recording, $manager->make('smtp'), 'An injected provider overrides driver selection.');

        $delivery = $manager->send(new MailMessage(['jane@example.com'], 'Subject', 'Body'));
        $this->assertSame('injected_1', $delivery['message_id']);
        $this->assertSame('test', $delivery['provider']);
        $this->assertSame('sent', $delivery['status']);
        $this->assertCount(1, $recording->sent);
    }
}
