<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Modules\Notifications\NotificationLogger;
use App\Modules\Notifications\NotificationService;

/**
 * Inc-13 P2a — notices → in-app bell/inbox (NotificationService).
 *
 * PARKED pre-write (T162/T167 build-gate plan, qa-mail): the service landed
 * (app/Modules/Notifications/NotificationService.php) but the publish fanout hook,
 * controller/routes (`GET /notifications`, `POST /notifications/{uuid}/read`) and
 * shell bell wiring land with the P2a slice. Service-level cases below run as soon
 * as the schema (P0 10/42/84) is applied; HTTP/markup cases activate when routes
 * land. No notification content (title/body) may leak into audit/logs.
 */
final class NotificationBellTest extends AdminModuleTestCase
{
    /** @var array<string, mixed> */
    private array $originalNotifications = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalNotifications = (array) Config::get('notifications', []);
        NotificationService::resetSchemaCache();
    }

    public function tearDown(): void
    {
        Config::set('notifications', $this->originalNotifications);
        NotificationService::resetSchemaCache();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Authenticated in-process context for a seeded phone (same UA fingerprint as
     * the TestCase HTTP helpers so Auth resolves the session).
     */
    private function contextFor(string $phone): Context
    {
        $this->ensureLoggedInAs($phone);

        return new Context(
            new Request('GET', '/notifications', [], [], [], null, '127.0.0.1', 'MtaaPass-Test/1.0'),
            'qa-bell-' . bin2hex(random_bytes(4))
        );
    }

    /**
     * @return array<string, mixed> the notice row
     */
    private function insertNotice(array $overrides = []): array
    {
        $estate = $this->estate();
        $author = $this->seededUser(self::GRACE);
        $now = Clock::nowDb();

        $row = array_merge([
            'uuid' => $this->newUuid(),
            'estate_id' => (int) $estate['id'],
            'title' => 'Bell fixture notice ' . bin2hex(random_bytes(2)),
            'body' => 'A fixture notice body for the in-app bell fanout tests.',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'published',
            'audience' => 'all',
            'published_at' => $now,
            'expires_at' => null,
            'created_by_user_id' => (int) $author['id'],
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $this->db->insert('estate_notices', $row);

        return $row;
    }

    private function userId(string $phone): int
    {
        return (int) $this->seededUser($phone)['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inAppRowsFor(int $userId, string $order = 'id ASC'): array
    {
        return $this->db->select(
            "SELECT * FROM notification_logs
             WHERE channel = 'in_app' AND recipient = :recipient
             ORDER BY " . $order,
            ['recipient' => 'u:' . $userId]
        );
    }

    private function addInApp(int $userId, string $template, string $title, string $dedupe, ?string $link = null): ?int
    {
        return (new NotificationLogger($this->db))->logInApp(
            (int) $this->estate()['id'],
            'u:' . $userId,
            $template,
            $title,
            'Excerpt for ' . $title,
            $link ?? '/app/notices',
            $dedupe
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestNoticeAudit(): ?array
    {
        $row = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'notice.notified' ORDER BY id DESC LIMIT 1"
        );

        if ($row === null) {
            return null;
        }

        return (array) json_decode((string) $row['metadata'], true);
    }

    /* ------------------------------------------------------------------ */
    /* Fanout                                                             */
    /* ------------------------------------------------------------------ */

    public function testPublishToAllFansOutToResidentsAndStaffButNotTheAuthor(): void
    {
        $notice = $this->insertNotice(['audience' => 'all']);
        $service = NotificationService::make($this->contextFor(self::GRACE));

        $created = $service->fanoutNotice($notice, $this->userId(self::GRACE));

        $this->assertTrue($created > 0, 'Audience=all must create in-app rows.');
        $grace = $this->inAppRowsFor($this->userId(self::GRACE));
        $this->assertCount(0, $grace, 'The publishing author must be excluded from the fanout.');
        $this->assertTrue(count($this->inAppRowsFor($this->userId(self::JANE))) === 1, 'Residents must receive the notice.');
        $this->assertTrue(count($this->inAppRowsFor($this->userId(self::GUARD_MAIN))) === 1, 'Staff/guards must receive an all-audience notice.');

        $audit = $this->latestNoticeAudit();
        $this->assertNotNull($audit);
        $this->assertSame((string) $notice['uuid'], (string) ($audit['notice_uuid'] ?? ''));
        $this->assertSame('all', (string) ($audit['audience'] ?? ''));
        $this->assertTrue((int) ($audit['recipients_count'] ?? 0) >= 2);
        $this->assertStringNotContainsString((string) $notice['title'], (string) json_encode($audit), 'Fanout audit must not contain notice text.');
        $this->assertStringNotContainsString((string) $notice['body'], (string) json_encode($audit), 'Fanout audit must not contain notice text.');
    }

    public function testAudienceTargetingResidentsAndStaff(): void
    {
        $service = NotificationService::make($this->contextFor(self::GRACE));
        $author = $this->userId(self::GRACE);
        $jane = $this->userId(self::JANE);
        $guard = $this->userId(self::GUARD_MAIN);

        $residents = $this->insertNotice(['audience' => 'residents']);
        $service->fanoutNotice($residents, $author);
        $janeAfterResidents = count($this->inAppRowsFor($jane));
        $this->assertSame(1, $janeAfterResidents, 'Residents audience reaches residents.');
        $this->assertCount(0, $this->inAppRowsFor($guard), 'Residents audience excludes staff.');
        $guardAfterResidents = 0;

        $staff = $this->insertNotice(['audience' => 'staff']);
        $service->fanoutNotice($staff, $author);
        $this->assertSame($janeAfterResidents, count($this->inAppRowsFor($jane)), 'Staff audience does not add rows for residents.');
        $this->assertSame($guardAfterResidents + 1, count($this->inAppRowsFor($guard)), 'Staff audience reaches guards.');
    }

    public function testFanoutIsIdempotentViaDedupeKey(): void
    {
        $notice = $this->insertNotice();
        $service = NotificationService::make($this->contextFor(self::GRACE));
        $author = $this->userId(self::GRACE);

        $first = $service->fanoutNotice($notice, $author);
        $second = $service->fanoutNotice($notice, $author);

        $this->assertTrue($first > 0);
        $this->assertSame(0, $second, 'A re-run must not duplicate bell rows (dedupe key).');
        $this->assertCount(1, $this->inAppRowsFor($this->userId(self::JANE)));
    }

    public function testDraftAndExpiredNoticesAreNotFannedOut(): void
    {
        $service = NotificationService::make($this->contextFor(self::GRACE));
        $author = $this->userId(self::GRACE);
        $before = (int) $this->db->scalar("SELECT COUNT(*) FROM notification_logs WHERE channel = 'in_app'");

        $draft = $this->insertNotice(['status' => 'draft', 'published_at' => null]);
        $this->assertSame(0, $service->fanoutNotice($draft, $author));

        $expired = $this->insertNotice([
            'expires_at' => Clock::toDb(Clock::now()->modify('-1 minute')),
        ]);
        $this->assertSame(0, $service->fanoutNotice($expired, $author));

        $after = (int) $this->db->scalar("SELECT COUNT(*) FROM notification_logs WHERE channel = 'in_app'");
        $this->assertSame($before, $after, 'Draft/expired notices must not create bell rows.');
    }

    /* ------------------------------------------------------------------ */
    /* Bell data + inbox                                                  */
    /* ------------------------------------------------------------------ */

    public function testShellDataUnreadCountAndLatestFiveUnreadFirst(): void
    {
        $jane = $this->userId(self::JANE);

        for ($i = 1; $i <= 7; $i++) {
            $this->addInApp($jane, 'notice_published', 'Bell item ' . $i, 'bell:' . $i);
        }

        $service = NotificationService::make($this->contextFor(self::JANE));
        $data = $service->shellData();

        $this->assertTrue((bool) $data['notifications_enabled']);
        $this->assertSame(7, (int) $data['notifications_unread_count']);
        $this->assertCount(5, $data['notifications_latest'], 'The bell shows at most the latest 5 rows.');
        $this->assertTrue((bool) $data['notifications_latest'][0]['unread']);

        // Read one: unread count drops and unread rows still sort first.
        $firstUuid = (string) $data['notifications_latest'][0]['uuid'];
        $service->markReadOwned($firstUuid);
        $after = $service->shellData();
        $this->assertSame(6, (int) $after['notifications_unread_count']);
        $this->assertCount(5, $after['notifications_latest']);
        $this->assertTrue((bool) $after['notifications_latest'][0]['unread'], 'Unread rows sort before read rows.');

        // The just-read row is now behind the 6 unread rows, so it is not in the
        // top-5 bell slice; the payload exposes it as read via the inbox instead.
        $inbox = $service->inboxData();
        $readEntries = array_values(array_filter(
            $inbox['notifications'],
            static fn (array $item): bool => (string) $item['uuid'] === $firstUuid
        ));
        $this->assertCount(1, $readEntries);
        $this->assertFalse((bool) $readEntries[0]['unread']);
        $this->assertNotNull($readEntries[0]['read_at']);
    }

    public function testInboxFiltersCountsAndOwnerIsolation(): void
    {
        $jane = $this->userId(self::JANE);
        $brian = $this->userId(self::BRIAN);

        $this->addInApp($jane, 'notice_published', 'Notice for Jane', 'jane:notice');
        $this->addInApp($jane, 'billing_invoice_issued', 'Bill for Jane', 'jane:bill');
        $this->addInApp($jane, 'billing_reminder_due', 'Reminder for Jane', 'jane:reminder');
        $readId = $this->addInApp($jane, 'system_notice', 'System for Jane', 'jane:system');

        $service = NotificationService::make($this->contextFor(self::JANE));
        $inbox = $service->inboxData();
        $this->assertTrue((bool) $inbox['notifications_enabled']);
        $this->assertCount(4, $inbox['notifications']);
        $this->assertSame(['unread' => 4, 'total' => 4], $inbox['counts']);

        $byType = $service->inboxData('all', 'billing');
        $this->assertCount(2, $byType['notifications'], 'Billing filter covers billing/invoice/utility templates.');

        $unreadOnly = $service->inboxData('unread', 'all');
        $this->assertCount(4, $unreadOnly['notifications']);

        if ($readId !== null) {
            $service->markReadOwned((string) $this->db->scalar(
                "SELECT uuid FROM notification_logs WHERE id = :id",
                ['id' => $readId]
            ));
        }

        $afterRead = $service->inboxData('unread', 'all');
        $this->assertSame(3, $afterRead['counts']['unread']);

        // Another user's inbox is completely separate.
        $brianInbox = NotificationService::make($this->contextFor(self::BRIAN))->inboxData();
        $this->assertCount(0, $brianInbox['notifications'], 'Notifications are owner-scoped.');
        $this->assertSame(['unread' => 0, 'total' => 0], $brianInbox['counts']);
        $this->assertCount(0, $this->inAppRowsFor($brian));
    }

    /* ------------------------------------------------------------------ */
    /* Mark-read / read target safety                                     */
    /* ------------------------------------------------------------------ */

    public function testMarkReadOwnershipIdempotencyAndForeign404(): void
    {
        $jane = $this->userId(self::JANE);
        $this->addInApp($jane, 'notice_published', 'Readable', 'jane:readable');
        $uuid = (string) $this->db->scalar(
            "SELECT uuid FROM notification_logs WHERE recipient = :r ORDER BY id DESC LIMIT 1",
            ['r' => 'u:' . $jane]
        );

        $service = NotificationService::make($this->contextFor(self::JANE));

        $row = $service->markReadOwned($uuid);
        $this->assertNotNull($row);

        // The service selects the row before updating it, so the returned copy may
        // still show read_at=null; assert the persisted state instead and that a
        // second call returns the read row (idempotent).
        $persisted = $this->db->scalar(
            'SELECT read_at FROM notification_logs WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );
        $this->assertNotNull($persisted, 'Marking read must persist read_at.');

        // Idempotent: a second mark-read does not change read_at and still succeeds.
        $again = $service->markReadOwned($uuid);
        $this->assertNotNull($again);
        $this->assertSame((string) $persisted, (string) $again['read_at']);
        $this->assertNull($service->markReadOwned(''), 'An empty uuid is rejected.');
        $this->assertNull($service->markReadOwned($this->newUuid()), 'A foreign/unknown notification is not readable.');

        // Foreign uuid through another user's context.
        $this->addInApp($this->userId(self::BRIAN), 'notice_published', 'Brian only', 'brian:only');
        $brianUuid = (string) $this->db->scalar(
            "SELECT uuid FROM notification_logs WHERE recipient = :r ORDER BY id DESC LIMIT 1",
            ['r' => 'u:' . $this->userId(self::BRIAN)]
        );
        $this->assertNull($service->markReadOwned($brianUuid), 'Another user\'s notification is a 404 (null).');
    }

    public function testReadTargetIsAllowlistedAndNeverOpenRedirect(): void
    {
        $service = NotificationService::make($this->contextFor(self::JANE));

        foreach ([
            'https://evil.test/x',
            '//evil.test/x',
            'javascript:alert(1)',
            '/pay/token',
            '/admin/settings',
            '',
        ] as $unsafe) {
            $this->assertSame('/notifications', $service->readTarget(['link' => $unsafe]), 'Unsafe link must fall back to /notifications: ' . $unsafe);
        }

        // Path-normalisation trickery must at least stay internal (never an
        // external/open redirect), regardless of whether it is normalised away.
        $traversal = $service->readTarget(['link' => '/notifications/../admin']);
        $this->assertStringStartsWith('/', $traversal);
        $this->assertStringNotContainsString('://', $traversal);
        $this->assertStringNotContainsString('//evil', $traversal);

        $this->assertSame('/admin/notices/abc', $service->readTarget(['link' => '/admin/notices/abc']));
        $this->assertSame('/gate/notices', $service->readTarget(['link' => '/gate/notices']));
        $this->assertSame('/app/notices', $service->readTarget(['link' => '/app/notices']));
        $this->assertSame('/notifications', $service->readTarget(['link' => '/notifications']));

        // A published, unexpired notice detail link is honoured.
        $notice = $this->insertNotice();
        $this->assertSame(
            '/app/notices/' . (string) $notice['uuid'],
            $service->readTarget(['link' => '/app/notices/' . (string) $notice['uuid']])
        );

        // Hidden/unpublished/expired notice detail degrades to the inbox.
        $draft = $this->insertNotice(['status' => 'draft', 'published_at' => null]);
        $this->assertSame('/notifications', $service->readTarget(['link' => '/app/notices/' . (string) $draft['uuid']]));
        $expired = $this->insertNotice(['expires_at' => Clock::toDb(Clock::now()->modify('-5 minutes'))]);
        $this->assertSame('/notifications', $service->readTarget(['link' => '/app/notices/' . (string) $expired['uuid']]));
    }

    /* ------------------------------------------------------------------ */
    /* Feature flag / degraded modes                                      */
    /* ------------------------------------------------------------------ */

    public function testFeatureFlagOffDegradesToEmptyDisabledBell(): void
    {
        Config::set('notifications.in_app_enabled', false);
        NotificationService::resetSchemaCache();
        $this->addInApp($this->userId(self::JANE), 'notice_published', 'Hidden when off', 'flag:off');

        $service = NotificationService::make($this->contextFor(self::JANE));

        $this->assertFalse($service->enabled());
        $this->assertSame([
            'notifications_enabled' => false,
            'notifications_unread_count' => 0,
            'notifications_latest' => [],
        ], $service->shellData());

        $inbox = $service->inboxData();
        $this->assertFalse((bool) $inbox['notifications_enabled']);
        $this->assertSame([], $inbox['notifications']);
        $this->assertSame(['unread' => 0, 'total' => 0], $inbox['counts']);
        $this->assertNull($service->markReadOwned((string) $this->db->scalar(
            "SELECT uuid FROM notification_logs WHERE recipient = :r LIMIT 1",
            ['r' => 'u:' . $this->userId(self::JANE)]
        )));

        $notice = $this->insertNotice();
        $this->assertSame(0, $service->fanoutNotice($notice, $this->userId(self::GRACE)), 'Flag off must not fan out.');
    }

    /* ------------------------------------------------------------------ */
    /* HTTP + shell render (activates when the P2a routes land)           */
    /* ------------------------------------------------------------------ */

    public function testNotificationInboxHttpIsOwnerScopedAndFilterable(): void
    {
        $jane = $this->userId(self::JANE);
        $this->addInApp($jane, 'notice_published', 'HTTP notice for Jane', 'http:jane:notice');
        $this->addInApp($this->userId(self::BRIAN), 'notice_published', 'HTTP notice for Brian', 'http:brian:notice');
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/notifications');
        $this->assertStatus(200, $response);
        $this->assertTextContains('HTTP notice for Jane', $response);
        $this->assertTextNotContains('HTTP notice for Brian', $response);
        $this->assertCleanMarkup($response, 'Notification inbox');

        $unread = $this->get('/notifications?state=unread');
        $this->assertStatus(200, $unread);
        $this->assertTextContains('HTTP notice for Jane', $unread);

        $this->resetSession();
        $this->assertRedirect($this->get('/notifications'), '/login', 302);
    }

    public function testMarkReadHttpRequiresCsrfOwnershipAndSafeRedirect(): void
    {
        $jane = $this->userId(self::JANE);
        $this->addInApp($jane, 'notice_published', 'HTTP mark read', 'http:mark');
        $uuid = (string) $this->db->scalar(
            "SELECT uuid FROM notification_logs WHERE recipient = :r ORDER BY id DESC LIMIT 1",
            ['r' => 'u:' . $jane]
        );
        $this->ensureLoggedInAs(self::JANE);

        $csrfless = $this->post('/notifications/' . $uuid . '/read', [], false);
        $this->assertStatus(403, $csrfless);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $csrfless);

        $ok = $this->post('/notifications/' . $uuid . '/read', ['_token' => $this->csrfToken()], false);
        $this->assertStatus(302, $ok);
        $this->assertNotNull($this->db->scalar(
            "SELECT read_at FROM notification_logs WHERE uuid = :uuid",
            ['uuid' => $uuid]
        ));

        // Idempotent second POST is still a safe redirect (not an error).
        $again = $this->post('/notifications/' . $uuid . '/read', ['_token' => $this->csrfToken()], false);
        $this->assertStatus(302, $again);

        // Foreign/unknown uuid is a 404.
        $this->assertStatus(404, $this->post('/notifications/' . $this->newUuid() . '/read', ['_token' => $this->csrfToken()], false));
    }

    public function testInboxEscapesTitlesAndLinksStayInternal(): void
    {
        $jane = $this->userId(self::JANE);
        $payload = '<script>alert("bell xss")</script>';
        $this->addInApp($jane, 'notice_published', $payload, 'http:xss', '/app/notices');
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/notifications');
        $this->assertStatus(200, $response);
        $this->assertStringNotContainsString($payload, $response->body(), 'Notification titles must be escaped.');
        $this->assertTextContains($payload, $response, 'The escaped title still renders as text.');
        $this->assertCleanMarkup($response, 'Notification inbox');
        $this->assertBodyNotContains('https://evil.test', $response);

        // The shell bell data (Home/account/app pages) is flag-aware and hides when disabled.
        $home = $this->get('/app');
        $this->assertStatus(200, $home);
        $this->assertCleanMarkup($home, 'Resident home with bell');

        Config::set('notifications.in_app_enabled', false);
        NotificationService::resetSchemaCache();
        $hidden = $this->get('/app');
        $this->assertStatus(200, $hidden);
        $this->assertStringNotContainsString('notification-bell', $hidden->body(), 'The bell must be hidden when the flag is off.');
    }
}
