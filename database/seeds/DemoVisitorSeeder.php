<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Visitor & Access demo fixtures (visitor-access-module-contract §2).
 *
 * All fixtures belong to Greenview Residences and are clearly demo-labelled.
 * Rows are matched by the stable UUID constants below, so `php scripts/install.php`
 * can run repeatedly without duplicating data.
 *
 * Demo story for Jane Smith (+254700000010, household A-001):
 *  - two completed (checked_out) visits, 3 and 9 days ago;
 *  - one upcoming approved invitation for "Demo Visitor Three" tomorrow
 *    10:00-14:00, whose plaintext PIN is the documented demo PIN below;
 *  - one pending walk-in request at the Main Gate.
 * A checked-in fixture for Brian Otieno's household (A-002) keeps the guard
 * dashboard populated for screenshots.
 *
 * PINs exist only as keyed HMACs in the database. The single documented demo
 * PIN is deliberately published so a guard can verify the seeded visit
 * immediately after installation; the QR pass token is generated at seed time
 * and never printed or committed.
 */
final class DemoVisitorSeeder extends Seeder
{
    /**
     * Documented development-only PIN for the seeded upcoming invitation
     * ("Demo Visitor Three"). Tests and the README use this value so a guard can
     * verify the demo visit immediately after `php scripts/install.php`.
     */
    public const DEMO_PIN = '246810';

    public const ESTATE_SLUG = 'greenview-residences';

    /** Stable UUIDs let the seeder find its rows without natural-key ambiguity. */
    public const VISITOR_HISTORY_ONE_UUID = 'a1000001-0000-4000-8000-000000000001';
    public const VISITOR_HISTORY_TWO_UUID = 'a1000001-0000-4000-8000-000000000002';
    public const VISITOR_UPCOMING_UUID = 'a1000001-0000-4000-8000-000000000003';
    public const VISITOR_INSIDE_UUID = 'a1000001-0000-4000-8000-000000000005';

    public const INVITATION_HISTORY_ONE_UUID = 'b1000001-0000-4000-8000-000000000001';
    public const INVITATION_HISTORY_TWO_UUID = 'b1000001-0000-4000-8000-000000000002';
    public const INVITATION_UPCOMING_UUID = 'b1000001-0000-4000-8000-000000000003';
    public const INVITATION_INSIDE_UUID = 'b1000001-0000-4000-8000-000000000004';

    public const CREDENTIAL_HISTORY_ONE_PIN_UUID = 'c1000001-0000-4000-8000-000000000001';
    public const CREDENTIAL_HISTORY_ONE_QR_UUID = 'c1000001-0000-4000-8000-000000000002';
    public const CREDENTIAL_HISTORY_TWO_PIN_UUID = 'c1000001-0000-4000-8000-000000000003';
    public const CREDENTIAL_HISTORY_TWO_QR_UUID = 'c1000001-0000-4000-8000-000000000004';
    public const CREDENTIAL_UPCOMING_PIN_UUID = 'c1000001-0000-4000-8000-000000000005';
    public const CREDENTIAL_UPCOMING_QR_UUID = 'c1000001-0000-4000-8000-000000000006';
    public const CREDENTIAL_INSIDE_PIN_UUID = 'c1000001-0000-4000-8000-000000000007';
    public const CREDENTIAL_INSIDE_QR_UUID = 'c1000001-0000-4000-8000-000000000008';

    public const EVENT_HISTORY_ONE_CHECKIN_UUID = 'e1000001-0000-4000-8000-000000000001';
    public const EVENT_HISTORY_ONE_CHECKOUT_UUID = 'e1000001-0000-4000-8000-000000000002';
    public const EVENT_HISTORY_TWO_CHECKIN_UUID = 'e1000001-0000-4000-8000-000000000003';
    public const EVENT_HISTORY_TWO_CHECKOUT_UUID = 'e1000001-0000-4000-8000-000000000004';
    public const EVENT_INSIDE_CHECKIN_UUID = 'e1000001-0000-4000-8000-000000000005';

