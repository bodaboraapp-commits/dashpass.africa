<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Uuid;
use App\Modules\Parking\ParkingOverstayService;

/**
 * Increment 8 — parking overstay detection job
 * (docs/parking-administration-contract.md §2 "Overstay job", §4, §6).
 *
 * Exercises the frozen T60 seam **in process** (no CLI subprocess):
 *
 *   ParkingOverstayService::make(?DateTimeImmutable $now = null)
 *     ->run(bool $dryRun = false, ?int $estateId = null): array
 *   [
 *     'dry_run' => bool, 'estates' => int, 'scanned' => int,
 *     'overstayed' => int, 'flagged' => int, 'violations_created' => int,
 *     'skipped' => int, 'processed_at' => 'Y-m-d\TH:i:s.v\Z',
 *   ]
 *
 * Covers dry-run vs apply, deterministic grace handling (0/30/120 minutes),
 * flagging `parking_sessions.status='overstay'`, exactly one open `overstay`
 * violation per bay even with repeated sessions/runs, `parking.overstay_flagged`
 * audit (uuid-only, no PII), active/not-due/completed/non-invitation sessions
 * untouched, and the pre-existing enum (no schema change).
 *
 * PRE-WRITTEN for the Inc-8 gate: parked in storage/temporary/pending-tests/
 * until T60 lands and the Lead opens the gate.
 */
final class ParkingOverstayJobTest extends ParkingModuleTestCase
{
    private const GRACE_SETTING = 'parking_overstay_grace_minutes';

