<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Modules\Notifications\NotificationLogger;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * DEV-ONLY demo fixture for the Inc-13 incident siren (owner test aid).
 *
 * Creates (idempotently, fixed UUIDs):
 *  - ONE open incident on Greenview: "Suspicious activity reported at Gate A"
 *    (security/suspicious_activity, severity low, reported by guard John Mutiso);
 *  - unread in-app notifications for the users who triage incidents
 *    (roles holding `visitor.incident.manage`: estate_admin, estate_manager,
 *    security_supervisor), dedupe `incident:{uuid}:{user_id}`,
 *    `recipient='u:{user_id}'`, `template='incident_reported'`, read_at NULL;
 *  - ONE older Resolved incident for visual contrast (no notification).
 *
 * Guarded so it can NEVER seed production (and is skipped in testing/cleanroom so
 * product/test expectations are untouched): only runs when APP_ENV is
 * `local`/`development`. Re-running is safe: existing rows are refreshed and
 * read_at is reset to NULL so the owner can re-arm the siren on demand.
 */
final class DemoIncidentSirenSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';
    public const AUTHOR_PHONE = '+254700000004';   // guard John Mutiso (fixture)
    public const RESOLVER_PHONE = '+254700000001'; // Grace Wanjiku (estate_admin)

    public const OPEN_INCIDENT_UUID = '9a000001-0000-4000-8000-000000000001';
    public const RESOLVED_INCIDENT_UUID = '9a000001-0000-4000-8000-000000000002';

    public const SUMMARY = 'Suspicious activity reported at Gate A';
    public const DESCRIPTION = 'Demo fixture: a visitor was seen lingering near Gate A after hours. '
        . 'No personal data recorded; for siren/notification testing only.';
    public const RESOLVED_SUMMARY = 'Tailgating attempt at Gate A (resolved)';
    public const RESOLVED_DESCRIPTION = 'Demo fixture: tailgating attempt investigated and resolved. '
        . 'No personal data recorded.';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'incidents_created',
        'incidents_updated',
        'notifications_created',
        'notifications_reset',
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $env = strtolower((string) \App\Core\Config::get('app.env', 'production'));

        // Demo/dev only: never production, and skip automated test/cleanroom seeds.
        if ($env !== 'local' && $env !== 'development') {
            return $this->counters();
        }

        $estate = $this->db->selectOne(
            'SELECT id, timezone FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => self::ESTATE_SLUG]
        );

        if ($estate === null) {
            throw new RuntimeException('Incident siren fixture needs the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.');
        }

        $estateId = (int) $estate['id'];
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);

        $authorId = $this->requireId('users', ['phone' => self::AUTHOR_PHONE], 'guard John Mutiso');
        $resolverId = $this->requireId('users', ['phone' => self::RESOLVER_PHONE], 'estate admin Grace Wanjiku');
        $gateId = $this->findId('gates', ['estate_id' => $estateId, 'name' => 'Main Gate']);

        if ($gateId === null) {
            throw new RuntimeException('Incident siren fixture needs the Greenview Main Gate.');
        }

        /* ------------------------------------------------------------------ */
        /* 1. Open incident (the siren source)                                 */
        /* ------------------------------------------------------------------ */
        $openPayload = [
            'estate_id' => $estateId,
            'gate_id' => $gateId,
            'household_id' => null,
            'invitation_id' => null,
            'reported_by_user_id' => $authorId,
            'incident_type' => 'suspicious_activity',
            'severity' => 'low',
            'status' => 'open',
            'summary' => self::SUMMARY,
            'description' => self::DESCRIPTION,
            'occurred_at' => Clock::toDb($now),
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'resolution_notes' => null,
        ];

        $openIncidentId = $this->findId('access_incidents', ['uuid' => self::OPEN_INCIDENT_UUID]);

        if ($openIncidentId === null) {
            $openIncidentId = $this->insert('access_incidents', ['uuid' => self::OPEN_INCIDENT_UUID] + $openPayload);
            $this->count('incidents_created');
        } else {
            // Re-arm: keep occurred_at create-only, reset status/resolution for the siren test.
            $reset = $openPayload;
            unset($reset['occurred_at']);
            $this->updateIfChanged('access_incidents', $openIncidentId, $reset, 'incidents_updated');
        }

        /* ------------------------------------------------------------------ */
        /* 2. Older resolved incident (visual contrast, no notification)       */
        /* ------------------------------------------------------------------ */
        $resolvedPayload = [
            'estate_id' => $estateId,
            'gate_id' => $gateId,
            'household_id' => null,
            'invitation_id' => null,
            'reported_by_user_id' => $authorId,
            'incident_type' => 'tailgating',
            'severity' => 'medium',
            'status' => 'resolved',
            'summary' => self::RESOLVED_SUMMARY,
            'description' => self::RESOLVED_DESCRIPTION,
            'occurred_at' => Clock::toDb($now->modify('-3 days')),
            'resolved_at' => Clock::toDb($now->modify('-2 days')),
            'resolved_by_user_id' => $resolverId,
            'resolution_notes' => 'Investigated by the estate admin; no further action required.',
        ];

        $resolvedIncidentId = $this->findId('access_incidents', ['uuid' => self::RESOLVED_INCIDENT_UUID]);

        if ($resolvedIncidentId === null) {
            $this->insert('access_incidents', ['uuid' => self::RESOLVED_INCIDENT_UUID] + $resolvedPayload);
            $this->count('incidents_created');
        }

        /* ------------------------------------------------------------------ */
        /* 3. Unread in-app siren notifications for triage users               */
        /* ------------------------------------------------------------------ */
        $recipients = $this->db->select(
            "SELECT DISTINCT em.user_id, r.name AS role_name
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id AND u.status = 'active'
             INNER JOIN roles r ON r.id = em.role_id
             INNER JOIN role_permissions rp ON rp.role_id = r.id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE em.estate_id = :estate_id AND em.status = 'active'
               AND p.slug = 'visitor.incident.manage'",
            ['estate_id' => $estateId]
        );

        $rolesByUser = [];

        foreach ($recipients as $row) {
            $userId = (int) $row['user_id'];

            if ($userId === $authorId) {
                continue;
            }

            $rolesByUser[$userId][] = (string) $row['role_name'];
        }

        $logger = new NotificationLogger($this->db);
        $title = 'New incident reported';
        $body = 'Suspicious activity · Low severity';
        $dedupeSuffix = 'incident:' . self::OPEN_INCIDENT_UUID . ':';

        foreach ($rolesByUser as $userId => $roles) {
            $link = array_intersect($roles, ['estate_admin', 'estate_manager']) !== []
                ? '/admin/incidents/' . self::OPEN_INCIDENT_UUID
                : '/gate/incidents';
            $dedupeKey = $dedupeSuffix . $userId;
            $recipient = 'u:' . $userId;

            $createdId = $logger->logInApp($estateId, $recipient, 'incident_reported', $title, $body, $link, $dedupeKey);

            // Ensure the per-user siren columns are set and reset unread state.
            $affected = $this->db->execute(
                "UPDATE notification_logs
                 SET user_id = :user_id, type = 'system', channel = 'in_app',
                     template = 'incident_reported', title = :title, body = :body, link = :link,
                     read_at = NULL, status = 'sent'
                 WHERE dedupe_key = :dedupe_key",
                [
                    'user_id' => $userId,
                    'title' => $title,
                    'body' => $body,
                    'link' => $link,
                    'dedupe_key' => $dedupeKey,
                ]
            );

            if ($createdId !== null) {
                $this->count('notifications_created');
            } elseif ($affected > 0) {
                $this->count('notifications_reset');
            }
        }

        return $this->counters();
    }

    /**
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException('Incident siren fixture needs ' . $description . '. Run the foundation demo seeder first.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function updateIfChanged(string $table, int $id, array $expected, string $counter): void
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
        $this->count($counter);
    }
}
