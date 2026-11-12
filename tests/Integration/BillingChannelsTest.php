<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Modules\Billing\Automation\BillingReminderService;
use App\Modules\Notifications\BillingNotificationService;
use App\Modules\Notifications\ChannelSettingsService;

/**
 * Inc-13 P2 notification channels / billing delivery.
 *
 * Covers: email + in-app always available; SMS/WhatsApp only with a configured
 * gateway (otherwise skipped with `*_not_configured`); estate subset intersection;
 * fake-gateway sends; per-channel failure isolation with attempts; dedupe +
 * `sent_at`; reminder stages/markers with idempotent re-runs.
 */
final class BillingChannelsTest extends AdminModuleTestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array<string, mixed> */
    private array $originalSms = [];

    /** @var array<string, mixed> */
    private array $originalAutomation = [];

    private string $sandbox = '';

    public function setUp(): void
    {
        parent::setUp();

        foreach (['SMS_API_KEY', 'WHATSAPP_API_KEY', 'SMS_FAKE_SEND', 'WHATSAPP_FAKE_SEND', 'SMS_DRIVER'] as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
        }

        $this->sandbox = 'qa-billing-channels-' . bin2hex(random_bytes(4));
        // Snapshot the shared config first: Config is process-wide, so leaving a
        // fake SMS driver/automation flag behind would leak into later tests
        // (OTP delivery, parking SMS assertions).
        $this->originalSms = (array) Config::get('sms', []);
        $this->originalAutomation = (array) Config::get('billing.automation', []);
        Config::set('sms.driver', 'log');
        Config::set('billing.automation', array_merge($this->originalAutomation, ['enabled' => true]));
    }

    public function tearDown(): void
    {
        Config::set('sms', $this->originalSms);
        Config::set('billing.automation', $this->originalAutomation);

        foreach ($this->env as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        parent::tearDown();
    }

    private function context(): Context
    {
        return new Context(
            new Request('GET', '/qa-' . $this->sandbox, [], [], [], null, '127.0.0.1', 'MtaaPaaS-Test/1.0'),
            $this->sandbox
        );
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM billing_invoices WHERE kind = 'utility' ORDER BY id ASC LIMIT 1"
        );
        $this->assertNotNull($row, 'The seeded A-001 utility invoice is required.');

        return $row;
    }

    /** @param array<string, bool> $channels */
    private function setGlobalChannels(array $channels): void
    {
        $this->db->execute(
            "UPDATE platform_settings SET `value` = :value WHERE `key` = 'notifications.channels.available'",
            ['value' => json_encode($channels, JSON_UNESCAPED_SLASHES)]
        );
    }

    /** @param array<int, string> $channels */
    private function setEstateChannels(int $estateId, array $channels): void
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key',
            ['estate_id' => $estateId, 'key' => 'billing.notification_channels']
        );
        $payload = ['estate_id' => $estateId, 'setting_key' => 'billing.notification_channels', 'setting_value' => json_encode($channels)];

        if ($existing === null) {
            $this->db->insert('estate_settings', $payload + ['updated_at' => Clock::nowDb()]);
        } else {
            $this->db->update('estate_settings', ['setting_value' => $payload['setting_value']], ['id' => (int) $existing['id']]);
        }
    }

    private function channels(): ChannelSettingsService
    {
        return ChannelSettingsService::make($this->context());
    }

    private function configureSmsGateway(): void
    {
        Config::set('sms.driver', 'fakegateway');
        putenv('SMS_API_KEY=qa-sms-key');
        $this->setGlobalChannels(['email' => true, 'in_app' => true, 'sms' => true, 'whatsapp' => false]);
    }

    private function configureWhatsAppGateway(): void
    {
        putenv('WHATSAPP_API_KEY=qa-whatsapp-key');
        $this->setGlobalChannels(['email' => true, 'in_app' => true, 'sms' => false, 'whatsapp' => true]);
    }

    /** @return array<int, array<string, mixed>> */
    private function channelRows(string $invoiceUuid): array
    {
        return $this->db->select(
            "SELECT channel, status, error_code, attempts, dedupe_key, sent_at
             FROM notification_logs WHERE dedupe_key LIKE :p ORDER BY id ASC",
            ['p' => '%' . $invoiceUuid . '%']
        );
    }

    // ---------------------------------------------------------------------
    // Availability
    // ---------------------------------------------------------------------

    public function testEmailAndInAppAreAlwaysAvailable(): void
    {
        $this->setGlobalChannels(['email' => false, 'in_app' => false, 'sms' => false, 'whatsapp' => false]);

        $global = $this->channels()->globalChannels();
        $this->assertSame(true, $global['email'], 'Email is always available.');
        $this->assertSame(true, $global['in_app'], 'In-app is always available.');

        $estate = $this->seededEstate(self::ESTATE_SLUG);
        $this->setEstateChannels((int) $estate['id'], ['email']);
        $resolved = $this->channels()->estateChannels((int) $estate['id']);
        $this->assertSame(true, $resolved['email']);
        $this->assertSame(true, $resolved['in_app']);
    }

    public function testSmsAndWhatsAppAreGatedWithoutAConfiguredGateway(): void
    {
        // Stored "enabled" but no real gateway/keys → resolver keeps them off.
        $this->setGlobalChannels(['email' => true, 'in_app' => true, 'sms' => true, 'whatsapp' => true]);

        $global = $this->channels()->globalChannels();
        $this->assertSame(false, $global['sms'], 'SMS needs a non-log driver + API key.');
        $this->assertSame(false, $global['whatsapp'], 'WhatsApp needs a gateway key.');

        $invoice = $this->invoice();
        $result = BillingNotificationService::make($this->context())->deliverInvoiceEvent($invoice, 'generated');
        $errors = array_column($this->channelRows((string) $invoice['uuid']), 'error_code', 'channel');
        $this->assertSame('sms_not_configured', (string) ($errors['sms'] ?? ''));
        $this->assertSame('whatsapp_not_configured', (string) ($errors['whatsapp'] ?? ''));
    }

    public function testFakeSmsGatewaySendsAndMarksTheRow(): void
    {
        $this->configureSmsGateway();
        putenv('SMS_FAKE_SEND=1');
        $estate = $this->seededEstate(self::ESTATE_SLUG);
        $this->setEstateChannels((int) $estate['id'], ['email', 'in_app', 'sms']);

        $invoice = $this->invoice();
        $result = BillingNotificationService::make($this->context())->deliverInvoiceEvent($invoice, 'generated');

        $this->assertSame('sent', $result['channels']['sms'] ?? null, 'The fake gateway records a successful send.');
        $row = $this->db->selectOne(
            "SELECT status, sent_at FROM notification_logs WHERE channel = 'sms' AND dedupe_key LIKE :p ORDER BY id DESC LIMIT 1",
            ['p' => '%' . $invoice['uuid'] . '%']
        );
        $this->assertNotNull($row);
        $this->assertSame('sent', (string) $row['status']);
        $this->assertNotEmpty($row['sent_at']);
    }

    public function testEstateSubsetIntersectsGlobalAvailability(): void
    {
        $this->configureSmsGateway();
        putenv('SMS_FAKE_SEND=1');
        $estate = $this->seededEstate(self::ESTATE_SLUG);

        // Estate selects only email/in-app despite the global SMS availability.
        $this->setEstateChannels((int) $estate['id'], ['email', 'in_app']);
        $resolved = $this->channels()->estateChannels((int) $estate['id']);
        $this->assertSame(false, $resolved['sms'], 'The estate subset can only narrow global availability.');

        $invoice = $this->invoice();
        BillingNotificationService::make($this->context())->deliverInvoiceEvent($invoice, 'generated');
        $errors = array_column($this->channelRows((string) $invoice['uuid']), 'error_code', 'channel');
        $this->assertSame('sms_not_configured', (string) ($errors['sms'] ?? ''), 'The estate subset keeps SMS off even when globally available.');
    }

    // ---------------------------------------------------------------------
    // Failures / retries / dedupe
    // ---------------------------------------------------------------------

    public function testEmailFailureIsIsolatedAndRetriedWithAttempts(): void
    {
        $jane = $this->seededUser(self::JANE);
        $this->db->execute('UPDATE users SET email = :email WHERE id = :id', ['email' => 'jane.qa@example.test', 'id' => (int) $jane['id']]);
        $estate = $this->seededEstate(self::ESTATE_SLUG);
        $this->setEstateChannels((int) $estate['id'], ['email', 'in_app']);
        $invoice = $this->invoice();

        $failing = BillingNotificationService::make($this->context(), static function (): void {
            throw new \RuntimeException('smtp down');
        });
        $first = $failing->deliverInvoiceEvent($invoice, 'generated');
        $this->assertTrue(
            in_array($first['channels']['email'] ?? null, ['pending', 'failed'], true),
            'A failed email attempt is isolated as pending/failed, got ' . var_export($first['channels']['email'] ?? null, true) . '.'
        );

        $row = $this->db->selectOne(
            "SELECT status, attempts, next_attempt_at, error_code FROM notification_logs
             WHERE channel = 'email' AND dedupe_key LIKE :p ORDER BY id ASC LIMIT 1",
            ['p' => '%' . $invoice['uuid'] . '%']
        );
        $this->assertNotNull($row);
        $this->assertTrue(
            in_array((string) $row['status'], ['queued', 'pending', 'failed'], true),
            'A failed send is recorded as queued/pending (retryable) or failed after the attempt threshold.'
        );
        $this->assertSame(1, (int) $row['attempts'], 'A failed send records one attempt.');
        $this->assertNotEmpty($row['next_attempt_at'], 'A retry time is scheduled.');

        $sending = BillingNotificationService::make($this->context(), static function (): void {
        });
        $second = $sending->deliverInvoiceEvent($invoice, 'generated');
        $this->assertSame('sent', $second['channels']['email'] ?? null, 'A retry delivers the email.');
        $this->assertSame('deduped', $second['channels']['in_app'] ?? null, 'The in-app row is deduped on retry.');

        $retried = $this->db->selectOne(
            "SELECT status, sent_at FROM notification_logs WHERE channel = 'email' AND dedupe_key LIKE :p ORDER BY id ASC LIMIT 1",
            ['p' => '%' . $invoice['uuid'] . '%']
        );
        $this->assertSame('sent', (string) $retried['status']);
        $this->assertNotEmpty($retried['sent_at']);
    }

    public function testInAppDedupeAndSentAtMarker(): void
    {
        $invoice = $this->invoice();
        $service = BillingNotificationService::make($this->context());

        $first = $service->deliverInvoiceEvent($invoice, 'generated');
        $this->assertTrue((int) ($first['sent'] ?? 0) >= 1);

        $sentAt = $this->db->scalar('SELECT sent_at FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertNotEmpty($sentAt, 'A successful generated delivery stamps billing_invoices.sent_at.');

        $second = $service->deliverInvoiceEvent($invoice, 'generated');
        $this->assertSame('deduped', $second['channels']['in_app'] ?? null, 'Repeat delivery dedupes the in-app row.');

        $inAppRows = $this->db->select(
            "SELECT id FROM notification_logs WHERE channel = 'in_app' AND dedupe_key LIKE :p",
            ['p' => '%' . $invoice['uuid'] . '%']
        );
        $this->assertCount(1, $inAppRows, 'Exactly one in-app row per recipient/dedupe key.');
    }

    // ---------------------------------------------------------------------
    // Reminders
    // ---------------------------------------------------------------------

    public function testReminderBeforeDueIsDeliveredOnceAndMarksTheInvoice(): void
    {
        $invoice = $this->invoice();
        $asOf = Clock::now();

        // Three days before due → before_due reminder.
        $this->db->execute(
            'UPDATE billing_invoices SET status = :status, due_at = :due, reminder_sent_at = NULL, last_reminder_stage = NULL WHERE id = :id',
            ['status' => 'open', 'due' => Clock::toDb($asOf->modify('+3 days')), 'id' => (int) $invoice['id']]
        );

        $service = BillingReminderService::make($this->context());
        $first = $service->run($asOf);
        $this->assertSame(1, (int) ($first['counts']['reminders'] ?? 0), 'The before-due reminder is sent once.');

        $row = $this->db->selectOne('SELECT last_reminder_stage, reminder_sent_at FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('before_due', (string) $row['last_reminder_stage']);
        $this->assertNotEmpty($row['reminder_sent_at']);

        $second = $service->run($asOf);
        $this->assertSame(0, (int) ($second['counts']['reminders'] ?? 0), 'A re-run dedupes the reminder.');
        $this->assertTrue((int) ($second['skips']['deduped'] ?? 0) >= 1);

        $audit = $this->db->selectOne("SELECT metadata FROM audit_logs WHERE action = 'billing.reminder_sent' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
        $this->assertStringContainsString('before_due', (string) $audit['metadata']);
    }

    public function testReminderOnDueStageIsAlsoIdempotent(): void
    {
        $invoice = $this->invoice();
        $asOf = Clock::now();
        $this->db->execute(
            'UPDATE billing_invoices SET status = :status, due_at = :due, reminder_sent_at = NULL, last_reminder_stage = NULL WHERE id = :id',
            ['status' => 'open', 'due' => Clock::toDb($asOf), 'id' => (int) $invoice['id']]
        );

        $service = BillingReminderService::make($this->context());
        $first = $service->run($asOf);
        $this->assertSame(1, (int) ($first['counts']['reminders'] ?? 0));

        $row = $this->db->selectOne('SELECT last_reminder_stage FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('on_due', (string) $row['last_reminder_stage']);

        $second = $service->run($asOf);
        $this->assertSame(0, (int) ($second['counts']['reminders'] ?? 0));
    }
}