    /** @var array<int, string> */
    private const TABLES = ['parking_zones', 'parking_bays', 'parking_allocations', 'parking_reservations', 'parking_sessions', 'parking_violations'];

    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);

        foreach (['parking:', 'violation:', 'otp:', 'throttle:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                           */
    /* ------------------------------------------------------------------ */

    private function estateId(): int
    {
        return (int) $this->estate()['id'];
    }

    private function setOverstayGrace(int $minutes): void
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :setting_key LIMIT 1',
            ['estate_id' => $this->estateId(), 'setting_key' => self::GRACE_SETTING]
        );

        if ($existing !== null) {
            $this->db->update(
                'estate_settings',
                ['setting_value' => (string) json_encode($minutes)],
                ['id' => (int) $existing['id']]
            );

            return;
        }

        $this->db->insert('estate_settings', [
            'estate_id' => $this->estateId(),
            'setting_key' => self::GRACE_SETTING,
            'setting_value' => (string) json_encode($minutes),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationById(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM visitor_invitations WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($row === null) {
            $this->fail('Invitation ' . $id . ' was not found.');
        }

        return $row;
    }

    /**
     * Create a real resident invitation via the product flow, shift its validity
     * window into the past (dedicated fixture only) and insert the matching
     * active parking session for a dedicated bay.
     *
     * @return array{invitation_uuid: string, invitation_id: int, session_uuid: string, session_id: int, bay_id: int, bay_code: string}
     */
    private function dueSessionFixture(string $bayCode, int $minutesPastDue = 120, int $graceSetting = 30): array
    {
        $this->setOverstayGrace($graceSetting);
        $this->ensureLoggedInAs(self::JANE);

        $data = $this->createInvitation([
            'household_uuid' => $this->householdUuid('A-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('-30 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+30 minutes')),
        ]);

        $invitationUuid = (string) $data['invitation_uuid'];
        $invitation = $this->db->selectOne(
            'SELECT id FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $invitationUuid]
        );
        $this->assertNotNull($invitation, 'The dedicated invitation must exist.');

        $this->db->execute(
            'UPDATE visitor_invitations SET valid_from = :valid_from, valid_until = :valid_until WHERE id = :id',
            [
                'valid_from' => Clock::toDb(Clock::now()->modify('-4 hours')),
                'valid_until' => Clock::toDb(Clock::now()->modify('-' . $minutesPastDue . ' minutes')),
                'id' => (int) $invitation['id'],
            ]
        );

        $bay = $this->seededBay($bayCode);
        $sessionUuid = Uuid::v4();

        $sessionId = $this->db->insert('parking_sessions', [
            'uuid' => $sessionUuid,
            'estate_id' => $this->estateId(),
            'bay_id' => (int) $bay['id'],
            'invitation_id' => (int) $invitation['id'],
            'checkin_at' => Clock::toDb(Clock::now()->modify('-5 hours')),
            'checkout_at' => null,
            'status' => 'active',
        ]);

        return [
            'invitation_uuid' => $invitationUuid,
            'invitation_id' => (int) $invitation['id'],
            'session_uuid' => $sessionUuid,
            'session_id' => $sessionId,
            'bay_id' => (int) $bay['id'],
            'bay_code' => (string) $bay['bay_code'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRowById(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM parking_sessions WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($row === null) {
            $this->fail('Parking session ' . $id . ' was not found.');
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overstayViolations(): array
    {
        return $this->db->select(
            "SELECT id, uuid, bay_id, violation_type, status, plate_number, notes, reported_by_user_id
             FROM parking_violations WHERE estate_id = :estate_id AND violation_type = 'overstay' ORDER BY id ASC",
            ['estate_id' => $this->estateId()]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overstayAudits(): array
    {
        return $this->db->select(
            "SELECT entity_type, entity_id, metadata FROM audit_logs WHERE action = 'parking.overstay_flagged' ORDER BY id ASC"
        );
    }

    private function service(): ParkingOverstayService
    {
        return ParkingOverstayService::make();
    }

    /* ------------------------------------------------------------------ */
    /* Tests                                                              */
    /* ------------------------------------------------------------------ */

    public function testSchemaAlreadySupportsOverstayAndNoNewTableIsNeeded(): void
    {
        $columnType = (string) $this->db->scalar(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parking_sessions' AND COLUMN_NAME = 'status' LIMIT 1"
        );

        $this->assertStringContainsString('overstay', $columnType, 'parking_sessions.status must already allow the overstay value (no schema change).');

        $violationType = (string) $this->db->scalar(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parking_violations' AND COLUMN_NAME = 'violation_type' LIMIT 1"
        );
        $this->assertStringContainsString('overstay', $violationType, 'parking_violations.violation_type must already allow overstay.');
    }

    public function testDryRunReportsOverstaysWithoutFlaggingOrWriting(): void
    {
        $fixture = $this->dueSessionFixture('V-03', 120, 30);
        $tablesBefore = $this->tableInventory();

        $result = $this->service()->run(true, $this->estateId());

        $this->assertTrue($result['dry_run'] === true, 'A dry run must be marked dry_run=true.');
        $this->assertSame(1, (int) $result['estates'], 'The estate-scoped run must process exactly one estate.');
        $this->assertTrue((int) $result['scanned'] >= 1, 'The dry run must scan the active sessions.');
        $this->assertSame(1, (int) $result['overstayed'], 'The past-due session must be detected.');
        $this->assertSame(0, (int) $result['flagged'], 'A dry run must never flag a session.');
        $this->assertSame(0, (int) $result['violations_created'], 'A dry run must never create a violation.');
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T/', (string) $result['processed_at'], 'processed_at must be an ISO-8601 timestamp.');

        $session = $this->sessionRowById($fixture['session_id']);
        $this->assertSame('active', (string) $session['status'], 'A dry run must leave the session active.');
        $this->assertNull($session['checkout_at'], 'A dry run must not touch checkout_at.');
        $this->assertSame([], $this->overstayViolations(), 'A dry run must not create violations.');
        $this->assertSame([], $this->overstayAudits(), 'A dry run must not write overstay audits.');
        $this->assertSame($tablesBefore, $this->tableInventory(), 'A dry run must not change row counts.');
    }

    public function testApplyFlagsTheSessionCreatesOneViolationAndAudits(): void
    {
        $fixture = $this->dueSessionFixture('V-04', 120, 30);

        $result = $this->service()->run(false, $this->estateId());

        $this->assertSame(false, $result['dry_run']);
        $this->assertSame(1, (int) $result['overstayed']);
        $this->assertSame(1, (int) $result['flagged'], 'The past-due session must be flagged.');
        $this->assertSame(1, (int) $result['violations_created'], 'Exactly one overstay violation must be created.');

        $session = $this->sessionRowById($fixture['session_id']);
        $this->assertSame('overstay', (string) $session['status'], 'The job must set status=overstay.');
        $this->assertNull($session['checkout_at'], 'Flagging must not check the visitor out.');

        $violations = $this->overstayViolations();
        $this->assertCount(1, $violations, 'Exactly one overstay violation must exist.');
        $this->assertSame((int) $fixture['bay_id'], (int) $violations[0]['bay_id'], 'The violation must reference the session bay.');
        $this->assertSame('open', (string) $violations[0]['status']);
        $this->assertSame('overstay', (string) $violations[0]['violation_type']);

        $audits = $this->overstayAudits();
        $this->assertCount(1, $audits, 'Flagging must be audited as parking.overstay_flagged.');

        $metadata = (string) $audits[0]['metadata'];
        $this->assertStringContainsString((string) $fixture['session_uuid'], $metadata, 'Audit metadata must reference the session uuid.');
        $this->assertStringContainsString((string) $violations[0]['uuid'], $metadata, 'Audit metadata must reference the violation uuid.');
        $this->assertStringContainsString($fixture['bay_code'], $metadata, 'Audit metadata must reference the bay code.');
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $metadata), 'Overstay audit metadata must never contain phone numbers.');
        $this->assertStringNotContainsString('@example', $metadata, 'Overstay audit metadata must never contain email addresses.');
    }

    public function testSecondApplyRunIsIdempotent(): void
    {
        $fixture = $this->dueSessionFixture('V-03', 120, 30);

        $first = $this->service()->run(false, $this->estateId());
        $this->assertSame(1, (int) $first['flagged']);
        $this->assertSame(1, (int) $first['violations_created']);

        $violationsAfterFirst = $this->overstayViolations();
        $auditsAfterFirst = $this->overstayAudits();
        $this->assertCount(1, $violationsAfterFirst);
        $this->assertCount(1, $auditsAfterFirst);

        $second = $this->service()->run(false, $this->estateId());

        $this->assertSame(0, (int) $second['flagged'], 'An already-flagged session must not be flagged twice.');
        $this->assertSame(0, (int) $second['violations_created'], 'A second run must not create a duplicate violation.');
        $this->assertTrue((int) $second['skipped'] >= 1, 'The already-flagged session must be counted as skipped.');

        $this->assertCount(1, $this->overstayViolations(), 'Re-running the job must not duplicate violations.');
        $this->assertCount(1, $this->overstayAudits(), 'Re-running the job must not duplicate overstay audits.');

        $session = $this->sessionRowById($fixture['session_id']);
        $this->assertSame('overstay', (string) $session['status']);
    }

    public function testOneOpenOverstayViolationPerBayEvenWithMultipleSessions(): void
    {
        $first = $this->dueSessionFixture('V-04', 120, 30);
        $second = $this->dueSessionFixture('V-04', 90, 30);

        $result = $this->service()->run(false, $this->estateId());

        $this->assertSame(2, (int) $result['flagged'], 'Both due sessions on the bay must be flagged.');
        $this->assertSame(1, (int) $result['violations_created'], 'Only one open overstay violation may exist per bay.');
        $this->assertTrue((int) $result['skipped'] >= 1, 'The duplicate bay violation must be skipped.');

        $violations = $this->overstayViolations();
        $this->assertCount(1, $violations, 'parking_violations has no session_id: one open violation per bay.');
        $this->assertSame((int) $first['bay_id'], (int) $violations[0]['bay_id']);
        $this->assertSame((int) $first['bay_id'], (int) $second['bay_id']);

        $this->assertSame('overstay', (string) $this->sessionRowById($first['session_id'])['status']);
        $this->assertSame('overstay', (string) $this->sessionRowById($second['session_id'])['status']);
    }

    public function testGraceSettingIsRespected(): void
    {
        $fixture = $this->dueSessionFixture('V-03', 90, 120);

        $withinGrace = $this->service()->run(false, $this->estateId());
        $this->assertSame(0, (int) $withinGrace['overstayed'], 'A session inside the 120-minute grace must not be overstayed.');
        $this->assertSame(0, (int) $withinGrace['flagged']);
        $this->assertSame(0, (int) $withinGrace['violations_created']);
        $this->assertSame('active', (string) $this->sessionRowById($fixture['session_id'])['status']);

        // Lower the estate grace to zero: the same session is now overdue.
        $this->setOverstayGrace(0);

        $noGrace = $this->service()->run(false, $this->estateId());
        $this->assertSame(1, (int) $noGrace['overstayed'], 'A zero grace setting must flag the past-due session.');
        $this->assertSame(1, (int) $noGrace['flagged']);
        $this->assertSame('overstay', (string) $this->sessionRowById($fixture['session_id'])['status']);
    }

    public function testGraceFallsBackToConfigWhenTheEstateSettingIsMissing(): void
    {
        $original = Config::get('parking.overstay_grace_minutes');
        $fixture = $this->dueSessionFixture('V-03', 20, 30);

        // Remove the estate setting: the job must fall back to the configured value.
        $this->db->execute(
            'DELETE FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :setting_key',
            ['estate_id' => $this->estateId(), 'setting_key' => self::GRACE_SETTING]
        );

        Config::set('parking.overstay_grace_minutes', 60);

        try {
            $insideFallbackGrace = $this->service()->run(false, $this->estateId());
            $this->assertSame(0, (int) $insideFallbackGrace['flagged'], 'A 20-minute overrun is inside a 60-minute configured grace.');
            $this->assertSame('active', (string) $this->sessionRowById($fixture['session_id'])['status']);

            Config::set('parking.overstay_grace_minutes', 0);

            $outsideFallbackGrace = $this->service()->run(false, $this->estateId());
            $this->assertSame(1, (int) $outsideFallbackGrace['flagged'], 'A zero configured grace must flag the past-due session.');
            $this->assertSame('overstay', (string) $this->sessionRowById($fixture['session_id'])['status']);
        } finally {
            Config::set('parking.overstay_grace_minutes', $original);
        }
    }

    public function testActiveNotDueAndCompletedSessionsAreUntouched(): void
    {
        // Not due yet: invitation still valid for two hours.
        $this->setOverstayGrace(30);
        $this->ensureLoggedInAs(self::JANE);
        $data = $this->createInvitation([
            'household_uuid' => $this->householdUuid('A-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('-30 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+2 hours')),
        ]);
        $invitation = $this->db->selectOne(
            'SELECT id FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => (string) $data['invitation_uuid']]
        );
        $this->assertNotNull($invitation);
        $notDueBay = $this->seededBay('V-03');
        $notDueSessionId = $this->db->insert('parking_sessions', [
            'uuid' => Uuid::v4(),
            'estate_id' => $this->estateId(),
            'bay_id' => (int) $notDueBay['id'],
            'invitation_id' => (int) $invitation['id'],
            'checkin_at' => Clock::toDb(Clock::now()->modify('-1 hour')),
            'status' => 'active',
        ]);

        // Active session without an invitation: the grace window is unknowable.
        $orphanBay = $this->seededBay('V-04');
        $orphanSessionId = $this->db->insert('parking_sessions', [
            'uuid' => Uuid::v4(),
            'estate_id' => $this->estateId(),
            'bay_id' => (int) $orphanBay['id'],
            'invitation_id' => null,
            'checkin_at' => Clock::toDb(Clock::now()->modify('-8 hours')),
            'status' => 'active',
        ]);

        // A completed session must never be touched (even with a past window).
        $completedBay = $this->seededBay('V-02');
        $completedSessionId = $this->db->insert('parking_sessions', [
            'uuid' => Uuid::v4(),
            'estate_id' => $this->estateId(),
            'bay_id' => (int) $completedBay['id'],
            'invitation_id' => (int) $invitation['id'],
            'checkin_at' => Clock::toDb(Clock::now()->modify('-10 hours')),
            'checkout_at' => Clock::toDb(Clock::now()->modify('-9 hours')),
            'status' => 'completed',
        ]);

        $result = $this->service()->run(false, $this->estateId());

        $this->assertSame(0, (int) $result['overstayed'], 'Only past-due active sessions with an invitation may be overstayed.');
        $this->assertSame(0, (int) $result['violations_created']);

        $this->assertSame('active', (string) $this->sessionRowById($notDueSessionId)['status'], 'A not-due session must stay active.');
        $this->assertSame('active', (string) $this->sessionRowById($orphanSessionId)['status'], 'A session without an invitation window must stay active.');
        $this->assertSame('completed', (string) $this->sessionRowById($completedSessionId)['status'], 'A completed session must stay completed.');
        $this->assertNotNull($this->sessionRowById($completedSessionId)['checkout_at'], 'The completed session checkout must be untouched.');
    }

    public function testRunAllEstatesScopesAndProcessesOnlyActiveSessions(): void
    {
        $fixture = $this->dueSessionFixture('V-03', 120, 30);

        $all = $this->service()->run(true, null);
        $this->assertTrue((int) $all['estates'] >= 2, 'An unscoped run must visit every estate.');
        $this->assertTrue((int) $all['overstayed'] >= 1, 'The Greenview due session must be detected in an unscoped dry run.');

        $scoped = $this->service()->run(true, $this->estateId());
        $this->assertSame(1, (int) $scoped['estates'], 'A scoped run must process exactly the requested estate.');
        $this->assertSame(0, (int) $scoped['flagged'], 'A scoped dry run must not flag.');
        $this->assertSame('active', (string) $this->sessionRowById($fixture['session_id'])['status']);
    }

    public function testCliWrapperIsAThinScriptOverTheService(): void
    {
        $script = BASE_PATH . '/scripts/parking-overstay.php';
        $this->assertTrue(is_file($script), 'scripts/parking-overstay.php must exist for cron/dry-run use.');

        $contents = (string) file_get_contents($script);
        $this->assertStringContainsString('--dry-run', $contents, 'The CLI must document the --dry-run flag.');
        $this->assertStringContainsString('ParkingOverstayService', $contents, 'The CLI must delegate to the testable overstay service.');

        $this->assertTrue(class_exists(ParkingOverstayService::class), 'The overstay service class must exist.');
        $this->assertTrue(method_exists(ParkingOverstayService::class, 'make'), 'ParkingOverstayService::make() is the frozen test seam.');
        $this->assertTrue(method_exists(ParkingOverstayService::class, 'run'), 'ParkingOverstayService::run() is the frozen test seam.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, int>
     */
    private function tableInventory(): array
    {
        $inventory = [];

        foreach (self::TABLES as $table) {
            $inventory[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $table);
        }

        return $inventory;
    }
}
