<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailProvider;
use App\Modules\Notifications\EmailAlertService;

/**
 * Parallel-QA (T47) gap coverage on top of EmailAlertTest, which owns the
 * end-to-end log-backend cases. These tests prove the parts the log adapter
 * cannot observe directly:
 *
 *  - the outgoing MailMessage body/subject carry only safe security facts and
 *    never a PIN, OTP, QR/pass token, phone number or raw recipient address;
 *  - the audience is host email first + configured fallback, deduplicated, and
 *    the audit row stores masked recipients only;
 *  - a checkout alert failure is audited as email.alert_failed and never blocks
 *    the checkout (the existing suite only proves check-in fail-open);
 *  - unsupported events are ignored without sending or auditing.
 *
 * The provider is an in-memory recording adapter and the context is built
 * directly: no network, no real credentials.
 */
final class EmailAlertPrivacyTest extends VisitorModuleTestCase
{
    private const LOG_PATH = 'storage/temporary/mail-alert-privacy.log';

    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalMail = (array) Config::get('mail', []);
        Config::set('mail.driver', 'log');
        Config::set('mail.log_path', self::LOG_PATH);
        Config::set('mail.alert_to', []);

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
     * An in-memory MailProvider that records every MailMessage instead of
     * contacting a provider.
     */
    private function recordingProvider(): MailProvider
    {
        return new class implements MailProvider {
            /** @var array<int, MailMessage> */
            public array $sent = [];

            public function send(MailMessage $message): array
            {
                $this->sent[] = $message;

                return ['message_id' => 'recording_1', 'provider' => 'recording', 'status' => 'sent'];
            }

            public function name(): string
            {
                return 'recording';
            }
        };
    }

    private function context(): Context
    {
        // Auth binds the session to a UA fingerprint, so the synthetic request
        // must present the same user agent as TestCase's HTTP helpers (see
        // Auth::fingerprint()); otherwise the session is destroyed and the
        // alert service resolves no tenant.
        return new Context(
            new Request('GET', '/app', [], [], [], null, '127.0.0.1', 'MtaaPass-Test/1.0'),
            'qa-mail-' . bin2hex(random_bytes(4))
        );
    }

    private function setHostEmail(?string $email): void
    {
        $this->db->execute(
            'UPDATE users SET email = :email WHERE phone = :phone',
            ['email' => $email, 'phone' => '+254700000010']
        );
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

    /**
     * Create a live invitation and check it in as the gate guard.
     *
     * @return array<string, mixed>
     */
    private function checkInVisitor(string $name = 'Privacy Alert Visitor'): array
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

    public function testAlertBodyCarriesOnlySafeSecurityFacts(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $hostEmail = 'jane.alerts@example.test';
        $this->setHostEmail($hostEmail);
        Config::set('mail.alert_to', ['ops@example.test', $hostEmail]);

        $recording = $this->recordingProvider();
        $pin = '482913';
        $otp = '771204';
        $token = str_repeat('ab12', 8);
        $phone = '+254712345678';

        $service = EmailAlertService::withProvider($this->context(), MailManager::withProvider($recording));

        $service->sendSecurityAlert('visitor.checked_in', [
            'visitor_name' => 'Alice Mwangi',
            'gate_name' => 'Main Gate',
            'estate_name' => 'Greenview Residences',
            'household_label' => 'Unit A-001',
            'occurred_at' => Clock::nowDb(),
            'timezone' => 'Africa/Nairobi',
            'host_email' => $hostEmail,
            // Credential/PII-shaped values that must never be interpolated.
            'pin' => $pin,
            'otp' => $otp,
            'qr_token' => $token,
            'phone' => $phone,
            'visitor_phone' => $phone,
        ]);

        $this->assertCount(1, $recording->sent);
        $message = $recording->sent[0];

        // Host address first, fallback inbox second, duplicate host address deduped.
        $this->assertSame(['jane.alerts@example.test', 'ops@example.test'], $message->to);
        $this->assertNull($message->html, 'Security alert email is plain text only.');
        $this->assertSame(['event' => 'visitor.checked_in'], $message->metadata);

        $this->assertStringContainsString('Visitor checked in', $message->subject);
        $this->assertStringContainsString('Alice Mwangi', $message->subject);

        $body = $message->text;
        $this->assertStringContainsString('Alice Mwangi', $body);
        $this->assertStringContainsString('Gate: Main Gate', $body);
        $this->assertStringContainsString('Estate: Greenview Residences', $body);
        $this->assertStringContainsString('Household: Unit A-001', $body);

        foreach ([$pin, $otp, $token, $phone, $hostEmail] as $secret) {
            $this->assertStringNotContainsString($secret, $body, 'The alert body must never contain ' . $secret . '.');
        }

        $this->assertFalse(
            (bool) preg_match('/\+2547\d{6,9}/', $body),
            'No phone number may appear in the alert body.'
        );

        // Audit carries masked recipients only.
        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'email.alert_sent' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit);
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString('visitor.checked_in', $metadata);
        $this->assertStringContainsString('j***@example.test', $metadata);
        $this->assertStringContainsString('o***@example.test', $metadata);
        $this->assertStringNotContainsString($hostEmail, $metadata);
        $this->assertStringNotContainsString($phone, $metadata);
        $this->assertStringNotContainsString($pin, $metadata);
    }