    public const ROW_HISTORY_ONE_CHECKIN_UUID = 'd1000001-0000-4000-8000-000000000001';
    public const ROW_HISTORY_ONE_CHECKOUT_UUID = 'd1000001-0000-4000-8000-000000000002';
    public const ROW_HISTORY_TWO_CHECKIN_UUID = 'd1000001-0000-4000-8000-000000000003';
    public const ROW_HISTORY_TWO_CHECKOUT_UUID = 'd1000001-0000-4000-8000-000000000004';
    public const ROW_INSIDE_CHECKIN_UUID = 'd1000001-0000-4000-8000-000000000005';

    public const WALKIN_PENDING_UUID = 'f1000001-0000-4000-8000-000000000001';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'visitors_created',
        'invitations_created',
        'credentials_created',
        'events_created',
        'walkins_created',
        'fixtures_updated',
    ];

    /**
     * Completed visits (status checked_out).
     *
     * @var array<int, array{invitation_uuid: string, visitor_uuid: string, name: string, phone: string, household: string, host: string, purpose: string, days_ago: int, start_hour: int, end_hour: int, pin_uuid: string, qr_uuid: string, checkin_event_uuid: string, checkin_row_uuid: string, checkin_hour: int, checkin_minute: int, checkout_event_uuid: string, checkout_row_uuid: string, checkout_hour: int, checkout_minute: int}>
     */
    private const HISTORY_VISITS = [
        [
            'invitation_uuid' => self::INVITATION_HISTORY_ONE_UUID,
            'visitor_uuid' => self::VISITOR_HISTORY_ONE_UUID,
            'name' => 'Demo Visitor One',
            'phone' => '+254700090001',
            'household' => 'A-001',
            'host' => '+254700000010',
            'purpose' => 'Demo visit one',
            'days_ago' => 3,
            'start_hour' => 9,
            'end_hour' => 18,
            'pin_uuid' => self::CREDENTIAL_HISTORY_ONE_PIN_UUID,
            'qr_uuid' => self::CREDENTIAL_HISTORY_ONE_QR_UUID,
            'checkin_event_uuid' => self::EVENT_HISTORY_ONE_CHECKIN_UUID,
            'checkin_row_uuid' => self::ROW_HISTORY_ONE_CHECKIN_UUID,
            'checkin_hour' => 9,
            'checkin_minute' => 20,
            'checkout_event_uuid' => self::EVENT_HISTORY_ONE_CHECKOUT_UUID,
            'checkout_row_uuid' => self::ROW_HISTORY_ONE_CHECKOUT_UUID,
            'checkout_hour' => 17,
            'checkout_minute' => 5,
        ],
        [
            'invitation_uuid' => self::INVITATION_HISTORY_TWO_UUID,
            'visitor_uuid' => self::VISITOR_HISTORY_TWO_UUID,
            'name' => 'Demo Visitor Two',
            'phone' => '+254700090002',
            'household' => 'A-001',
            'host' => '+254700000010',
            'purpose' => 'Demo visit two',
            'days_ago' => 9,
            'start_hour' => 10,
            'end_hour' => 16,
            'pin_uuid' => self::CREDENTIAL_HISTORY_TWO_PIN_UUID,
            'qr_uuid' => self::CREDENTIAL_HISTORY_TWO_QR_UUID,
            'checkin_event_uuid' => self::EVENT_HISTORY_TWO_CHECKIN_UUID,
            'checkin_row_uuid' => self::ROW_HISTORY_TWO_CHECKIN_UUID,
            'checkin_hour' => 10,
            'checkin_minute' => 10,
            'checkout_event_uuid' => self::EVENT_HISTORY_TWO_CHECKOUT_UUID,
            'checkout_row_uuid' => self::ROW_HISTORY_TWO_CHECKOUT_UUID,
            'checkout_hour' => 15,
            'checkout_minute' => 40,
        ],
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $estate = $this->db->selectOne(
            'SELECT id, timezone FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => self::ESTATE_SLUG]
        );

        if ($estate === null) {
            throw new RuntimeException(
                'Visitor demo fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $estateId = (int) $estate['id'];
        $timezone = (string) ($estate['timezone'] ?? 'Africa/Nairobi');
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

        $households = [
            'A-001' => $this->requireId(
                'households',
                ['estate_id' => $estateId, 'unit_number' => 'A-001'],
                'household A-001'
            ),
            'A-002' => $this->requireId(
                'households',
                ['estate_id' => $estateId, 'unit_number' => 'A-002'],
                'household A-002'
            ),
        ];

        $userIds = [
            '+254700000010' => $this->requireId('users', ['phone' => '+254700000010'], 'user Jane Smith'),
            '+254700000011' => $this->requireId('users', ['phone' => '+254700000011'], 'user Brian Otieno'),
            '+254700000004' => $this->requireId('users', ['phone' => '+254700000004'], 'guard John Mutiso'),
        ];

        $gateId = $this->requireId(
            'gates',
            ['estate_id' => $estateId, 'name' => 'Main Gate'],
            'gate Main Gate'
        );

        $visitorIds = [
            self::VISITOR_HISTORY_ONE_UUID => $this->ensureVisitor($estateId, self::VISITOR_HISTORY_ONE_UUID, 'Demo Visitor One', '+254700090001'),
            self::VISITOR_HISTORY_TWO_UUID => $this->ensureVisitor($estateId, self::VISITOR_HISTORY_TWO_UUID, 'Demo Visitor Two', '+254700090002'),
            self::VISITOR_UPCOMING_UUID => $this->ensureVisitor($estateId, self::VISITOR_UPCOMING_UUID, 'Demo Visitor Three', '+254700090003'),
            self::VISITOR_INSIDE_UUID => $this->ensureVisitor($estateId, self::VISITOR_INSIDE_UUID, 'Demo Visitor Five', '+254700090005'),
        ];

        $context = [
            'estate_id' => $estateId,
            'households' => $households,
            'users' => $userIds,
            'gate_id' => $gateId,
            'now' => $now,
        ];

        foreach (self::HISTORY_VISITS as $fixture) {
            $this->ensureHistoryVisit($fixture, $visitorIds[$fixture['visitor_uuid']], $context);
        }

        $this->ensureUpcomingInvitation($visitorIds[self::VISITOR_UPCOMING_UUID], $context);
        $this->ensureCheckedInFixture($visitorIds[self::VISITOR_INSIDE_UUID], $context);
        $this->ensurePendingWalkIn($context);

        return $this->counters();
    }

    /**
     * A completed visit: already checked out, with consumed credentials and
     * check-in/check-out access events. Created once and then left stable.
     *
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $context
     */
    private function ensureHistoryVisit(array $fixture, int $visitorId, array $context): void
    {
        $now = $context['now'];
        $validFrom = $this->atTime($now, -((int) $fixture['days_ago']), (int) $fixture['start_hour']);
        $validUntil = $this->atTime($now, -((int) $fixture['days_ago']), (int) $fixture['end_hour']);
        $createdAt = $this->atTime($now, -((int) $fixture['days_ago']) - 1, 12);

        $invitationId = $this->ensureRow('visitor_invitations', (string) $fixture['invitation_uuid'], [
            'estate_id' => (int) $context['estate_id'],
            'household_id' => $context['households'][$fixture['household']],
            'host_user_id' => $context['users'][$fixture['host']],
            'visitor_id' => $visitorId,
            'purpose' => $fixture['purpose'],
            'visitor_count' => 1,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => 'checked_out',
            'created_at' => $createdAt,
        ], 'invitations_created');

        $this->ensurePinCredential(
            (int) $context['estate_id'],
            $invitationId,
            (string) $fixture['pin_uuid'],
            $validUntil,
            'used',
            1,
            $createdAt,
            '715511'
        );
        $this->ensureQrCredential(
            (int) $context['estate_id'],
            $invitationId,
            (string) $fixture['qr_uuid'],
            $validUntil,
            'used',
            1,
            $createdAt
        );

        $this->ensureEvent(
            (int) $context['estate_id'],
            $invitationId,
            (int) $context['gate_id'],
            (int) $context['users']['+254700000004'],
            (string) $fixture['checkin_row_uuid'],
            (string) $fixture['checkin_event_uuid'],
            'check_in',
            $this->atTime($now, -((int) $fixture['days_ago']), (int) $fixture['checkin_hour'], (int) $fixture['checkin_minute'])
        );
        $this->ensureEvent(
            (int) $context['estate_id'],
            $invitationId,
            (int) $context['gate_id'],
            (int) $context['users']['+254700000004'],
            (string) $fixture['checkout_row_uuid'],
            (string) $fixture['checkout_event_uuid'],
            'check_out',
            $this->atTime($now, -((int) $fixture['days_ago']), (int) $fixture['checkout_hour'], (int) $fixture['checkout_minute'])
        );
    }

    /**
     * The upcoming approved invitation with a live PIN + QR credential. The
     * validity window is refreshed on every run ("tomorrow 10:00-14:00" estate
     * local) so the demo visit always verifies, while the credential hashes stay
     * stable. The PIN is part of the documented demo data.
     *
     * @param array<string, mixed> $context
     */
    private function ensureUpcomingInvitation(int $visitorId, array $context): void
    {
        $now = $context['now'];
        $validFrom = $this->atTime($now, 1, 10);
        $validUntil = $this->atTime($now, 1, 14);
        $estateId = (int) $context['estate_id'];

        $invitationId = $this->findId('visitor_invitations', ['uuid' => self::INVITATION_UPCOMING_UUID]);

        if ($invitationId === null) {
            $invitationId = $this->insert('visitor_invitations', [
                'uuid' => self::INVITATION_UPCOMING_UUID,
                'estate_id' => $estateId,
                'household_id' => $context['households']['A-001'],
                'host_user_id' => $context['users']['+254700000010'],
                'visitor_id' => $visitorId,
                'purpose' => 'Demo family visit',
                'visitor_count' => 1,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'status' => 'approved',
            ]);
            $this->count('invitations_created');
        } else {
            $this->updateIfChanged('visitor_invitations', $invitationId, [
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'status' => 'approved',
                'visitor_count' => 1,
            ]);
        }

        $this->ensurePinCredential(
            $estateId,
            $invitationId,
            self::CREDENTIAL_UPCOMING_PIN_UUID,
            $validUntil,
            'active',
            0,
            Clock::nowDb(),
            self::DEMO_PIN
        );

        $qrCredentialId = $this->findId('access_credentials', ['uuid' => self::CREDENTIAL_UPCOMING_QR_UUID]);

        if ($qrCredentialId === null) {
            $this->ensureQrCredential($estateId, $invitationId, self::CREDENTIAL_UPCOMING_QR_UUID, $validUntil, 'active', 0, Clock::nowDb());
        } else {
            $this->updateIfChanged(
                'access_credentials',
                $qrCredentialId,
                ['expires_at' => $validUntil, 'status' => 'active', 'used_count' => 0]
            );
        }
    }

    /**
     * A visit that is currently inside (checked_in) for the guard dashboard.
     * Its validity window and check-in event are refreshed to "today".
     *
     * @param array<string, mixed> $context
     */
    private function ensureCheckedInFixture(int $visitorId, array $context): void
    {
        $now = $context['now'];
        $validFrom = $this->atTime($now, 0, 7);
        $validUntil = $this->atTime($now, 1, 7);
        $estateId = (int) $context['estate_id'];

        $invitationId = $this->findId('visitor_invitations', ['uuid' => self::INVITATION_INSIDE_UUID]);

        if ($invitationId === null) {
            $invitationId = $this->insert('visitor_invitations', [
                'uuid' => self::INVITATION_INSIDE_UUID,
                'estate_id' => $estateId,
                'household_id' => $context['households']['A-002'],
                'host_user_id' => $context['users']['+254700000011'],
                'visitor_id' => $visitorId,
                'purpose' => 'Demo contractor visit',
                'visitor_count' => 1,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'status' => 'checked_in',
                'created_at' => $this->atTime($now, 0, 6, 30),
            ]);
            $this->count('invitations_created');
        } else {
            $this->updateIfChanged('visitor_invitations', $invitationId, [
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'status' => 'checked_in',
            ]);
        }

        $this->ensurePinCredential(
            $estateId,
            $invitationId,
            self::CREDENTIAL_INSIDE_PIN_UUID,
            $validUntil,
            'used',
            1,
            $this->atTime($now, 0, 6, 30),
            '826413'
        );
        $this->ensureQrCredential(
            $estateId,
            $invitationId,
            self::CREDENTIAL_INSIDE_QR_UUID,
            $validUntil,
            'used',
            1,
            $this->atTime($now, 0, 6, 30)
        );

        $eventId = $this->findId('visitor_access_events', ['event_uuid' => self::EVENT_INSIDE_CHECKIN_UUID]);
        $occurredAt = $this->atTime($now, 0, 9, 15);

        if ($eventId === null) {
            $this->ensureEvent(
                $estateId,
                $invitationId,
                (int) $context['gate_id'],
                (int) $context['users']['+254700000004'],
                self::ROW_INSIDE_CHECKIN_UUID,
                self::EVENT_INSIDE_CHECKIN_UUID,
                'check_in',
                $occurredAt
            );
        } else {
            $this->updateIfChanged('visitor_access_events', $eventId, ['occurred_at' => $occurredAt]);
        }
    }

    /**
     * One pending walk-in at the Main Gate for Jane's household.
     *
     * @param array<string, mixed> $context
     */
    private function ensurePendingWalkIn(array $context): void
    {
        $existing = $this->db->selectOne(
            'SELECT id, status FROM walk_in_requests WHERE uuid = :uuid LIMIT 1',
            ['uuid' => self::WALKIN_PENDING_UUID]
        );

        if ($existing === null) {
            $this->insert('walk_in_requests', [
                'uuid' => self::WALKIN_PENDING_UUID,
                'estate_id' => (int) $context['estate_id'],
                'household_id' => $context['households']['A-001'],
                'gate_id' => (int) $context['gate_id'],
                'requested_by_user_id' => $context['users']['+254700000004'],
                'visitor_name' => 'Demo Walk-in Four',
                'visitor_phone' => '+254700090004',
                'purpose' => 'Demo delivery',
                'status' => 'pending',
            ]);
            $this->count('walkins_created');

            return;
        }

        if ((string) $existing['status'] === 'pending') {
            return;
        }

        // Keep the demo fixture pending for screenshots after acceptance runs.
        $this->update('walk_in_requests', [
            'status' => 'pending',
            'invitation_id' => null,
            'responded_by_user_id' => null,
            'responded_at' => null,
        ], ['id' => (int) $existing['id']]);
        $this->count('fixtures_updated');
    }

    private function ensureVisitor(int $estateId, string $uuid, string $name, string $phone): int
    {
        $visitorId = $this->findId('visitors', ['uuid' => $uuid]);

        if ($visitorId !== null) {
            return $visitorId;
        }

        $visitorId = $this->insert('visitors', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'name' => $name,
            'phone' => $phone,
        ]);
        $this->count('visitors_created');

        return $visitorId;
    }

    private function ensureRow(string $table, string $uuid, array $data, string $counter): int
    {
        $id = $this->findId($table, ['uuid' => $uuid]);

        if ($id !== null) {
            return $id;
        }

        $id = $this->insert($table, ['uuid' => $uuid] + $data);
        $this->count($counter);

        return $id;
    }

    private function ensurePinCredential(
        int $estateId,
        int $invitationId,
        string $uuid,
        string $expiresAt,
        string $status,
        int $usedCount,
        string $createdAt,
        string $pin
    ): int {
        $existing = $this->findId('access_credentials', ['uuid' => $uuid]);

        if ($existing !== null) {
            $this->updateIfChanged('access_credentials', $existing, [
                'expires_at' => $expiresAt,
                'status' => $status,
                'used_count' => $usedCount,
            ]);

            return $existing;
        }

        $credentialId = $this->insert('access_credentials', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'credential_hash' => Crypto::hmac('access-pin:' . $uuid, $pin),
            'credential_type' => 'pin',
            'encrypted_secret' => null,
            'expires_at' => $expiresAt,
            'max_uses' => 1,
            'used_count' => $usedCount,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
        $this->count('credentials_created');

        return $credentialId;
    }

    private function ensureQrCredential(
        int $estateId,
        int $invitationId,
        string $uuid,
        string $expiresAt,
        string $status,
        int $usedCount,
        string $createdAt
    ): int {
        $existing = $this->findId('access_credentials', ['uuid' => $uuid]);

        if ($existing !== null) {
            $this->updateIfChanged('access_credentials', $existing, [
                'expires_at' => $expiresAt,
                'status' => $status,
                'used_count' => $usedCount,
            ]);

            return $existing;
        }

        $token = Crypto::randomToken((int) Config::get('visitors.pass.token_bytes', 32));

        $credentialId = $this->insert('access_credentials', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'credential_hash' => Crypto::hmac('access-qr:' . $uuid, $token),
            'credential_type' => 'qr',
            'encrypted_secret' => Crypto::encrypt($token, 'access-qr:' . $uuid),
            'expires_at' => $expiresAt,
            'max_uses' => 1,
            'used_count' => $usedCount,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
        $this->count('credentials_created');

        return $credentialId;
    }

    private function ensureEvent(
        int $estateId,
        int $invitationId,
        int $gateId,
        int $actorId,
        string $rowUuid,
        string $eventUuid,
        string $eventType,
        string $occurredAt
    ): void {
        if ($this->findId('visitor_access_events', ['event_uuid' => $eventUuid]) !== null) {
            return;
        }

        $this->insert('visitor_access_events', [
            'uuid' => $rowUuid !== '' ? $rowUuid : $this->uuid(),
            'event_uuid' => $eventUuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'gate_id' => $gateId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt,
            'source' => 'online',
            'created_at' => $occurredAt,
        ]);
        $this->count('events_created');
    }

    /**
     * Update a row from expected values, counting one refresh only when
     * something actually changed. Keeps repeated seed runs a no-op.
     *
     * @param array<string, mixed> $expected
     */
    private function updateIfChanged(string $table, int $id, array $expected): void
    {
        if ($id <= 0) {
            return;
        }

        $current = $this->db->selectOne('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($current === null) {
            return;
        }

        $changes = [];

        foreach ($expected as $column => $value) {
            if ((string) ($current[$column] ?? '') !== (string) ($value ?? '')) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->update($table, $changes, ['id' => $id]);
        $this->count('fixtures_updated');
    }

    /**
     * Convert an estate-local day/hour into a UTC DATETIME(3) string.
     */
    private function atTime(DateTimeImmutable $now, int $daysOffset, int $hour, int $minute = 0): string
    {
        return Clock::toDb(
            $now->modify(($daysOffset >= 0 ? '+' : '') . $daysOffset . ' days')->setTime($hour, $minute, 0)
        );
    }

    /**
     * Resolve a required natural-key row id.
     *
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException(
                'Visitor demo fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }
}
