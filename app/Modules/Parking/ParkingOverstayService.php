<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Uuid;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Overstay detection job (Increment 8 §2; testable in-process, no HTTP).
 *
 * For every active parking session (`status='active'` and `checkout_at IS NULL`)
 * linked to an invitation, when now > invitation.valid_until + grace minutes:
 *  - set the session status to `overstay`,
 *  - create one open `overstay` violation for the session's bay unless an open
 *    overstay violation already exists for that bay,
 *  - audit `parking.overstay_flagged` (uuid metadata only).
 *
 * Grace comes from the estate setting `parking_overstay_grace_minutes` (JSON
 * scalar), falling back to config `parking.overstay_grace_minutes` (default 30).
 * The job is idempotent: a second run flags/creates nothing new. `$dryRun` never
 * writes. The CLI (`scripts/parking-overstay.php`) is a thin wrapper over run().
 */
final class ParkingOverstayService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    public static function make(?DateTimeImmutable $now = null): self
    {
        return new self(Database::instance(), $now);
    }

    /**
     * @return array{dry_run: bool, estates: int, scanned: int, overstayed: int,
     *               flagged: int, violations_created: int, skipped: int,
     *               processed_at: string}
     */
    public function run(bool $dryRun = false, ?int $estateId = null): array
    {
        $now = $this->now ?? Clock::now();
        $result = [
            'dry_run' => $dryRun,
            'estates' => 0,
            'scanned' => 0,
            'overstayed' => 0,
            'flagged' => 0,
            'violations_created' => 0,
            'skipped' => 0,
            'processed_at' => Clock::toDb($now->setTimezone(Clock::utc())),
        ];

        $params = [];
        $filter = '';

        if ($estateId !== null) {
            $filter = ' AND s.estate_id = :estate_id';
            $params['estate_id'] = $estateId;
        }

        $sessions = $this->db->select(
            "SELECT s.id, s.uuid, s.estate_id, s.bay_id, s.vehicle_id,
                    i.valid_until,
                    b.uuid AS bay_uuid, b.bay_code
             FROM parking_sessions s
             INNER JOIN visitor_invitations i ON i.id = s.invitation_id AND i.estate_id = s.estate_id
             INNER JOIN parking_bays b ON b.id = s.bay_id AND b.estate_id = s.estate_id
             WHERE s.checkout_at IS NULL
               AND s.status = 'active'" . $filter . '
             ORDER BY s.id ASC',
            $params
        );

        $estates = [];
        $audit = new AuditLogger($this->db);
        $graceCache = [];

        foreach ($sessions as $session) {
            $sessionEstateId = (int) $session['estate_id'];
            $estates[$sessionEstateId] = true;
            $result['scanned']++;

            $validUntil = Clock::fromDb((string) $session['valid_until']);

            if ($validUntil === null) {
                continue;
            }

            $grace = $graceCache[$sessionEstateId]
                ??= $this->graceMinutes($sessionEstateId);
            $threshold = $validUntil->modify('+' . $grace . ' minutes');

            if ($now <= $threshold) {
                continue;
            }

            $result['overstayed']++;

            $existing = $this->db->selectOne(
                "SELECT id, uuid FROM parking_violations
                 WHERE estate_id = :estate_id AND bay_id = :bay_id
                   AND violation_type = 'overstay' AND status = 'open'
                 LIMIT 1",
                ['estate_id' => $sessionEstateId, 'bay_id' => (int) $session['bay_id']]
            );

            if ($existing !== null) {
                $result['skipped']++;

                continue;
            }

            if ($dryRun) {
                continue;
            }

            $nowDb = Clock::toDb($now);
            $violationUuid = Uuid::v4();

            // Atomic flag + one-violation-per-bay: the conditional session update
            // serialises concurrent runs; the second run updates 0 rows and skips.
            $flaggedNow = $this->db->transaction(function () use (
                $session,
                $sessionEstateId,
                $nowDb,
                $grace,
                $audit,
                $violationUuid
            ): bool {
                $updated = $this->db->execute(
                    "UPDATE parking_sessions SET status = 'overstay', updated_at = :updated
                     WHERE id = :id AND status = 'active' AND checkout_at IS NULL",
                    ['updated' => $nowDb, 'id' => (int) $session['id']]
                );

                if ($updated < 1) {
                    return false;
                }

                $this->db->insert('parking_violations', [
                    'uuid' => $violationUuid,
                    'estate_id' => $sessionEstateId,
                    'bay_id' => (int) $session['bay_id'],
                    'vehicle_id' => $session['vehicle_id'] === null ? null : (int) $session['vehicle_id'],
                    'plate_number' => null,
                    'violation_type' => 'overstay',
                    'status' => 'open',
                    'notes' => sprintf(
                        'Automatic overstay flag: vehicle did not check out before the visit window plus %d minute grace.',
                        $grace
                    ),
                    'reported_by_user_id' => null,
                    'created_at' => $nowDb,
                ]);

                $audit->record(
                    'parking.overstay_flagged',
                    'parking_session',
                    (int) $session['id'],
                    [
                        'session_uuid' => (string) $session['uuid'],
                        'bay_uuid' => (string) $session['bay_uuid'],
                        'bay_code' => (string) $session['bay_code'],
                        'violation_uuid' => $violationUuid,
                        'grace_minutes' => $grace,
                    ],
                    $sessionEstateId
                );

                return true;
            });

            if (!$flaggedNow) {
                $result['skipped']++;

                continue;
            }

            $result['flagged']++;
            $result['violations_created']++;
        }

        $result['estates'] = count($estates);
        $result['processed_at'] = Clock::toDb($now->setTimezone(Clock::utc()));

        return $result;
    }

    private function graceMinutes(int $estateId): int
    {
        $row = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings
             WHERE estate_id = :estate_id AND setting_key = :setting_key LIMIT 1',
            ['estate_id' => $estateId, 'setting_key' => 'parking_overstay_grace_minutes']
        );

        if ($row !== null && $row['setting_value'] !== null) {
            $decoded = json_decode((string) $row['setting_value'], true);

            if (is_numeric($decoded)) {
                return max(0, min(120, (int) $decoded));
            }
        }

        return max(0, min(120, (int) Config::get('parking.overstay_grace_minutes', 30)));
    }
}
