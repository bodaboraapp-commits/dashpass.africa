<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use Throwable;

/**
 * In-app notifications (Inc-13 P2a).
 *
 * Owns the per-user `notification_logs` rows (channel `in_app`), the notice
 * publish fanout, the bell data provider and the small inbox/read API. User
 * identity is stored in the existing `recipient` column as `u:{user_id}` — no
 * new table/user-id FK is needed, keeping the Inc-13 schema counts unchanged.
 *
 * All reads are owner-scoped. Failure to support the feature (migration not
 * applied, columns missing, flag off, query error) degrades to an empty,
 * disabled bell rather than a 500.
 */
final class NotificationService
{
    private const TYPES = ['notice', 'billing', 'reminder', 'system'];

    private const TYPE_LABELS = [
        'notice' => 'Notice',
        'billing' => 'Billing',
        'reminder' => 'Reminder',
        'system' => 'System',
    ];

    private const STAFF_ROLES = [
        'estate_admin',
        'estate_manager',
        'security_guard',
        'security_supervisor',
        'parking_manager',
    ];

    private const MANAGER_ROLES = ['estate_admin', 'estate_manager'];

    private const GUARD_ROLES = ['security_guard', 'security_supervisor', 'parking_manager'];

    /** @var bool|null Cached per process/request; reset by tests when schema changes. */
    private static ?bool $schemaReady = null;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly NotificationLogger $logger
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new NotificationLogger($context->db));
    }

    public static function resetSchemaCache(): void
    {
        self::$schemaReady = null;
    }

    /* ------------------------------------------------------------------ */
    /* Capability / degradation                                            */
    /* ------------------------------------------------------------------ */

    public function enabled(): bool
    {
        return (bool) Config::get('notifications.in_app_enabled', true)
            && $this->userId() > 0
            && $this->schemaReady();
    }

    public function schemaReady(): bool
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }

        try {
            $columns = $this->db->select(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs'
                   AND COLUMN_NAME IN ('dedupe_key','title','body','link','read_at')"
            );
        } catch (Throwable) {
            self::$schemaReady = false;

            return false;
        }

        self::$schemaReady = count($columns) === 5;

        return self::$schemaReady;
    }

    public function userId(): int
    {
        try {
            return (int) ($this->context->auth->id() ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function estateId(): int
    {
        try {
            $membership = $this->context->tenant->active();

            return $membership === null ? 0 : (int) $membership['estate_id'];
        } catch (Throwable) {
            return 0;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Notice fanout                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Create one in-app row per eligible recipient. Idempotent via the unique
     * dedupe key. Returns the number of rows created in this call.
     *
     * @param array<string, mixed> $notice Raw estate_notices row.
     */
    public function fanoutNotice(array $notice, int $authorId): int
    {
        if (!$this->schemaReady() || !(bool) Config::get('notifications.in_app_enabled', true)) {
            return 0;
        }

        $estateId = (int) ($notice['estate_id'] ?? 0);
        $noticeUuid = (string) ($notice['uuid'] ?? '');
        $audience = (string) ($notice['audience'] ?? 'all');
        $status = (string) ($notice['status'] ?? 'draft');

        if ($estateId <= 0 || $noticeUuid === '' || $status !== 'published') {
            return 0;
        }

        if (!in_array($audience, ['all', 'residents', 'staff'], true)) {
            return 0;
        }

        $expiresAt = isset($notice['expires_at']) && $notice['expires_at'] !== null
            ? Clock::fromDb((string) $notice['expires_at'])
            : null;

        if ($expiresAt !== null && $expiresAt <= Clock::now()) {
            $this->auditFanout($notice, $audience, 0, 0);

            return 0;
        }

        $rolesByUser = $this->staffRolesByUser($estateId);
        $recipientIds = [];

        if ($audience === 'all' || $audience === 'residents') {
            foreach ($this->residentUserIds($estateId, $authorId) as $id) {
                $recipientIds[$id] = true;
            }
        }

        if ($audience === 'all' || $audience === 'staff') {
            foreach (array_keys($rolesByUser) as $id) {
                if ($id !== $authorId) {
                    $recipientIds[$id] = true;
                }
            }
        }

        $title = $this->title((string) ($notice['title'] ?? ''));
        $body = $this->excerpt((string) ($notice['body'] ?? ''));
        $created = 0;

        foreach (array_keys($recipientIds) as $userId) {
            $userId = (int) $userId;

            if ($userId <= 0 || $userId === $authorId) {
                continue;
            }

            $roles = $rolesByUser[$userId] ?? [];
            $link = $this->noticeLink($noticeUuid, $roles);
            $rowId = $this->logger->logInApp(
                $estateId,
                'u:' . $userId,
                'notice_published',
                $title,
                $body,
                $link,
                'notice:' . $noticeUuid . ':' . $userId
            );

            if ($rowId !== null) {
                $created++;
            }
        }

        $this->auditFanout($notice, $audience, $created, count($recipientIds) - $created);

        return $created;
    }

    /**
     * @return array<int, int> distinct active resident user ids
     */
    private function residentUserIds(int $estateId, int $authorId): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT hm.user_id
             FROM household_members hm
             INNER JOIN households h
               ON h.id = hm.household_id AND h.estate_id = hm.estate_id AND h.status = 'active'
             INNER JOIN users u ON u.id = hm.user_id AND u.status = 'active'
             WHERE hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND hm.user_id <> :author_id",
            ['estate_id' => $estateId, 'author_id' => $authorId]
        );

        return array_values(array_map(static fn (array $row): int => (int) $row['user_id'], $rows));
    }

    /**
     * @return array<int, array<int, string>> user id => role names
     */
    private function staffRolesByUser(int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT em.user_id, r.name
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id AND u.status = 'active'
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id
               AND em.status = 'active'
               AND r.name IN ('estate_admin','estate_manager','security_guard','security_supervisor','parking_manager')",
            ['estate_id' => $estateId]
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = (string) $row['name'];
        }

        return $map;
    }

    /**
     * @param array<int, string> $roles
     */
    private function noticeLink(string $noticeUuid, array $roles): string
    {
        if (array_intersect($roles, self::MANAGER_ROLES) !== []) {
            return '/admin/notices/' . rawurlencode($noticeUuid);
        }

        if (array_intersect($roles, self::GUARD_ROLES) !== []) {
            return '/gate/notices';
        }
        return '/app/notices/' . rawurlencode($noticeUuid);
    }

    private function title(string $title): string
    {
        return mb_substr($this->clean($title), 0, 160);
    }

    private function excerpt(string $body): string
    {
        $limit = max(1, (int) Config::get('notifications.excerpt_limit', 140));

        return mb_substr($this->clean(strip_tags($body)), 0, $limit);
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param array<string, mixed> $notice
     */
    private function auditFanout(array $notice, string $audience, int $created, int $skipped): void
    {
        try {
            $this->context->audit->record('notice.notified', 'estate_notice', null, [
                'notice_uuid' => (string) ($notice['uuid'] ?? ''),
                'audience' => $audience,
                'recipients_count' => $created,
                'skipped_count' => max(0, $skipped),
            ], (int) ($notice['estate_id'] ?? 0));
        } catch (Throwable) {
            // Notification bookkeeping must never break publishing.
        }
    }

    /* ------------------------------------------------------------------ */
    /* Incident siren fanout + auto-read                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Fan out a new incident report to active incident-triage users (roles with
     * visitor.incident.manage). Author excluded; idempotent; PII-free excerpt.
     *
     * @param array<string, mixed> $incident
     */
    public function fanoutIncident(array $incident, int $authorId): int
    {
        if (!$this->schemaReady() || !(bool) Config::get('notifications.in_app_enabled', true)) {
            return 0;
        }

        $estateId = (int) ($incident['estate_id'] ?? 0);
        $incidentUuid = (string) ($incident['uuid'] ?? '');

        if ($estateId <= 0 || $incidentUuid === '') {
            return 0;
        }

        $rows = $this->db->select(
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

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];

            if ($userId === $authorId) {
                continue;
            }

            $rolesByUser[$userId][] = (string) $row['role_name'];
        }

        $type = $this->clean((string) ($incident['incident_type'] ?? 'other'));
        $severity = $this->clean((string) ($incident['severity'] ?? 'medium'));
        $title = 'New incident reported';
        $body = mb_substr(
            ucfirst(str_replace('_', ' ', $type)) . ' · ' . ucfirst($severity) . ' severity',
            0,
            (int) Config::get('notifications.excerpt_limit', 140)
        );
        $created = 0;

        foreach ($rolesByUser as $userId => $roles) {
            $link = array_intersect($roles, ['estate_admin', 'estate_manager']) !== []
                ? '/admin/incidents/' . rawurlencode($incidentUuid)
                : '/gate/incidents';
            $rowId = $this->logger->logInApp(
                $estateId,
                'u:' . $userId,
                'incident_reported',
                $title,
                $body,
                $link,
                'incident:' . $incidentUuid . ':' . $userId
            );

            if ($rowId !== null) {
                $created++;
            }
        }

        try {
            $this->context->audit->record('incident.notified', 'incident', (int) ($incident['id'] ?? 0), [
                'incident_uuid' => $incidentUuid,
                'recipients_count' => $created,
            ], $estateId);
        } catch (Throwable) {
            // Notification bookkeeping must never break incident reporting.
        }

        return $created;
    }

    /**
     * Auto-read the current user's incident notification when they open the
     * incident detail. Ownership-safe and idempotent.
     */
    public function markIncidentRead(string $incidentUuid): int
    {
        if ($incidentUuid === '' || !$this->enabled()) {
            return 0;
        }

        try {
            return $this->db->execute(
                "UPDATE notification_logs SET read_at = :read_at
                 WHERE channel = 'in_app' AND recipient = :recipient
                   AND template = 'incident_reported' AND read_at IS NULL
                   AND dedupe_key = :dedupe_key",
                [
                    'read_at' => Clock::nowDb(),
                    'recipient' => 'u:' . $this->userId(),
                    'dedupe_key' => 'incident:' . $incidentUuid . ':' . $this->userId(),
                ]
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{incidents_unread_count:int, incidents_latest_link:?string}
     */
    public function incidentSirenData(): array
    {
        if (!$this->enabled()) {
            return ['incidents_unread_count' => 0, 'incidents_latest_link' => null];
        }

        $recipient = 'u:' . $this->userId();

        try {
            $count = (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM notification_logs
                 WHERE channel = 'in_app' AND recipient = :recipient
                   AND template = 'incident_reported' AND read_at IS NULL",
                ['recipient' => $recipient]
            ) ?? 0);
            $latest = $this->db->selectOne(
                "SELECT link FROM notification_logs
                 WHERE channel = 'in_app' AND recipient = :recipient
                   AND template = 'incident_reported' AND read_at IS NULL
                 ORDER BY id DESC LIMIT 1",
                ['recipient' => $recipient]
            );
        } catch (Throwable) {
            return ['incidents_unread_count' => 0, 'incidents_latest_link' => null];
        }

        $link = $latest === null ? null : $this->safeInternalLink((string) ($latest['link'] ?? ''));

        return ['incidents_unread_count' => $count, 'incidents_latest_link' => $link];
    }

    /* ------------------------------------------------------------------ */
    /* Bell / inbox                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{notifications_enabled: bool, notifications_unread_count: int, notifications_latest: array<int, array<string, mixed>>}
     */
    public function shellData(): array
    {
        if (!$this->enabled()) {
            return [
                'notifications_enabled' => false,
                'notifications_unread_count' => 0,
                'notifications_latest' => [],
            ];
        }

        $userId = $this->userId();
        $recipient = 'u:' . $userId;
        $limit = max(1, (int) Config::get('notifications.latest_limit', 5));

        try {
            $unread = (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM notification_logs
                 WHERE channel = 'in_app' AND recipient = :recipient AND read_at IS NULL",
                ['recipient' => $recipient]
            ) ?? 0);

            $rows = $this->db->select(
                "SELECT uuid, template, title, body, link, read_at, created_at
                 FROM notification_logs
                 WHERE channel = 'in_app' AND recipient = :recipient
                 ORDER BY CASE WHEN read_at IS NULL THEN 0 ELSE 1 END, id DESC
                 LIMIT " . $limit,
                ['recipient' => $recipient]
            );
        } catch (Throwable) {
            return [
                'notifications_enabled' => false,
                'notifications_unread_count' => 0,
                'notifications_latest' => [],
            ];
        }

        return [
            'notifications_enabled' => true,
            'notifications_unread_count' => $unread,
            'notifications_latest' => array_map(fn (array $row): array => $this->present($row), $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inboxData(string $state = 'all', string $type = 'all'): array
    {
        $state = $state === 'unread' ? 'unread' : 'all';
        $type = in_array($type, self::TYPES, true) ? $type : 'all';
        $filters = ['state' => $state, 'type' => $type];

        if (!$this->enabled()) {
            return [
                'notifications_enabled' => false,
                'notifications' => [],
                'filters' => $filters,
                'counts' => ['unread' => 0, 'total' => 0],
            ];
        }

        $recipient = 'u:' . $this->userId();
        $limit = max(1, (int) Config::get('notifications.inbox_limit', 50));
        $where = ["channel = 'in_app'", 'recipient = :recipient'];
        $params = ['recipient' => $recipient];

        if ($state === 'unread') {
            $where[] = 'read_at IS NULL';
        }

        $typeSql = $this->typeWhere($type);

        if ($typeSql !== null) {
            $where[] = $typeSql;
        }

        try {
            $counts = $this->db->selectOne(
                "SELECT
                   SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
                   COUNT(*) AS total_count
                 FROM notification_logs
                 WHERE channel = 'in_app' AND recipient = :recipient",
                ['recipient' => $recipient]
            ) ?? ['unread_count' => 0, 'total_count' => 0];

            $rows = $this->db->select(
                'SELECT uuid, template, title, body, link, read_at, created_at
                 FROM notification_logs
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY id DESC
                 LIMIT ' . $limit,
                $params
            );
        } catch (Throwable) {
            return [
                'notifications_enabled' => false,
                'notifications' => [],
                'filters' => $filters,
                'counts' => ['unread' => 0, 'total' => 0],
            ];
        }

        return [
            'notifications_enabled' => true,
            'notifications' => array_map(fn (array $row): array => $this->present($row), $rows),
            'filters' => $filters,
            'counts' => [
                'unread' => (int) ($counts['unread_count'] ?? 0),
                'total' => (int) ($counts['total_count'] ?? 0),
            ],
        ];
    }

    /**
     * Mark one owned notification read. Returns the row (with link) or null when
     * the row does not belong to the current user/feature is unavailable.
     *
     * @return array<string, mixed>|null
     */
    public function markReadOwned(string $uuid): ?array
    {
        if (!$this->enabled() || $uuid === '') {
            return null;
        }

        $recipient = 'u:' . $this->userId();

        try {
            $row = $this->db->selectOne(
                "SELECT uuid, template, title, body, link, dedupe_key, read_at, created_at, estate_id
                 FROM notification_logs
                 WHERE uuid = :uuid AND channel = 'in_app' AND recipient = :recipient
                 LIMIT 1",
                ['uuid' => $uuid, 'recipient' => $recipient]
            );

            if ($row === null) {
                return null;
            }

            if ($row['read_at'] === null) {
                $readAt = Clock::nowDb();
                $this->db->update('notification_logs', ['read_at' => $readAt], [
                    'uuid' => $uuid,
                    'recipient' => $recipient,
                ]);
                $row['read_at'] = $readAt;
            }

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve a safe redirect target for a read notification. Keeps ownership and
     * link validation server-side; client-supplied redirects are never trusted.
     *
     * @param array<string, mixed> $row
     */
    public function readTarget(array $row): string
    {
        $link = $this->safeInternalLink((string) ($row['link'] ?? ''));

        if ($link === null) {
            return '/notifications';
        }

        if (str_starts_with($link, '/admin/notices/')) {
            $uuid = rawurldecode(substr($link, strlen('/admin/notices/')));

            return $this->noticeReachable($uuid, 'admin') ? $link : '/notifications';
        }

        if ($link === '/gate/notices' || $link === '/app/notices') {
            return $link;
        }

        if (str_starts_with($link, '/app/notices/')) {
            $uuid = rawurldecode(substr($link, strlen('/app/notices/')));

            return $this->noticeReachable($uuid, 'resident') ? $link : '/notifications';
        }

        return $link;
    }

    /**
     * A notice notification target is redirectable only while the notice is still
     * published/unexpired AND reachable by the current user (active estate
     * membership for admin targets; active household membership + resident/staff
     * audience for resident targets). Archived/expired notices fall back to the
     * inbox with the caller's generic flash; the notification row/read state is
     * retained.
     */
    private function noticeReachable(string $noticeUuid, string $target): bool
    {
        if ($noticeUuid === '') {
            return false;
        }

        try {
            $notice = $this->db->selectOne(
                "SELECT id, estate_id, audience FROM estate_notices
                 WHERE uuid = :uuid AND status = 'published'
                   AND (expires_at IS NULL OR expires_at > :now)
                 LIMIT 1",
                ['uuid' => $noticeUuid, 'now' => Clock::nowDb()]
            );

            if ($notice === null) {
                return false;
            }

            $userId = $this->userId();
            $estateId = (int) $notice['estate_id'];
            $audience = (string) ($notice['audience'] ?? 'all');

            if ($target === 'admin') {
                return $this->db->selectOne(
                    "SELECT id FROM estate_memberships
                     WHERE user_id = :user_id AND estate_id = :estate_id AND status = 'active'
                     LIMIT 1",
                    ['user_id' => $userId, 'estate_id' => $estateId]
                ) !== null;
            }

            if (!in_array($audience, ['all', 'residents'], true)) {
                return false;
            }

            return $this->db->selectOne(
                "SELECT hm.id FROM household_members hm
                 INNER JOIN households h ON h.id = hm.household_id
                   AND h.estate_id = hm.estate_id AND h.status = 'active'
                 WHERE hm.user_id = :user_id AND hm.estate_id = :estate_id AND hm.status = 'active'
                 LIMIT 1",
                ['user_id' => $userId, 'estate_id' => $estateId]
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function safeInternalLink(string $link): ?string
    {
        $link = trim($link);

        if ($link === '' || $link[0] !== '/' || str_starts_with($link, '//')) {
            return null;
        }

        if (preg_match('#^/(app/notices|admin/notices|gate/notices|admin/incidents|gate/incidents|notifications)(/|$)#', $link) !== 1) {
            return null;
        }

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $type = $this->typeForTemplate((string) ($row['template'] ?? ''));
        $readAt = $row['read_at'] === null || $row['read_at'] === ''
            ? null
            : Clock::fromDb((string) $row['read_at'])?->format('Y-m-d\TH:i:s.v\Z');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'title' => (string) ($row['title'] ?? ''),
            'body_excerpt' => (string) ($row['body'] ?? ''),
            'created_at' => Clock::fromDb(isset($row['created_at']) ? (string) $row['created_at'] : null)
                ?->format('Y-m-d\TH:i:s.v\Z'),
            'read_at' => $readAt,
            'unread' => $readAt === null,
            'link' => $this->safeInternalLink((string) ($row['link'] ?? '')) ?? '/notifications',
        ];
    }

    private function typeForTemplate(string $template): string
    {
        $template = strtolower($template);

        if (str_starts_with($template, 'notice')) {
            return 'notice';
        }

        if (str_contains($template, 'reminder')) {
            return 'reminder';
        }

        if (str_starts_with($template, 'billing') || str_starts_with($template, 'invoice')
            || str_starts_with($template, 'utility')) {
            return 'billing';
        }

        return 'system';
    }

    private function typeWhere(string $type): ?string
    {
        return match ($type) {
            'notice' => "template LIKE 'notice%'",
            'billing' => "(template LIKE 'billing%' OR template LIKE 'invoice%' OR template LIKE 'utility%')",
            'reminder' => "template LIKE '%reminder%'",
            'system' => "template NOT LIKE 'notice%' AND template NOT LIKE 'billing%'
                         AND template NOT LIKE 'invoice%' AND template NOT LIKE 'utility%'
                         AND template NOT LIKE '%reminder%'",
            default => null,
        };
    }
}
