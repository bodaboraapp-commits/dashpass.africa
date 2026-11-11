<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Increment-7 demo notices (estate-notices-contract section 1).
 *
 * Four clearly demo-labelled notices for Greenview Residences only (Riverside
 * stays empty so the empty state can be demonstrated):
 *   - published / general   / normal  · "Water supply maintenance on Saturday";
 *   - published / security  / important · "Main gate barrier upgrade";
 *   - published / emergency / urgent  · "Lift maintenance - Block B" (expires +7d);
 *   - draft   / general   / normal  · "Upcoming community clean-up".
 *
 * Rows are matched by the stable UUID constants below, so `php scripts/install.php`
 * can run repeatedly without duplicating anything. Published timestamps/expiry
 * are anchored at first insert and never rewritten; only demo state (status,
 * audience, copy) self-heals after acceptance flows. The draft's timestamps are
 * reset to NULL when the demo is restored.
 */
final class DemoNoticeSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';

    public const NOTICE_WATER_UUID = '4a000001-0000-4000-8000-000000000001';
    public const NOTICE_BARRIER_UUID = '4a000001-0000-4000-8000-000000000002';
    public const NOTICE_LIFT_UUID = '4a000001-0000-4000-8000-000000000003';
    public const NOTICE_CLEANUP_UUID = '4a000001-0000-4000-8000-000000000004';

    /** @var array<int, string> */
    public const NOTICE_UUIDS = [
        self::NOTICE_WATER_UUID,
        self::NOTICE_BARRIER_UUID,
        self::NOTICE_LIFT_UUID,
        self::NOTICE_CLEANUP_UUID,
    ];

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'notices_created',
        'fixtures_updated',
    ];

    /**
     * Demo notices. `published` anchors are create-only:
     * two_days_ago_0900 | yesterday_1000 | three_hours_ago | null (draft).
     *
     * @var array<int, array{uuid: string, title: string, body: string, category: string, priority: string, status: string, audience: string, published: ?string, expires_in_days?: int}>
     */
    private const NOTICES = [
        [
            'uuid' => self::NOTICE_WATER_UUID,
            'title' => 'Water supply maintenance on Saturday',
            'body' => 'Water will be shut off on Saturday between 08:00 and 14:00 while the estate pump house is serviced. Please store water in advance.',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'published',
            'audience' => 'all',
            'published' => 'two_days_ago_0900',
        ],
        [
            'uuid' => self::NOTICE_BARRIER_UUID,
            'title' => 'Main gate barrier upgrade',
            'body' => 'The main gate barrier will be upgraded next week. Expect short delays at peak hours while the new unit is commissioned.',
            'category' => 'security',
            'priority' => 'important',
            'status' => 'published',
            'audience' => 'all',
            'published' => 'yesterday_1000',
        ],
        [
            'uuid' => self::NOTICE_LIFT_UUID,
            'title' => 'Lift maintenance - Block B',
            'body' => 'The Block B lift is under emergency maintenance. Please use the stairs or the Block A lift until further notice.',
            'category' => 'emergency',
            'priority' => 'urgent',
            'status' => 'published',
            'audience' => 'all',
            'published' => 'three_hours_ago',
            'expires_in_days' => 7,
        ],
        [
            'uuid' => self::NOTICE_CLEANUP_UUID,
            'title' => 'Upcoming community clean-up',
            'body' => 'Join neighbours for the monthly community clean-up in the shared gardens. Gloves and bags will be provided at the gate.',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'draft',
            'audience' => 'all',
            'published' => null,
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
                'Notice demo fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $estateId = (int) $estate['id'];
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);

        $createdBy = $this->requireId(
            'users',
            ['phone' => '+254700000001'],
            'estate admin Grace Wanjiku'
        );

        foreach (self::NOTICES as $notice) {
            $this->ensureNotice($estateId, $createdBy, $now, $notice);
        }

        return $this->counters();
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function ensureNotice(int $estateId, int $createdBy, DateTimeImmutable $now, array $spec): int
    {
        $existing = $this->findId('estate_notices', ['uuid' => $spec['uuid']]);

        if ($existing === null) {
            $publishedAt = $spec['published'] === null ? null : $this->publishedAt($now, (string) $spec['published']);
            $expiresAt = null;

            if ($publishedAt !== null && isset($spec['expires_in_days'])) {
                $moment = Clock::fromDb($publishedAt);

                if ($moment === null) {
                    throw new RuntimeException('Cannot resolve demo notice timestamp.');
                }

                $expiresAt = Clock::toDb($moment->modify('+' . (int) $spec['expires_in_days'] . ' days'));
            }

            $noticeId = $this->insert('estate_notices', [
                'uuid' => $spec['uuid'],
                'estate_id' => $estateId,
                'title' => $spec['title'],
                'body' => $spec['body'],
                'category' => $spec['category'],
                'priority' => $spec['priority'],
                'status' => $spec['status'],
                'audience' => $spec['audience'],
                'published_at' => $publishedAt,
                'expires_at' => $expiresAt,
                'created_by_user_id' => $createdBy,
                'updated_by_user_id' => null,
            ]);
            $this->count('notices_created');

            return $noticeId;
        }

        $expected = [
            'title' => $spec['title'],
            'body' => $spec['body'],
            'category' => $spec['category'],
            'priority' => $spec['priority'],
            'status' => $spec['status'],
            'audience' => $spec['audience'],
        ];

        if ($spec['status'] === 'draft') {
            // Restore the draft demo state if acceptance published it.
            $expected['published_at'] = null;
            $expected['expires_at'] = null;
        }

        $this->updateIfChanged('estate_notices', $existing, $expected);

        return $existing;
    }

    private function publishedAt(DateTimeImmutable $now, string $anchor): string
    {
        return match ($anchor) {
            'two_days_ago_0900' => Clock::toDb($now->modify('-2 days')->setTime(9, 0, 0)),
            'yesterday_1000' => Clock::toDb($now->modify('-1 day')->setTime(10, 0, 0)),
            'three_hours_ago' => Clock::toDb($now->modify('-3 hours')),
            default => throw new RuntimeException('Unknown demo notice published anchor: ' . $anchor),
        };
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
                'Notice demo fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }
}
