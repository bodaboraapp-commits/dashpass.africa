<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Platform estate/plan/subscription management + platform audit
 * (docs/platform-admin-contract.md §2, §4).
 *
 * PRE-WRITTEN by qa-mail (T66) for the Increment-9 gate; parked in
 * storage/temporary/pending-tests/ until T64/T65 land the controllers + views.
 *
 * The contract does not freeze every form field name; payload helpers send the
 * canonical field plus the obvious alias so the gate only needs a small rename
 * pass if T64 chose different names.
 */
final class PlatformManagementTest extends AdminModuleTestCase
{
    protected const PLATFORM_ADMIN = '+254700000020';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function platformGet(string $path, string $phone = self::PLATFORM_ADMIN): \App\Core\Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->get($path);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function platformPost(
        string $path,
        array $body = [],
        string $phone = self::PLATFORM_ADMIN,
        bool $csrf = true
    ): \App\Core\Response {
        $this->ensureLoggedInAs($phone);

        return $this->post($path, $body, $csrf);
    }

    /**
     * @return array<string, mixed>
     */
    private function seededEstateRow(string $slug): array
    {
        return $this->seededEstate($slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function planByCode(string $code): array
    {
        $plan = $this->db->selectOne('SELECT * FROM subscription_plans WHERE code = :code LIMIT 1', ['code' => $code]);

        if ($plan === null) {
            $this->fail('Seeded plan "' . $code . '" not found.');
        }

        return $plan;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function subscriptionsForEstate(array $estate): array
    {
        return $this->db->select(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id ASC',
            ['eid' => (int) $estate['id']]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :action',
            ['action' => $action]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestAudit(string $action): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM audit_logs WHERE action = :action ORDER BY id DESC LIMIT 1',
            ['action' => $action]
        );
    }

    /**
     * Platform forms are web PRG; a rejection may be 422, 409 or a 302 flash.
     *
     * @param array<int, string> $markers
     */
    private function assertPlatformRejected(\App\Core\Response $response, string $fallbackPath, array $markers): void
    {
        if (in_array($response->status(), [409, 422], true)) {
            $haystack = $response->body();
        } elseif ($response->status() === 302) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);
            $haystack = $follow->body();
        } else {
            $this->fail('Expected a platform rejection (409/422/PRG 302), got HTTP ' . $response->status() . '.');
        }

        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return;
            }
        }

        $this->fail('The rejection must contain one of: ' . implode(', ', $markers) . '.');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createPlanPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'QA_PLAN_' . strtoupper(bin2hex(random_bytes(2))),
            'name' => 'QA Plan ' . bin2hex(random_bytes(2)),
            'monthly_price_cents' => 125000,
            'currency' => 'KES',
            'household_limit' => 25,
            'gate_device_limit' => 1,
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* Estate status                                                      */
    /* ------------------------------------------------------------------ */

