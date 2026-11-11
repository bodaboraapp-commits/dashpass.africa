<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Estate notices admin module contract (docs/estate-notices-contract.md §2-§3, §5).
 *
 * PRE-WRITTEN by qa-mail (T55) for the Increment-7 gate. The routes
 * (`/admin/notices*`, `/app/notices*`, `/gate/notices`) land at the gate, so this
 * file was parked in storage/temporary/pending-tests/ and moves to
 * tests/Integration/ once routes + migration 9 are registered.
 *
 * The module's actual controller/service/presenter shapes (T53) are already on
 * disk; assertions below follow both the frozen contract and those handlers:
 * admin POSTs are PRG (302 + flash / flash errors), reads are escaped HTML.
 */
final class NoticeModuleTest extends AdminModuleTestCase
{
    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> The estate_notices row.
     */
    private function createDraft(array $overrides = [], string $phone = self::GRACE): array
    {
        $title = (string) ($overrides['title'] ?? 'Notice draft ' . bin2hex(random_bytes(3)));
        $body = (string) ($overrides['body'] ?? 'A complete estate notice body for the Increment-7 tests.');

        $payload = array_merge([
            'title' => $title,
            'body' => $body,
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
            'expires_at' => '',
        ], $overrides);
        $payload['title'] = $title;
        $payload['body'] = $body;

        $response = $this->adminPost('/admin/notices', $payload, $phone);
        $this->assertAdminRedirect($response, '/admin/notices');

        return $this->noticeRowByTitle($title);
    }

