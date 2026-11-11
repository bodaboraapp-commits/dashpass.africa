<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Increment-6 demo fixtures (staff-incidents-contract §1).
 *
 * Greenview Residences gets three household staff records (A-001/A-002/B-001,
 * clearly demo-labelled, no user accounts) and three visitor/security incidents:
 *   - open / medium     · suspicious_activity at Main Gate, reported by guard John;
 *   - investigating/high· unauthorised_access linked to the seeded history visit;
 *   - resolved / low    · lost_pass for A-001, resolved by the estate admin.
 *
 * Rows are matched by the stable UUID constants below, so `php scripts/install.php`
 * can run repeatedly without duplicating anything. Timestamps are anchored to the
 * seeded visitor fixtures / creation time and are never rewritten on re-run;
 * only demo status state self-heals after acceptance flows mutate it.
 */
final class DemoStaffIncidentSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';

    public const STAFF_HOUSEKEEPER_UUID = '3a000001-0000-4000-8000-000000000001';
    public const STAFF_GARDENER_UUID = '3a000001-0000-4000-8000-000000000002';
    public const STAFF_DRIVER_UUID = '3a000001-0000-4000-8000-000000000003';

    public const STAFF_HOUSEKEEPER_PHONE = '+254700091001';
    public const STAFF_GARDENER_PHONE = '+254700091002';
    public const STAFF_DRIVER_PHONE = '+254700091003';

    public const INCIDENT_SUSPICIOUS_UUID = '3b000001-0000-4000-8000-000000000001';
    public const INCIDENT_UNAUTHORISED_UUID = '3b000001-0000-4000-8000-000000000002';
    public const INCIDENT_LOST_PASS_UUID = '3b000001-0000-4000-8000-000000000003';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'staff_created',
        'incidents_created',
        'fixtures_updated',
    ];

    /**
     * Demo staff directory (no user accounts; phone stored but masked in UI).
     *
     * @var array<int, array{uuid: string, household: string, full_name: string, phone: string, staff_type: string, notes: string}>
     */
    private const STAFF = [
        [
            'uuid' => self::STAFF_HOUSEKEEPER_UUID,
            'household' => 'A-001',
            'full_name' => 'Demo Housekeeper',
            'phone' => self::STAFF_HOUSEKEEPER_PHONE,
            'staff_type' => 'domestic',
            'notes' => 'Demo domestic staff member (day shift).',
        ],
        [
            'uuid' => self::STAFF_GARDENER_UUID,
            'household' => 'A-002',
            'full_name' => 'Demo Gardener',
            'phone' => self::STAFF_GARDENER_PHONE,
            'staff_type' => 'gardener',
            'notes' => 'Demo gardener (twice weekly).',
        ],
        [
            'uuid' => self::STAFF_DRIVER_UUID,
            'household' => 'B-001',
            'full_name' => 'Demo Driver',
            'phone' => self::STAFF_DRIVER_PHONE,
            'staff_type' => 'driver',
            'notes' => 'Demo household driver.',
        ],
    ];

    /**
     * Demo incidents. `occurred` kinds are fixed anchors:
     *  today_0830 | history_invitation_plus_40 | two_days_ago_1100.
     *
     * @var array<int, array{uuid: string, incident_type: string, severity: string, status: string, summary: string, description: string, household: ?string, gate: ?string, invitation: bool, reporter: string, occurred: string, resolved?: array{after_minutes: int, by: string, notes: string}}>
     */
    private const INCIDENTS = [
        [
            'uuid' => self::INCIDENT_SUSPICIOUS_UUID,
            'incident_type' => 'suspicious_activity',
            'severity' => 'medium',
            'status' => 'open',
            'summary' => 'Demo: suspicious activity at the Main Gate',
            'description' => 'Demo incident — a visitor lingered near the pedestrian lane and left when approached. Reported for demonstration purposes only.',
            'household' => null,
            'gate' => 'Main Gate',
            'invitation' => false,
            'reporter' => '+254700000004',
            'occurred' => 'today_0830',
        ],
        [
            'uuid' => self::INCIDENT_UNAUTHORISED_UUID,
            'incident_type' => 'unauthorised_access',
            'severity' => 'high',
            'status' => 'investigating',
            'summary' => 'Demo: unauthorised entry attempt linked to a visitor pass',
            'description' => 'Demo incident — a visitor attempted entry outside the approved window for the linked history visit. Under investigation for demonstration purposes only.',
            'household' => 'A-001',
            'gate' => 'Main Gate',
            'invitation' => true,
            'reporter' => '+254700000004',
            'occurred' => 'history_invitation_plus_40',
        ],
        [
            'uuid' => self::INCIDENT_LOST_PASS_UUID,
            'incident_type' => 'lost_pass',
            'severity' => 'low',
            'status' => 'resolved',
            'summary' => 'Demo: lost visitor pass reported by resident',
            'description' => 'Demo incident — a printed visitor pass was mislaid by the household. The old pass was revoked and a replacement was issued.',
            'household' => 'A-001',
            'gate' => null,
            'invitation' => false,
            'reporter' => '+254700000010',
            'occurred' => 'two_days_ago_1100',
            'resolved' => [
                'after_minutes' => 90,
                'by' => '+254700000001',
                'notes' => 'Demo resolution: old pass revoked and a replacement issued after phone verification.',
            ],
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
                'Staff/incident demo fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $estateId = (int) $estate['id'];
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);

        $households = [
            'A-001' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'A-001'], 'household A-001'),
            'A-002' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'A-002'], 'household A-002'),
            'B-001' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'B-001'], 'household B-001'),
        ];

        $userIds = [
            '+254700000004' => $this->requireId('users', ['phone' => '+254700000004'], 'guard John Mutiso'),
            '+254700000010' => $this->requireId('users', ['phone' => '+254700000010'], 'resident Jane Smith'),
            '+254700000001' => $this->requireId('users', ['phone' => '+254700000001'], 'estate admin Grace Wanjiku'),
        ];

        $gateIds = [
            'Main Gate' => $this->requireId('gates', ['estate_id' => $estateId, 'name' => 'Main Gate'], 'gate Main Gate'),
        ];

        foreach (self::STAFF as $staff) {
            $this->ensureStaff($estateId, $households[$staff['household']], $staff);
        }

        $historyInvitation = $this->db->selectOne(
            'SELECT id, household_id, valid_from FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID]
        );

        if ($historyInvitation === null) {
            throw new RuntimeException('Staff/incident demo fixtures need the seeded history visitor invitation.');
        }

        $context = [
            'estate_id' => $estateId,
            'households' => $households,
            'user_ids' => $userIds,
            'gate_ids' => $gateIds,
            'history_invitation' => $historyInvitation,
            'now' => $now,
        ];

        foreach (self::INCIDENTS as $incident) {
            $this->ensureIncident($context, $incident);
        }

        return $this->counters();
    }

    /**
     * @param array{uuid: string, household: string, full_name: string, phone: string, staff_type: string, notes: string} $staff
     */
    private function ensureStaff(int $estateId, int $householdId, array $staff): int
    {
        $expected = [
            'household_id' => $householdId,
            'user_id' => null,
            'full_name' => $staff['full_name'],
            'phone' => $staff['phone'],
            'staff_type' => $staff['staff_type'],
            'notes' => $staff['notes'],
            'status' => 'active',
        ];

        $staffId = $this->findId('household_staff', ['uuid' => $staff['uuid']]);

        if ($staffId !== null) {
            $this->updateIfChanged('household_staff', $staffId, $expected);

            return $staffId;
        }

        // Adopt a natural-key row created by another process instead of duplicating.
        $natural = $this->findId('household_staff', ['household_id' => $householdId, 'phone' => $staff['phone']]);

        if ($natural !== null) {
            $this->updateIfChanged('household_staff', $natural, $expected);

            return $natural;
        }

        $staffId = $this->insert('household_staff', [
            'uuid' => $staff['uuid'],
            'estate_id' => $estateId,
        ] + $expected);
        $this->count('staff_created');

        return $staffId;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $spec
     */
    private function ensureIncident(array $context, array $spec): int
    {
        $estateId = (int) $context['estate_id'];
        $householdId = $spec['household'] === null ? null : (int) $context['households'][(string) $spec['household']];
        $gateId = $spec['gate'] === null ? null : (int) $context['gate_ids'][(string) $spec['gate']];
        $invitationId = (bool) $spec['invitation'] ? (int) $context['history_invitation']['id'] : null;
        $reportedBy = (int) $context['user_ids'][(string) $spec['reporter']];

        $expected = [
            'estate_id' => $estateId,
            'gate_id' => $gateId,
            'household_id' => $householdId,
            'invitation_id' => $invitationId,
            'reported_by_user_id' => $reportedBy,
            'incident_type' => $spec['incident_type'],
            'severity' => $spec['severity'],
            'status' => $spec['status'],
            'summary' => $spec['summary'],
            'description' => $spec['description'],
        ];

        $incidentId = $this->findId('access_incidents', ['uuid' => $spec['uuid']]);

        if ($incidentId === null) {
            $occurredAt = $this->occurredAt($context, (string) $spec['occurred']);
            $insert = ['uuid' => $spec['uuid']] + $expected + [
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ];

            if (isset($spec['resolved'])) {
                $insert['resolved_at'] = $this->addMinutes($occurredAt, (int) $spec['resolved']['after_minutes']);
                $insert['resolved_by_user_id'] = (int) $context['user_ids'][(string) $spec['resolved']['by']];
                $insert['resolution_notes'] = (string) $spec['resolved']['notes'];
            }

            $incidentId = $this->insert('access_incidents', $insert);
            $this->count('incidents_created');

            return $incidentId;
        }

        // Self-heal demo state after acceptance flows, but never rewrite the
        // incident timeline (occurred_at/created_at stay as first seeded).
        if (isset($spec['resolved'])) {
            $current = $this->db->selectOne('SELECT occurred_at FROM access_incidents WHERE id = :id LIMIT 1', ['id' => $incidentId]);

            if ($current !== null && ($current['occurred_at'] ?? null) !== null) {
                $expected['resolved_at'] = $this->addMinutes((string) $current['occurred_at'], (int) $spec['resolved']['after_minutes']);
                $expected['resolved_by_user_id'] = (int) $context['user_ids'][(string) $spec['resolved']['by']];
                $expected['resolution_notes'] = (string) $spec['resolved']['notes'];
            }
        } else {
            $expected['resolved_at'] = null;
            $expected['resolved_by_user_id'] = null;
            $expected['resolution_notes'] = null;
        }

        $this->updateIfChanged('access_incidents', $incidentId, $expected);

        return $incidentId;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function occurredAt(array $context, string $kind): string
    {
        $now = $context['now'];

        if ($kind === 'history_invitation_plus_40') {
            $moment = Clock::fromDb((string) $context['history_invitation']['valid_from']);

            if ($moment === null) {
                throw new RuntimeException('Cannot resolve the seeded history visit start time.');
            }

            return Clock::toDb($moment->modify('+40 minutes'));
        }

        return match ($kind) {
            // Keep the "today" anchor in the past even if the seed runs before 08:30.
            'today_0830' => Clock::toDb(
                $now->format('H:i') < '08:30' ? $now->modify('-45 minutes') : $now->setTime(8, 30, 0)
            ),
            'two_days_ago_1100' => Clock::toDb($now->modify('-2 days')->setTime(11, 0, 0)),
            default => throw new RuntimeException('Unknown demo incident occurrence anchor: ' . $kind),
        };
    }

    private function addMinutes(string $utc, int $minutes): string
    {
        $moment = Clock::fromDb($utc);

        if ($moment === null) {
            throw new RuntimeException('Cannot resolve demo incident timestamp.');
        }

        return Clock::toDb($moment->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes'));
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
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException(
                'Staff/incident demo fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }
}