    public function testEstateStatusTransitionsAreAuditedAndNeverDeleteData(): void
    {
        $estate = $this->seededEstateRow(self::ESTATE_SLUG);
        $before = [
            'estates' => (int) $this->db->scalar('SELECT COUNT(*) FROM estates'),
            'households' => (int) $this->db->scalar('SELECT COUNT(*) FROM households'),
            'audit' => $this->auditCount('platform.estate_status_changed'),
        ];

        foreach (['suspended', 'active', 'archived', 'active'] as $status) {
            $response = $this->platformPost('/platform/estates/' . (string) $estate['uuid'] . '/status', [
                'status' => $status,
            ]);
            $this->assertStatus(302, $response, 'A status change must redirect (PRG).');

            $this->assertSame($status, (string) $this->db->scalar(
                'SELECT status FROM estates WHERE id = :id',
                ['id' => (int) $estate['id']]
            ));
        }

        $this->assertSame(
            $before['audit'] + 4,
            $this->auditCount('platform.estate_status_changed'),
            'Every estate status transition must be audited.'
        );
        $this->assertSame($before['estates'], (int) $this->db->scalar('SELECT COUNT(*) FROM estates'), 'Estates are never hard-deleted.');
        $this->assertSame($before['households'], (int) $this->db->scalar('SELECT COUNT(*) FROM households'), 'Estate data must be retained.');

        $audit = $this->latestAudit('platform.estate_status_changed');
        $this->assertNotNull($audit);
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $estate['uuid'], $metadata, 'Audit metadata must identify the estate by uuid.');
        $this->assertStringContainsString('active', $metadata);
        $this->assertStringNotContainsString((string) $estate['name'], $metadata, 'Audit metadata must not carry the estate name.');
    }

    public function testInvalidEstateStatusIsRejected(): void
    {
        $estate = $this->seededEstateRow(self::ESTATE_SLUG);

        $response = $this->platformPost('/platform/estates/' . (string) $estate['uuid'] . '/status', [
            'status' => 'deleted',
        ]);

        $this->assertPlatformRejected($response, '/platform/estates/' . (string) $estate['uuid'], ['status', 'not allowed', 'valid']);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM estates WHERE id = :id',
            ['id' => (int) $estate['id']]
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Plans                                                              */
    /* ------------------------------------------------------------------ */

    public function testPlanCreateRetireDuplicateAndValidation(): void
    {
        $payload = $this->createPlanPayload();

        $created = $this->platformPost('/platform/plans', $payload);
        $this->assertStatus(302, $created, 'Creating a plan must redirect (PRG).');

        $plan = $this->db->selectOne('SELECT * FROM subscription_plans WHERE code = :code', ['code' => $payload['code']]);
        $this->assertNotNull($plan, 'The plan must be stored.');
        $this->assertSame('active', (string) $plan['status']);
        $this->assertSame(125000, (int) $plan['monthly_price_cents']);
        $this->assertSame(1, $this->auditCount('platform.plan_created'));

        // Duplicate code → 422 PLAN_EXISTS.
        $this->assertPlatformRejected(
            $this->platformPost('/platform/plans', $this->createPlanPayload(['code' => $payload['code']])),
            '/platform/plans',
            ['PLAN_EXISTS', 'already exists', 'code']
        );
        $this->assertSame(1, $this->auditCount('platform.plan_created'), 'A rejected plan must not be audited.');

        // Invalid code format → validation error.
        $this->assertPlatformRejected(
            $this->platformPost('/platform/plans', $this->createPlanPayload(['code' => 'bad code!'])),
            '/platform/plans',
            ['uppercase', 'code', 'not allowed', 'valid']
        );

        // Numeric bounds: monthly_price_cents must be >= 0 by VALUE. The
        // Validator `min` rule is length-based, so a negative price must still be
        // rejected (product rule: integer money >= 0). No plan row may be written.
        $negativePrice = $this->createPlanPayload(['monthly_price_cents' => -100]);
        $negativeResponse = $this->platformPost('/platform/plans', $negativePrice);
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM subscription_plans WHERE code = :code', ['code' => $negativePrice['code']]),
            'A negative plan price must never be stored (numeric bounds, not length).'
        );
        $this->assertPlatformRejected($negativeResponse, '/platform/plans', [
            'price', 'valid', 'not allowed', 'between', 'required',
        ]);

        // Retire: status only, row retained.
        $starter = $this->planByCode('starter');
        $retire = $this->platformPost('/platform/plans/' . (string) $starter['uuid'] . '/retire', []);
        $this->assertStatus(302, $retire);
        $this->assertSame('retired', (string) $this->db->scalar(
            'SELECT status FROM subscription_plans WHERE id = :id',
            ['id' => (int) $starter['id']]
        ));
        $this->assertSame(1, $this->auditCount('platform.plan_retired'));
        $this->assertSame((int) $starter['id'], (int) $this->db->scalar(
            'SELECT id FROM subscription_plans WHERE id = :id',
            ['id' => (int) $starter['id']]
        ), 'Retired plans stay in the table (no hard delete).');
    }

    public function testPlanTextIsEscapedOnThePlansScreen(): void
    {
        $markupName = '<script>alert("plan xss")</script> Plan';
        $this->assertStatus(302, $this->platformPost('/platform/plans', $this->createPlanPayload([
            'code' => 'QA_XSS_' . strtoupper(bin2hex(random_bytes(2))),
            'name' => $markupName,
        ])));

        $page = $this->platformGet('/platform/plans');
        $this->assertStatus(200, $page);
        $this->assertStringNotContainsString('<script>alert("plan xss")</script>', $page->body(), 'Plan names must be escaped.');
        $this->assertCleanMarkup($page, 'Platform plans');
        $this->assertTextContains($markupName, $page);
    }

    /* ------------------------------------------------------------------ */
    /* Subscriptions                                                      */
    /* ------------------------------------------------------------------ */

    public function testSubscriptionPlanChangeAndStatusLifecycle(): void
    {
        $estate = $this->seededEstateRow(self::ESTATE_SLUG);
        $subscriptions = $this->subscriptionsForEstate($estate);
        $this->assertCount(1, $subscriptions, 'One current subscription per estate is seeded.');
        $subscription = $subscriptions[0];
        $starter = $this->planByCode('starter');

        // Change plan.
        $change = $this->platformPost('/platform/subscriptions/' . (string) $subscription['uuid'], [
            'plan_uuid' => (string) $starter['uuid'],
            'plan_id' => (int) $starter['id'],
        ]);
        $this->assertStatus(302, $change, 'Changing the subscription plan must redirect (PRG).');
        $this->assertSame((int) $starter['id'], (int) $this->db->scalar(
            'SELECT plan_id FROM subscriptions WHERE id = :id',
            ['id' => (int) $subscription['id']]
        ));
        $this->assertSame(1, $this->auditCount('platform.subscription_changed'));
        $this->assertCount(1, $this->subscriptionsForEstate($estate), 'A plan change must not spawn a second subscription.');

        // Status transitions.
        foreach (['suspended', 'active', 'past_due', 'active'] as $status) {
            $this->assertStatus(302, $this->platformPost('/platform/subscriptions/' . (string) $subscription['uuid'], [
                'status' => $status,
            ]));
            $this->assertSame($status, (string) $this->db->scalar(
                'SELECT status FROM subscriptions WHERE id = :id',
                ['id' => (int) $subscription['id']]
            ));
        }

        // cancelled is terminal → SUBSCRIPTION_CLOSED, row retained.
        $this->assertStatus(302, $this->platformPost('/platform/subscriptions/' . (string) $subscription['uuid'], [
            'status' => 'cancelled',
        ]));
        $this->assertSame('cancelled', (string) $this->db->scalar(
            'SELECT status FROM subscriptions WHERE id = :id',
            ['id' => (int) $subscription['id']]
        ));

        $this->assertPlatformRejected(
            $this->platformPost('/platform/subscriptions/' . (string) $subscription['uuid'], ['status' => 'active']),
            '/platform/subscriptions',
            ['SUBSCRIPTION_CLOSED', 'closed', 'cannot']
        );
        $this->assertSame('cancelled', (string) $this->db->scalar(
            'SELECT status FROM subscriptions WHERE id = :id',
            ['id' => (int) $subscription['id']]
        ), 'A terminal subscription must not be reopened.');
        $this->assertSame(
            (int) $subscription['id'],
            (int) $this->db->scalar('SELECT id FROM subscriptions WHERE id = :id', ['id' => (int) $subscription['id']]),
            'Subscriptions are never hard-deleted.'
        );
    }

    public function testNoEstateHasMoreThanOneCurrentSubscription(): void
    {
        $rows = $this->db->select(
            'SELECT estate_id, COUNT(*) AS total FROM subscriptions GROUP BY estate_id'
        );

        foreach ($rows as $row) {
            $this->assertTrue(
                (int) $row['total'] <= 1,
                'Estate ' . $row['estate_id'] . ' has more than one current subscription.'
            );
        }

        // There is no POST /platform/subscriptions create route in the binding
        // 158-route table: a second subscription can only come from changing the
        // existing one, which the lifecycle test already proves.
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        $response = $this->post('/platform/subscriptions', [
            'estate_uuid' => (string) $this->seededEstateRow(self::ESTATE_SLUG)['uuid'],
            'plan_uuid' => (string) $this->planByCode('starter')['uuid'],
        ], true);

        $this->assertTrue(
            in_array($response->status(), [404, 405], true),
            'There must be no subscription-create route (got HTTP ' . $response->status() . ').'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Audit + isolation + CSRF                                           */
    /* ------------------------------------------------------------------ */

    public function testPlatformAuditIsPermissionedAndPiiFree(): void
    {
        $estate = $this->seededEstateRow(self::ESTATE_SLUG);
        $this->assertStatus(302, $this->platformPost('/platform/estates/' . (string) $estate['uuid'] . '/status', [
            'status' => 'suspended',
        ]));

        $audit = $this->latestAudit('platform.estate_status_changed');
        $this->assertNotNull($audit);
        $metadata = (string) $audit['metadata'];
        $this->assertStringNotContainsString('+254', $metadata, 'Platform audit metadata must never contain a phone number.');
        $this->assertStringNotContainsString('@', $metadata, 'Platform audit metadata must never contain an email address.');

        // Super admin + platform_support (platform.audit.view) can open the
        // dashboard/audit surface; the dashboard must not echo raw PII. The
        // operator's own identity in the platform shell is intentionally
        // *masked* (Phone::mask keeps a +2547 prefix), so assert no COMPLETE
        // phone number leaks rather than no masked prefix.
        $page = $this->platformGet('/platform');
        $this->assertStatus(200, $page);
        $this->assertFalse(
            (bool) preg_match('/\+2547\d{6,9}/', $page->body()),
            'The platform dashboard must not expose a complete raw phone number.'
        );
        $this->assertStringContainsString('*', $page->body(), 'The operator identity phone must be masked in the shell.');
        $this->assertTextContains('Platform', $page);
        $this->assertCleanMarkup($page, 'Platform dashboard');

        $this->grantPlatformRole(self::BRIAN, 'platform_support');
        $support = $this->platformGet('/platform', self::BRIAN);
        $this->assertStatus(200, $support, 'platform.audit.view holders must reach the platform overview.');
    }

    public function testPlatformOperatorHasNoEstateRoutesAndEstateUsersNoPlatformWrites(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        foreach (['/admin', '/admin/buildings', '/admin/notices'] as $path) {
            $response = $this->get($path);
            $this->assertTrue(
                in_array($response->status(), [302, 403], true),
                'A platform-only operator must not reach ' . $path . ' (got HTTP ' . $response->status() . ').'
            );
        }

        $estate = $this->seededEstateRow(self::ESTATE_SLUG);
        $this->assertStatus(403, $this->platformPost(
            '/platform/estates/' . (string) $estate['uuid'] . '/status',
            ['status' => 'suspended'],
            self::GRACE
        ));
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM estates WHERE id = :id',
            ['id' => (int) $estate['id']]
        ));
    }

    public function testPlatformMutationsRequireCsrfToken(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        $estate = $this->seededEstateRow(self::ESTATE_SLUG);

        $plan = $this->post('/platform/plans', $this->createPlanPayload(), false);
        $this->assertStatus(403, $plan);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $plan);

        $status = $this->post('/platform/estates/' . (string) $estate['uuid'] . '/status', ['status' => 'suspended'], false);
        $this->assertStatus(403, $status);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $status);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM estates WHERE id = :id',
            ['id' => (int) $estate['id']]
        ));
    }

    /**
     * Fixture helper mirroring PlatformAccessTest (platform assignments are
     * created out of band, not by a portal route).
     */
    private function grantPlatformRole(string $phone, string $roleName): void
    {
        $user = $this->seededUser($phone);
        $roleId = (int) $this->db->scalar(
            "SELECT id FROM roles WHERE scope = 'platform' AND name = :name LIMIT 1",
            ['name' => $roleName]
        );
        $this->assertTrue($roleId > 0, 'Seeded platform role ' . $roleName . ' must exist.');

        $now = \App\Core\Clock::nowDb();
        $this->db->insert('platform_memberships', [
            'uuid' => $this->newUuid(),
            'user_id' => (int) $user['id'],
            'role_id' => $roleId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