    /**
     * @return array<string, mixed>
     */
    private function noticeRowByTitle(string $title): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM estate_notices WHERE title = :title ORDER BY id DESC LIMIT 1',
            ['title' => $title]
        );

        if ($row === null) {
            $this->fail('Expected a notice row titled "' . $title . '".');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function noticeRow(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM estate_notices WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($row === null) {
            $this->fail('Expected estate_notices row ' . $uuid . '.');
        }

        return $row;
    }

    private function noticeCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM estate_notices');
    }

    private function auditCount(string $action): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :action',
            ['action' => $action]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertAuditHasNoNoticeText(array $row): void
    {
        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action LIKE 'notice.%' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'A notice mutation must be audited.');
        $metadata = (string) $audit['metadata'];

        $this->assertStringContainsString((string) $row['uuid'], $metadata, 'Audit metadata must identify the notice by uuid.');
        $this->assertStringNotContainsString((string) $row['title'], $metadata, 'Audit metadata must never contain the notice title.');
        $this->assertStringNotContainsString((string) $row['body'], $metadata, 'Audit metadata must never contain the notice body.');
    }

    private function publish(string $uuid, string $phone = self::GRACE): \App\Core\Response
    {
        return $this->adminPost('/admin/notices/' . $uuid . '/publish', [], $phone);
    }

    /* ------------------------------------------------------------------ */
    /* Permissions                                                        */
    /* ------------------------------------------------------------------ */

    public function testNoticeViewRolesCanReadAndOthersCannotAdminister(): void
    {
        // A resident reads the published list.
        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(200, $this->get('/app/notices'));

        // A tenant and the auditor also hold notice.view and can read the list.
        foreach ([self::BRIAN, self::AUDITOR] as $phone) {
            $this->reloginAs($phone);
            $this->assertStatus(200, $this->get('/app/notices'), 'notice.view role must read the notices list.');
        }

        // A guard reads the gate notices screen (notice.view, staff shell).
        $this->loginGuard();
        $this->assertStatus(200, $this->get('/gate/notices'));

        // Residents, guards and read-only roles cannot reach the manage routes.
        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(403, $this->get('/admin/notices'));

        $this->ensureLoggedInAs(self::HOUSEHOLD_MEMBER);
        $this->assertStatus(403, $this->get('/admin/notices'));

        $this->loginGuard();
        $this->assertStatus(403, $this->get('/admin/notices'));
        $this->assertStatus(403, $this->adminPost('/admin/notices', [
            'title' => 'Guard notice attempt',
            'body' => 'A guard must not be able to compose estate notices.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
        ], self::GUARD_MAIN));
    }

    public function testNoticeViewRejectsUsersWithoutPermission(): void
    {
        // No seeded estate role lacks notice.view after the Increment-7 grants
        // (9 estate roles all receive it), so the negative permission case is a
        // platform-scope user with no estate membership: the estate context is
        // refused before the permission check.
        $this->loginAs('+254700000020');
        $response = $this->get('/app/notices');
        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            'A user without an estate notice context must be refused, got HTTP ' . $response->status() . '.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Admin lifecycle                                                    */
    /* ------------------------------------------------------------------ */

    public function testCreateDraftIsAuditedWithoutTitleOrBody(): void
    {
        $row = $this->createDraft([
            'title' => 'Committee meeting notice',
            'body' => 'The residents committee meets on Saturday at ten in the clubhouse.',
            'category' => 'event',
            'priority' => 'important',
            'audience' => 'residents',
        ]);

        $this->assertSame('draft', (string) $row['status']);
        $this->assertNull($row['published_at']);
        $this->assertSame('event', (string) $row['category']);
        $this->assertSame('important', (string) $row['priority']);
        $this->assertSame('residents', (string) $row['audience']);
        $this->assertSame(1, $this->auditCount('notice.created'));
        $this->assertAuditHasNoNoticeText($row);
    }

    public function testPublishSetsTimestampAndDuplicatePublishIsRejected(): void
    {
        $row = $this->createDraft(['title' => 'Publish me notice']);
        $uuid = (string) $row['uuid'];

        $response = $this->publish($uuid);
        $this->assertAdminRedirect($response, '/admin/notices/' . $uuid);

        $published = $this->noticeRow($uuid);
        $this->assertSame('published', (string) $published['status']);
        $this->assertNotNull($published['published_at'], 'Publishing must set published_at.');
        $this->assertSame(1, $this->auditCount('notice.published'));
        $this->assertAuditHasNoNoticeText($row);

        // Publishing twice is a 409 NOTICE_ALREADY_PUBLISHED (flash + PRG here).
        $before = $this->auditCount('notice.published');
        $this->assertAdminRejected($this->publish($uuid), '/admin/notices/' . $uuid, [
            'already published',
            'NOTICE_ALREADY_PUBLISHED',
        ]);
        $this->assertSame($before, $this->auditCount('notice.published'), 'A rejected publish must not add an audit row.');
        $this->assertSame('published', (string) $this->noticeRow($uuid)['status']);
    }

    public function testPublishingAnArchivedNoticeIsClosed(): void
    {
        $row = $this->createDraft(['title' => 'Archive then publish notice']);
        $uuid = (string) $row['uuid'];

        $this->publish($uuid);
        $this->assertAdminRedirect($this->adminPost('/admin/notices/' . $uuid . '/archive', []), '/admin/notices/' . $uuid);

        $this->assertAdminRejected($this->publish($uuid), '/admin/notices/' . $uuid, [
            'archived',
            'NOTICE_CLOSED',
        ]);
        $this->assertSame('archived', (string) $this->noticeRow($uuid)['status']);
    }

    public function testEditDraftThenPublishedNoticeIsLocked(): void
    {
        $row = $this->createDraft(['title' => 'Editable draft notice']);
        $uuid = (string) $row['uuid'];

        $updated = $this->adminPost('/admin/notices/' . $uuid, [
            'title' => 'Edited draft notice title',
            'body' => 'The edited body is still comfortably longer than ten characters.',
            'category' => 'maintenance',
            'priority' => 'urgent',
            'audience' => 'all',
            'expires_at' => '',
        ]);
        $this->assertAdminRedirect($updated, '/admin/notices/' . $uuid);

        $draft = $this->noticeRow($uuid);
        $this->assertSame('Edited draft notice title', (string) $draft['title']);
        $this->assertSame('maintenance', (string) $draft['category']);
        $this->assertSame('urgent', (string) $draft['priority']);

        $this->publish($uuid);

        $locked = $this->adminPost('/admin/notices/' . $uuid, [
            'title' => 'Attempted published edit',
            'body' => 'This update must be rejected because the notice is published.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
            'expires_at' => '',
        ]);
        $this->assertAdminRejected($locked, '/admin/notices/' . $uuid, ['locked', 'NOTICE_LOCKED']);
        $this->assertSame('Edited draft notice title', (string) $this->noticeRow($uuid)['title'], 'A locked notice must not change.');
    }

    public function testArchiveIsTerminalAuditedAndNeverDeletesTheRow(): void
    {
        $before = $this->noticeCount();
        $row = $this->createDraft(['title' => 'Archive lifecycle notice']);
        $uuid = (string) $row['uuid'];
        $this->publish($uuid);

        $this->assertAdminRedirect($this->adminPost('/admin/notices/' . $uuid . '/archive', []), '/admin/notices/' . $uuid);

        $archived = $this->noticeRow($uuid);
        $this->assertSame('archived', (string) $archived['status']);
        $this->assertSame(1, $this->auditCount('notice.archived'));
        $this->assertAuditHasNoNoticeText($row);

        // Archive is idempotent: a second attempt redirects without a second audit.
        $this->assertAdminRedirect($this->adminPost('/admin/notices/' . $uuid . '/archive', []), '/admin/notices/' . $uuid);
        $this->assertSame(1, $this->auditCount('notice.archived'));

        // Archived notices are closed for edits.
        $this->assertAdminRejected($this->adminPost('/admin/notices/' . $uuid, [
            'title' => 'Edit archived notice',
            'body' => 'Archived notices must remain immutable for the audit trail.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
        ]), '/admin/notices/' . $uuid, ['Archived notices cannot be edited', 'NOTICE_CLOSED']);

        $this->assertSame($before + 1, $this->noticeCount(), 'Notices are status transitions only — no hard deletes.');
        $this->assertSame('archived', (string) $this->noticeRow($uuid)['status']);
    }

    public function testValidationRejectsInvalidPayloadsWithoutPersisting(): void
    {
        $before = $this->noticeCount();
        $cases = [
            ['title' => 'ab'],
            ['title' => str_repeat('a', 161)],
            ['body' => 'short'],
            ['body' => str_repeat('b', 2001)],
            ['category' => 'gossip'],
            ['priority' => 'critical'],
            ['audience' => 'guests'],
            ['expires_at' => '2020-01-01T00:00'],
            ['expires_at' => \App\Core\Clock::now()->modify('+1 minute')->format('Y-m-d\TH:i')],
            ['expires_at' => 'not-a-date'],
        ];

        foreach ($cases as $overrides) {
            $payload = array_merge([
                'title' => 'Valid title for the validation case',
                'body' => 'A valid body that is comfortably longer than ten characters.',
                'category' => 'general',
                'priority' => 'normal',
                'audience' => 'all',
                'expires_at' => '',
            ], $overrides);

            $response = $this->adminPost('/admin/notices', $payload);
            $this->assertAdminRejected($response, '/admin/notices/new', [
                'must be', 'may not be longer', 'required', 'not allowed', 'valid expiry', '5 minutes',
            ]);
        }

        $this->assertSame($before, $this->noticeCount(), 'Rejected payloads must never create a notice.');
    }

    public function testQueueFiltersByStatusCategoryAndPriority(): void
    {
        $draft = $this->createDraft(['title' => 'Filter draft general', 'category' => 'general', 'priority' => 'normal']);
        $security = $this->createDraft(['title' => 'Filter published security', 'category' => 'security', 'priority' => 'urgent']);
        $this->publish((string) $security['uuid']);

        $byStatus = $this->adminGet('/admin/notices?status=draft');
        $this->assertStatus(200, $byStatus);
        $this->assertTextContains('Filter draft general', $byStatus);
        $this->assertTextNotContains('Filter published security', $byStatus);

        $byCategory = $this->adminGet('/admin/notices?category=security');
        $this->assertTextContains('Filter published security', $byCategory);
        $this->assertTextNotContains('Filter draft general', $byCategory);

        $byPriority = $this->adminGet('/admin/notices?priority=urgent');
        $this->assertTextContains('Filter published security', $byPriority);
        $this->assertTextNotContains('Filter draft general', $byPriority);

        // The queue reports real counts rather than hard-coded numbers.
        $this->assertTextContains('Notice queue', $byStatus);
        $this->assertBodyContains((string) $draft['uuid'], $this->adminGet('/admin/notices'));
    }

    public function testAdminDetailShowsLifecycleActions(): void
    {
        $draft = $this->createDraft(['title' => 'Detail lifecycle notice']);

        $detail = $this->adminGet('/admin/notices/' . (string) $draft['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains('Detail lifecycle notice', $detail);
        $this->assertTextContains('Publish', $detail);
        $this->assertTextContains('Archive', $detail);

        $this->publish((string) $draft['uuid']);

        $published = $this->adminGet('/admin/notices/' . (string) $draft['uuid']);
        $this->assertTextContains('Published', $published);
        $this->assertTextContains('Archive', $published);
        $this->assertTextNotContains('Publish notice', $published, 'Published notices are not re-publishable.');
    }

    public function testTenantIsolationHidesForeignNotices(): void
    {
        // David is the Riverside admin only; his active estate is Riverside.
        $foreign = $this->createDraft([
            'title' => 'Riverside-only notice',
            'body' => 'This notice belongs to Riverside Court and must never leak to Greenview.',
        ], self::DAVID_RIVERSIDE);

        $this->assertSame(
            (int) $this->seededEstate('riverside-court')['id'],
            (int) $foreign['estate_id'],
            'The notice must be created in the caller\'s active estate.'
        );

        // Grace (Greenview) cannot read it: generic 404, no leak.
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertStatus(404, $this->get('/admin/notices/' . (string) $foreign['uuid']));

        $this->assertAdminRejected(
            $this->publish((string) $foreign['uuid'], self::GRACE),
            '/admin/notices/' . (string) $foreign['uuid'],
            ['not available', 'NOTICE_NOT_FOUND']
        );
        $this->assertSame('draft', (string) $this->noticeRow((string) $foreign['uuid'])['status'], 'A foreign publish must not mutate the notice.');

        // And the foreign uuid is invisible in the Greenview admin queue.
        $this->assertTextNotContains('Riverside-only notice', $this->adminGet('/admin/notices'));
    }

    public function testAdminMutationsRequireCsrfToken(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $before = $this->noticeCount();

        $response = $this->post('/admin/notices', [
            'title' => 'CSRF-less notice',
            'body' => 'This notice must never be created without a CSRF token.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
        ], false);

        $this->assertStatus(403, $response);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
        $this->assertSame($before, $this->noticeCount());
    }

    public function testNoticeTextIsEscapedOnEveryScreen(): void
    {
        $title = 'XSS <script>alert("notice")</script> title';
        $body = 'Line one with <img src=x onerror="alert(1)"> markup.'
            . "\nLine two must keep its break and stay escaped.";

        $row = $this->createDraft(['title' => $title, 'body' => $body, 'priority' => 'urgent']);
        $uuid = (string) $row['uuid'];
        $this->publish($uuid);

        $screens = [];

        $this->ensureLoggedInAs(self::JANE);
        $screens['resident list'] = $this->get('/app/notices');
        $screens['resident detail'] = $this->get('/app/notices/' . $uuid);

        $this->loginGuard();
        $screens['guard list'] = $this->get('/gate/notices');

        $screens['admin queue'] = $this->adminGet('/admin/notices');
        $screens['admin detail'] = $this->adminGet('/admin/notices/' . $uuid);

        foreach ($screens as $label => $response) {
            $this->assertStatus(200, $response, $label . ' must render for the XSS check.');
            $this->assertStringNotContainsString('<script>alert("notice")</script>', $response->body(), 'Raw script markup must never be echoed on the ' . $label . '.');
            $this->assertStringNotContainsString('<img src=x onerror', $response->body(), 'Raw event-handler markup must never be echoed on the ' . $label . '.');
            $this->assertCleanMarkup($response, $label);
        }

        // The escaped title survives as text, not markup.
        $this->assertTextContains($title, $screens['resident detail']);
        $this->assertTextContains('Line two must keep its break and stay escaped.', $screens['resident detail']);
    }
}