    public function testCheckoutAlertFailureIsAuditedAndNeverBlocksCheckout(): void
    {
        $this->setHostEmail('jane.alerts@example.test');

        $data = $this->checkInVisitor();
        $this->assertCount(1, $this->mailLines(), 'The check-in alert must be logged before the failure case.');

        // Break the log adapter's write path: its parent is a regular file, so the
        // provider throws and EmailAlertService must catch it.
        $blocker = BASE_PATH . '/storage/temporary/mail-privacy-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'block');
        Config::set('mail.log_path', 'storage/temporary/' . basename($blocker) . '/mail.log');

        try {
            $this->assertSuccess($this->guardCheckout((string) $data['invitation_uuid']));

            $invitation = $this->db->selectOne(
                'SELECT status FROM visitor_invitations WHERE uuid = :uuid',
                ['uuid' => (string) $data['invitation_uuid']]
            );
            $this->assertNotNull($invitation);
            $this->assertSame('checked_out', (string) $invitation['status'], 'The checkout must complete despite the mail failure.');

            $audit = $this->db->selectOne(
                "SELECT metadata FROM audit_logs WHERE action = 'email.alert_failed' ORDER BY id DESC LIMIT 1"
            );
            $this->assertNotNull($audit, 'A failed checkout alert must be audited as email.alert_failed.');

            $metadata = (string) $audit['metadata'];
            $this->assertStringContainsString('visitor.checked_out', $metadata);
            $this->assertStringNotContainsString('jane.alerts@example.test', $metadata, 'Failure metadata must not leak raw addresses.');
        } finally {
            @unlink($blocker);
        }
    }

    public function testUnsupportedEventIsIgnoredWithoutSendingOrAuditing(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $this->setHostEmail('jane.alerts@example.test');
        Config::set('mail.alert_to', ['ops@example.test']);

        $recording = $this->recordingProvider();
        $service = EmailAlertService::withProvider($this->context(), MailManager::withProvider($recording));

        $service->sendSecurityAlert('visitor.pin_revealed', [
            'host_email' => 'jane.alerts@example.test',
            'visitor_name' => 'Alice Mwangi',
        ]);

        $this->assertCount(0, $recording->sent, 'Only the three security events may be emailed.');
        $this->assertSame([], $this->mailLines());

        $audit = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('email.alert_sent', 'email.alert_failed')"
        );
        $this->assertSame(0, (int) $audit['c']);
    }
}
