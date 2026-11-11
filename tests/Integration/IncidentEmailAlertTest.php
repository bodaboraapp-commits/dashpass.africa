<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Response;

/**
 * Increment 6 — high/critical incident email alerts (docs/staff-incidents-contract.md
 * §3 "Email alert" + the Increment-5 EmailAlertService contract).
 *
 * Proves that a high or critical incident report emits a security alert through
 * the `log` mail adapter (`email.alert_sent` audit, masked recipients), that
 * low/medium reports and unconfigured recipients stay silent, that a mail
 * failure never blocks the report (`email.alert_failed`, incident still
 * created) and that alert logs/metadata carry no recipient, phone or OTP data.
 *
 * The adapter is always `log` or a deliberately failing log path — never a real
 * SMTP/provider connection, and never the owner's credentials.
 */
final class IncidentEmailAlertTest extends AdminModuleTestCase
{
    private const LOG_PATH = 'storage/temporary/incident-alert-test.log';

    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);

        foreach (['incident:', 'otp:', 'throttle:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }

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

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

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
     * @return array<int, array<string, mixed>>
     */
    private function alertAudits(): array
    {
        return $this->db->select("SELECT metadata FROM audit_logs WHERE action = 'email.alert_sent' ORDER BY id ASC");
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentRow(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM access_incidents WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($row === null) {
            $this->fail('Incident ' . $uuid . ' was not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFrom(array $data, string $key): array
    {
        $candidate = $data[$key] ?? $data;

        if (is_array($candidate) && array_is_list($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
            $candidate = $candidate[0];
        }

        if (!is_array($candidate) || !isset($candidate['uuid'])) {
            $this->fail(
                'Expected the response to expose a ' . $key . ' object with a uuid. '
                . 'Payload: ' . (string) json_encode($data)
            );
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function incidentCreateApi(array $overrides = [], string $resident = self::JANE): Response
    {
        $this->ensureLoggedInAs($resident);

        return $this->post('/api/v1/resident/incidents', array_merge([
            'incident_type' => 'unauthorised_access',
            'severity' => 'high',
            'summary' => 'QA alert incident ' . bin2hex(random_bytes(3)),
            'description' => 'QA alert incident description with no secrets.',
            'occurred_at' => Clock::toDb(Clock::now()->modify('-10 minutes')),
        ], $overrides), true);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createAlertIncident(array $overrides = [], string $resident = self::JANE): array
    {
        $payload = $this->assertSuccess(
            $this->incidentCreateApi($overrides, $resident),
            'Incident creation failed while testing the optional email alert.'
        );

        return $this->itemFrom($payload['data'], 'incident');
    }

    /* ------------------------------------------------------------------ */
    /* Tests                                                              */
    /* ------------------------------------------------------------------ */

    public function testHighAndCriticalIncidentsEmitAlertEmailAndAudit(): void
    {
        Config::set('mail.alert_to', ['ops@example.test']);

        $high = $this->createAlertIncident(['severity' => 'high', 'summary' => 'QA high severity alert']);

        $lines = $this->mailLines();
        $this->assertCount(1, $lines, 'A high-severity incident must send exactly one security alert email.');

        $line = $lines[0];
        $this->assertSame('log', (string) ($line['driver'] ?? ''));
        $this->assertSame('sent', (string) ($line['status'] ?? ''));
        $this->assertSame(['o***@example.test'], $line['to'] ?? [], 'Alert recipients must be masked in the mail log.');

        $subject = (string) ($line['subject'] ?? '');
        $this->assertNotSame('', $subject, 'The alert email must carry a subject.');
        $this->assertTrue(
            stripos($subject, 'incident') !== false
            || stripos($subject, 'security') !== false
            || stripos($subject, 'alert') !== false,
            'The alert subject must identify a security incident. Subject: ' . $subject
        );

        $raw = (string) json_encode($line);
        $this->assertStringNotContainsString('ops@example.test', $raw, 'The raw recipient address must never be logged.');
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $raw), 'Phone numbers must never appear in alert mail logs.');

        $audits = $this->alertAudits();
        $this->assertCount(1, $audits, 'Sending an alert must be audited as email.alert_sent.');

        $metadata = (string) $audits[0]['metadata'];
        $this->assertStringContainsString('incident.reported', $metadata, 'The alert audit must reference incident.reported.');
        $this->assertStringContainsString('o***@example.test', $metadata, 'The alert audit must carry masked recipients only.');
        $this->assertStringNotContainsString('ops@example.test', $metadata, 'The alert audit must never carry raw recipients.');
        $this->assertStringNotContainsString(
            'QA high severity alert',
            $metadata,
            'Alert audit metadata must not contain the incident summary.'
        );

        // The report itself always succeeds.
        $this->assertSame('open', (string) $this->incidentRow((string) $high['uuid'])['status']);

        // Critical severity must alert too.
        $critical = $this->createAlertIncident(['severity' => 'critical', 'summary' => 'QA critical severity alert']);
        $this->assertCount(2, $this->mailLines(), 'Critical severity must also send a security alert email.');
        $this->assertCount(2, $this->alertAudits(), 'Both high and critical alerts must be audited.');
        $this->assertSame('open', (string) $this->incidentRow((string) $critical['uuid'])['status']);
    }

    public function testLowAndMediumSeverityIncidentsDoNotSendAlerts(): void
    {
        Config::set('mail.alert_to', ['ops@example.test']);

        $this->createAlertIncident(['severity' => 'low', 'summary' => 'QA low severity alert']);
        $this->createAlertIncident(['severity' => 'medium', 'summary' => 'QA medium severity alert']);

        $this->assertSame([], $this->mailLines(), 'Only high/critical incidents may trigger security alert emails.');
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'email.alert_sent'"));
    }

    public function testNoConfiguredRecipientsMeansNoEmailAttempt(): void
    {
        Config::set('mail.alert_to', []);

        $incident = $this->createAlertIncident(['severity' => 'critical']);

        $this->assertSame([], $this->mailLines(), 'No recipients must mean no email is attempted.');
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'email.alert_sent'"));
        $this->assertSame('open', (string) $this->incidentRow((string) $incident['uuid'])['status'], 'The incident must still be created.');
    }

    public function testConfiguredRecipientsAreMaskedAndDeduped(): void
    {
        Config::set('mail.alert_to', ['ops@example.test', 'ops@example.test', 'backup@example.test']);

        $this->createAlertIncident(['severity' => 'high']);

        $lines = $this->mailLines();
        $this->assertCount(1, $lines);
        $this->assertSame(
            ['o***@example.test', 'b***@example.test'],
            $lines[0]['to'] ?? [],
            'Duplicate configured addresses must be deduplicated and every recipient masked.'
        );
    }

    public function testGuardReportedCriticalIncidentAlsoAlerts(): void
    {
        Config::set('mail.alert_to', ['guard-alerts@example.test']);

        $this->loginGuard();

        $summary = 'QA guard critical alert ' . bin2hex(random_bytes(3));
        $response = $this->post('/gate/incidents', [
            'incident_type' => 'tailgating',
            'severity' => 'critical',
            'summary' => $summary,
            'description' => 'QA guard critical incident report.',
            'occurred_at' => Clock::toDb(Clock::now()->modify('-3 minutes')),
        ], true);

        $this->assertStatus(302, $response, 'The guard report must succeed (PRG) even while an alert is sent.');

        $lines = $this->mailLines();
        $this->assertCount(1, $lines, 'A guard-reported critical incident must also send a security alert.');

        $audits = $this->alertAudits();
        $this->assertCount(1, $audits);
        $this->assertStringContainsString('incident.reported', (string) $audits[0]['metadata']);

        $row = $this->db->selectOne(
            'SELECT status, severity FROM access_incidents WHERE summary = :summary LIMIT 1',
            ['summary' => $summary]
        );
        $this->assertNotNull($row, 'The guard incident must still be persisted.');
        $this->assertSame('critical', (string) $row['severity']);
        $this->assertSame('open', (string) $row['status']);
    }

    public function testAlertDeliveryFailureNeverBlocksIncidentCreation(): void
    {
        Config::set('mail.alert_to', ['ops@example.test']);

        // Point the log adapter at a path whose parent is a regular file so the
        // provider throws when it tries to write (same technique as EmailAlertTest).
        $blocker = BASE_PATH . '/storage/temporary/incident-alert-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'block');
        Config::set('mail.log_path', 'storage/temporary/' . basename($blocker) . '/mail.log');

        try {
            $incident = $this->createAlertIncident(['severity' => 'critical', 'summary' => 'QA blocked alert report']);

            $row = $this->incidentRow((string) $incident['uuid']);
            $this->assertSame('open', (string) $row['status'], 'The incident must be created even when the alert fails.');

            $audit = $this->db->selectOne(
                "SELECT metadata FROM audit_logs WHERE action = 'email.alert_failed' ORDER BY id DESC LIMIT 1"
            );
            $this->assertNotNull($audit, 'A failed alert must be audited as email.alert_failed.');

            $metadata = (string) $audit['metadata'];
            $this->assertStringContainsString('incident.reported', $metadata, 'The failure audit must name the alert event.');
            $this->assertStringNotContainsString('ops@example.test', $metadata, 'Failure metadata must not leak raw addresses.');
            $this->assertSame(
                0,
                (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'email.alert_sent'"),
                'A failed delivery must not be recorded as a sent alert.'
            );
        } finally {
            @unlink($blocker);
        }
    }

    public function testAlertLogsCarryNoRecipientPhoneOrOtpData(): void
    {
        Config::set('mail.alert_to', ['privacy@example.test']);

        $incident = $this->createAlertIncident([
            'severity' => 'high',
            'summary' => 'QA privacy alert summary',
            'description' => 'QA privacy description with phone +254712345678 and OTP 246810.',
        ]);

        $lines = $this->mailLines();
        $this->assertCount(1, $lines);

        $raw = (string) json_encode($lines);
        $this->assertStringNotContainsString('privacy@example.test', $raw, 'Raw recipient addresses must never be logged.');
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $raw), 'Alert mail logs must never contain a phone number.');
        $this->assertStringNotContainsString('246810', $raw, 'Alert mail logs must never contain OTP/PIN digits.');

        $metadata = (string) ($this->alertAudits()[0]['metadata'] ?? '');
        $this->assertStringNotContainsString('privacy@example.test', $metadata);
        $this->assertStringNotContainsString('246810', $metadata);
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $metadata), 'Alert audit metadata must never contain a phone number.');
        $this->assertStringNotContainsString(
            'QA privacy description with phone',
            $metadata,
            'Alert audit metadata must not contain the incident description.'
        );

        $this->assertSame('open', (string) $this->incidentRow((string) $incident['uuid'])['status'], 'The incident must still be created.');
    }
}
