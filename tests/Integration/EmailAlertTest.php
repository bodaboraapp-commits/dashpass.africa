<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Clock;
use App\Integrations\Sms\MockSmsProvider;
use Tests\TestCase;

/**
 * Security email alerts through MailManager (email-pwa-greeting-contract
 * §1.3/§1.4, §4): recipient selection (host email + MAIL_ALERT_TO dedupe),
 * no-recipient skip, privacy (no PIN/OTP/token/phone/raw address) and
 * post-commit non-blocking behaviour. Always uses the `log` adapter or an
 * injected mock — never a real SMTP connection.
 */
final class EmailAlertTest extends VisitorModuleTestCase
{
    private const LOG_PATH = 'storage/temporary/mail-alert-test.log';

    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalMail = (array) Config::get('mail', []);
        Config::set('mail.driver', 'log');
        Config::set('mail.log_path', self::LOG_PATH);
        Config::set('mail.alert_to', []);
        MockSmsProvider::reset();

        $absolute = BASE_PATH . '/' . self::LOG_PATH;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->originalMail);
        $absolute = BASE_PATH . '/' . self::LOG_PATH;
        if (is_file($absolute)) {
            @unlink($absolute);
        }

        parent::tearDown();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mailLines(): array
    {
        $absolute = BASE_PATH . '/' . self::LOG_PATH;

        if (!is_file($absolute)) {
            return [];
        }

        $lines = file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $line): array => (array) json_decode($line, true), $lines);
    }

    private function setHostEmail(?string $email): void
    {
        $this->db->execute(
            'UPDATE users SET email = :email WHERE phone = :phone',
            ['email' => $email, 'phone' => '+254700000010']
        );
    }

    /**
     * Create a live invitation and check the visitor in as the guard.
     *
     * @return array<string, mixed> Data from the alert audit row.
     */
    private function checkInVisitor(string $name = 'Email Alert Visitor'): array
    {
        $data = $this->createInvitation(['visitor_name' => $name]);

        $this->loginGuard();
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $this->assertSuccess($this->guardCheckin(
            (string) $data['invitation_uuid'],
            (string) $verified['data']['credential_uuid']
        ));

        return $data;
    }

    public function testVisitorCheckinEmailsTheHostWhenAnEmailExists(): void
    {
        $this->setHostEmail('jane.alerts@example.test');
        $data = $this->checkInVisitor();

        $lines = $this->mailLines();
        $this->assertCount(1, $lines, 'One security alert email must be logged for the check-in.');

        $line = $lines[0];
        $this->assertSame('log', (string) $line['driver']);
        $this->assertSame('sent', (string) $line['status']);
        $this->assertSame(['j***@example.test'], $line['to'], 'The recipient must be masked in the mail log.');

        $raw = (string) json_encode($line);
        $this->assertStringNotContainsString('jane.alerts@example.test', $raw, 'The raw host email must never be logged.');

        // PIN/OTP/token digits and phone numbers never appear in the log line.
        $this->assertStringNotContainsString((string) $data['pin'], $raw);
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $raw), 'Phone numbers must never appear in mail logs.');
        $this->assertFalse((bool) preg_match('/\b[0-9a-f]{32,}\b/i', $raw), 'No QR/pass tokens may appear in mail logs.');

        // Audit row with masked recipients only.
        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'email.alert_sent' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'A successful alert must be audited as email.alert_sent.');
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString('visitor.checked_in', $metadata);
        $this->assertStringContainsString('j***@example.test', $metadata);
        $this->assertStringNotContainsString('jane.alerts@example.test', $metadata);
        $this->assertStringNotContainsString((string) $data['pin'], $metadata);

        // SMS behaviour is unchanged.
        $sms = $this->db->selectOne("SELECT COUNT(*) AS c FROM notification_logs WHERE template = 'visitor_checked_in'");
        $this->assertTrue((int) $sms['c'] > 0, 'The existing visitor SMS must still be recorded.');
    }

    public function testFallbackInboxReceivesTheAlertWhenHostHasNoEmail(): void
    {
        $this->setHostEmail(null);
        Config::set('mail.alert_to', ['ops@example.test']);

        $this->checkInVisitor();

        $lines = $this->mailLines();
        $this->assertCount(1, $lines);
        $this->assertSame(['o***@example.test'], $lines[0]['to']);
    }

    public function testHostAndFallbackRecipientsAreDeduped(): void
    {
        $this->setHostEmail('ops@example.test');
        Config::set('mail.alert_to', ['ops@example.test', 'backup@example.test']);

        $this->checkInVisitor();

        $lines = $this->mailLines();
        $this->assertCount(1, $lines);
        $this->assertSame(['o***@example.test', 'b***@example.test'], $lines[0]['to'], 'Duplicate addresses must be deduped.');
    }

    public function testNoRecipientsMeansNoEmailAndNoAlertAudit(): void
    {
        $this->setHostEmail(null);
        Config::set('mail.alert_to', []);

        $this->checkInVisitor();

        $this->assertSame([], $this->mailLines(), 'No recipients must mean no email is attempted.');
        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'email.alert_sent'");
        $this->assertSame(0, (int) $audit['c']);
    }

    public function testCheckoutSendsACheckedOutAlert(): void
    {
        $this->setHostEmail('jane.alerts@example.test');
        $data = $this->checkInVisitor();

        $this->assertSuccess($this->guardCheckout((string) $data['invitation_uuid']));

        $lines = $this->mailLines();
        $this->assertCount(2, $lines, 'Check-in and checkout each emit one alert.');
        $this->assertSame(['j***@example.test'], $lines[1]['to']);

        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'email.alert_sent' AND metadata LIKE '%visitor.checked_out%' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit);
    }

    public function testWalkInCreationSendsAnAlert(): void
    {
        $this->setHostEmail('jane.alerts@example.test');

        $this->loginGuard();
        $response = $this->post('/api/v1/gate/walkins', [
            'visitor_name' => 'Email Alert Walk-in',
            'visitor_phone' => '+254700099931',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Mail alert walk-in',
        ], true);
        $this->assertSuccess($response);

        $lines = $this->mailLines();
        $this->assertCount(1, $lines);
        $this->assertSame(['j***@example.test'], $lines[0]['to']);

        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'email.alert_sent' AND metadata LIKE '%walkin.created%' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'A walk-in alert must be audited.');
    }

    public function testMailFailureNeverBlocksTheVisitorCheckin(): void
    {
        $this->setHostEmail('jane.alerts@example.test');

        // Point the log adapter at a path whose parent is a regular file so the
        // provider throws when it tries to write.
        $blocker = BASE_PATH . '/storage/temporary/mail-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'block');
        Config::set('mail.log_path', 'storage/temporary/' . basename($blocker) . '/mail.log');

        try {
            $data = $this->checkInVisitor();

            // The visitor flow must still succeed end to end.
            $invitation = $this->db->selectOne('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => (string) $data['invitation_uuid']]);
            $this->assertNotNull($invitation);
            $this->assertSame('checked_in', (string) $invitation['status']);

            $audit = $this->db->selectOne(
                "SELECT metadata FROM audit_logs WHERE action = 'email.alert_failed' ORDER BY id DESC LIMIT 1"
            );
            $this->assertNotNull($audit, 'A provider failure must be audited as email.alert_failed.');

            $metadata = (string) $audit['metadata'];
            $this->assertStringContainsString('visitor.checked_in', $metadata);
            $this->assertStringNotContainsString('jane.alerts@example.test', $metadata, 'Alert failure metadata must not leak raw addresses.');
        } finally {
            @unlink($blocker);
        }
    }
}
