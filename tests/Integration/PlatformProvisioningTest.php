<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Request;

/**
 * Platform estate provisioning transaction (docs/platform-admin-contract.md §2, §4).
 *
 * PRE-WRITTEN by qa-mail (T66) for the Increment-9 gate; parked in
 * storage/temporary/pending-tests/ until T64/T65 land the controllers + views.
 *
 * The contract does not freeze the form field names, so the helper sends the
 * canonical names from §2 plus the obvious aliases; extra fields are ignored by
 * the Validator (it returns only ruled fields). If T64 settles different names,
 * update only `provisionPayload()` at the gate.
 */
final class PlatformProvisioningTest extends AdminModuleTestCase
{
    protected const PLATFORM_ADMIN = '+254700000020';

    private const NEW_PHONE_PREFIX = '+2547990100';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $body
     */
    private function platformPost(string $path, array $body = [], string $phone = self::PLATFORM_ADMIN): \App\Core\Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->post($path, $body, true);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function provisionPayload(array $overrides = []): array
    {
        $accountName = (string) ($overrides['account_name'] ?? 'QA Holdings Ltd');
        $estateName = (string) ($overrides['estate_name'] ?? 'QA Court ' . bin2hex(random_bytes(3)));
        $adminName = (string) ($overrides['admin_full_name'] ?? 'QA Estate Admin');
        $adminPhone = (string) ($overrides['admin_phone'] ?? self::NEW_PHONE_PREFIX . random_int(10, 99));
        $growth = $this->planByCode('growth');

        $payload = [
            // Account: create-new by name or select by uuid/id.
            'account_mode' => $overrides['account_mode'] ?? 'new',
            'account_name' => $accountName,
            'account_uuid' => $overrides['account_uuid'] ?? '',
            'account_id' => $overrides['account_id'] ?? '',
            // Estate.
            'estate_name' => $estateName,
            'name' => $estateName,
            'timezone' => $overrides['timezone'] ?? 'Africa/Nairobi',
            'estate_type' => $overrides['estate_type'] ?? 'mixed',
            // Estate admin user.
            'admin_full_name' => $adminName,
            'admin_name' => $adminName,
            'admin_phone' => $adminPhone,
            // Optional plan.
            'plan_uuid' => $overrides['plan_uuid'] ?? (string) $growth['uuid'],
            'plan_code' => $overrides['plan_code'] ?? 'growth',
            // Initial settings.
            'visitor_validity_minutes' => $overrides['visitor_validity_minutes'] ?? 240,
            'visitor_parking_grace_minutes' => $overrides['visitor_parking_grace_minutes'] ?? 30,
        ];

        return array_merge($payload, $overrides);
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
     * @return array<string, mixed>|null
     */
    private function estateByName(string $name): ?array
    {
        return $this->db->selectOne('SELECT * FROM estates WHERE name = :name LIMIT 1', ['name' => $name]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userByPhone(string $phone): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE phone = :phone LIMIT 1', ['phone' => $phone]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function membershipsForUser(int $userId): array
    {
        return $this->db->select(
            'SELECT m.*, r.name AS role_name FROM estate_memberships m
             INNER JOIN roles r ON r.id = m.role_id
             WHERE m.user_id = :uid ORDER BY m.id ASC',
            ['uid' => $userId]
        );
    }

    /**
     * @param array<string, mixed> $estate
     * @return array<int, array<string, mixed>>
     */
    private function subscriptionsForEstate(array $estate): array
    {
        return $this->db->select(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id ASC',
            ['eid' => (int) $estate['id']]
        );
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $tables = ['saas_accounts', 'estates', 'users', 'estate_settings', 'estate_memberships', 'subscriptions', 'subscription_plans'];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $table);
        }

        return $counts;
    }

    /**
     * Platform forms are web PRG; validation may be 422 or a 302 flash error.
     *
     * @param array<int, string> $markers
     */
    private function assertProvisionRejected(\App\Core\Response $response, array $markers): void
    {
        if ($response->status() === 422) {
            foreach ($markers as $marker) {
                if (str_contains($response->body(), $marker)) {
                    return;
                }
            }

            $this->fail('The 422 provisioning response must contain one of: ' . implode(', ', $markers));
        }

        if ($response->status() === 302) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : '/platform/estates/new');

            foreach ($markers as $marker) {
                if (str_contains($follow->body(), $marker)) {
                    return;
                }
            }

            $this->fail('The redirected provisioning form must render one of: ' . implode(', ', $markers) . ' (got ' . $location . ').');
        }

        $this->fail('Expected a provisioning rejection (422 or PRG 302), got HTTP ' . $response->status() . '.');
    }

    /* ------------------------------------------------------------------ */
    /* Success path                                                       */
    /* ------------------------------------------------------------------ */

    public function testProvisionNewAccountEstateAdminAndSubscription(): void
    {
        $before = $this->rowCounts();
        $estateName = 'QA Court ' . bin2hex(random_bytes(3));
        $phone = self::NEW_PHONE_PREFIX . random_int(10, 99);

        $response = $this->platformPost('/platform/estates', $this->provisionPayload([
            'estate_name' => $estateName,
            'admin_full_name' => 'New Estate Admin',
            'admin_phone' => $phone,
            'estate_type' => 'apartment',
            'timezone' => 'Africa/Nairobi',
        ]));
        $this->assertStatus(302, $response, 'A successful provisioning POST must redirect (PRG).');
        $this->assertContains('/platform/estates/', (string) $response->header('Location'));

        // The detail page (redirect target) must mask the new admin phone.
        $detail = $this->get((string) $response->header('Location'));
        $this->assertStatus(200, $detail, 'The estate detail page must render after provisioning.');
        $this->assertStringNotContainsString($phone, $detail->body(), 'The estate detail must mask the admin phone.');
        $this->assertStringContainsString('*', $detail->body(), 'A masked admin phone indicator is expected on the estate detail.');

        $estate = $this->estateByName($estateName);
        $this->assertNotNull($estate, 'The estate must be created.');
        $this->assertSame('active', (string) $estate['status']);
        $this->assertSame('apartment', (string) $estate['estate_type']);
        $this->assertSame('Africa/Nairobi', (string) $estate['timezone']);

        // New account + default settings.
        $account = $this->db->selectOne('SELECT * FROM saas_accounts WHERE id = :id', ['id' => (int) $estate['account_id']]);
        $this->assertNotNull($account, 'The estate must belong to a SaaS account.');
        $this->assertSame('QA Holdings Ltd', (string) $account['name']);

        $settings = $this->db->select(
            'SELECT setting_key, setting_value FROM estate_settings WHERE estate_id = :eid',
            ['eid' => (int) $estate['id']]
        );
        $byKey = [];

        foreach ($settings as $row) {
            $byKey[(string) $row['setting_key']] = json_decode((string) $row['setting_value'], true);
        }

        $this->assertSame(240, (int) ($byKey['visitor_validity_minutes'] ?? -1), 'Default visitor validity must be 240 minutes.');
        $this->assertSame(30, (int) ($byKey['visitor_parking_grace_minutes'] ?? -1), 'Default parking grace must be 30 minutes.');
        $this->assertSame(30, (int) ($byKey['parking_overstay_grace_minutes'] ?? -1), 'Default parking overstay grace must be 30 minutes.');
        $this->assertCount(3, $settings, 'Provisioning must write exactly the three frozen default settings.');

        // Admin user + estate_admin membership.
        $admin = $this->userByPhone($phone);
        $this->assertNotNull($admin, 'The estate admin user must be created.');
        $this->assertSame('active', (string) $admin['status']);
        $memberships = $this->membershipsForUser((int) $admin['id']);
        $this->assertCount(1, $memberships);
        $this->assertSame('estate_admin', (string) $memberships[0]['role_name']);
        $this->assertSame('active', (string) $memberships[0]['status']);
        $this->assertSame((int) $estate['id'], (int) $memberships[0]['estate_id']);

        // Optional subscription: exactly one, on the selected plan.
        $subscriptions = $this->subscriptionsForEstate($estate);
        $this->assertCount(1, $subscriptions, 'Provisioning with a plan must create exactly one subscription.');
        $this->assertSame((int) $this->planByCode('growth')['id'], (int) $subscriptions[0]['plan_id']);
        $this->assertTrue(
            in_array((string) $subscriptions[0]['status'], ['trialing', 'active'], true),
            'A new subscription must be trialing or active.'
        );

        // Audit: platform.estate_created, uuid/status/codes only — never PII.
        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'platform.estate_created' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Provisioning must audit platform.estate_created.');
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $estate['uuid'], $metadata);
        $this->assertStringNotContainsString($phone, $metadata, 'Audit metadata must never contain the admin phone.');
        $this->assertStringNotContainsString('New Estate Admin', $metadata, 'Audit metadata must never contain a person name.');

        $after = $this->rowCounts();
        $this->assertSame($before['estates'] + 1, $after['estates']);
        $this->assertSame($before['saas_accounts'] + 1, $after['saas_accounts']);
        $this->assertSame($before['users'] + 1, $after['users']);
        $this->assertSame($before['estate_settings'] + 3, $after['estate_settings'], 'Three default settings rows are expected (T64 frozen keys).');
        $this->assertSame($before['subscriptions'] + 1, $after['subscriptions']);
    }

    public function testProvisionIntoExistingAccountAndLinkExistingUser(): void
    {
        $account = $this->db->selectOne('SELECT * FROM saas_accounts ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($account, 'The seeded SaaS account is required.');
        $beforeUsers = (int) $this->db->scalar('SELECT COUNT(*) FROM users');
        $estateName = 'QA Linked Court ' . bin2hex(random_bytes(3));

        $response = $this->platformPost('/platform/estates', $this->provisionPayload([
            'account_mode' => 'existing',
            'account_name' => (string) $account['name'],
            'account_uuid' => (string) $account['uuid'],
            'account_id' => (int) $account['id'],
            'estate_name' => $estateName,
            'admin_phone' => self::AMINA, // existing global user → linked, not duplicated
            'admin_full_name' => 'Amina Hassan',
        ]));
        $this->assertStatus(302, $response);

        $estate = $this->estateByName($estateName);
        $this->assertNotNull($estate);
        $this->assertSame((int) $account['id'], (int) $estate['account_id'], 'An existing account must be reused.');

        $this->assertSame($beforeUsers, (int) $this->db->scalar('SELECT COUNT(*) FROM users'), 'An existing phone must be linked, not duplicated.');

        $admin = $this->userByPhone(self::AMINA);
        $this->assertNotNull($admin);
        $roles = array_map(
            static fn (array $row): string => (string) $row['role_name'],
            $this->membershipsForUser((int) $admin['id'])
        );
        $this->assertTrue(in_array('estate_admin', $roles, true), 'The linked user must gain an estate_admin membership.');
    }

    public function testNewAdminCanLogInAndReachTheirEmptyEstatePortal(): void
    {
        $estateName = 'QA Login Court ' . bin2hex(random_bytes(3));
        $phone = self::NEW_PHONE_PREFIX . random_int(10, 99);

        $this->assertStatus(302, $this->platformPost('/platform/estates', $this->provisionPayload([
            'estate_name' => $estateName,
            'admin_phone' => $phone,
            'admin_full_name' => 'Fresh Estate Admin',
        ])));

        $this->reloginAs($phone);

        $dashboard = $this->get('/admin');
        $this->assertStatus(200, $dashboard, 'The provisioned estate admin must reach their admin portal.');

        $buildings = $this->get('/admin/buildings');
        $this->assertStatus(200, $buildings);
        $this->assertTextContains('Building', $buildings);
    }

    public function testProvisionedAdminPhoneIsMaskedInPlatformLists(): void
    {
        $phone = self::NEW_PHONE_PREFIX . random_int(10, 99);
        $this->assertStatus(302, $this->platformPost('/platform/estates', $this->provisionPayload([
            'admin_phone' => $phone,
            'admin_full_name' => 'Masked Admin',
        ])));

        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        $response = $this->get('/platform/estates');
        $this->assertStatus(200, $response);
        $this->assertStringNotContainsString($phone, $response->body(), 'Platform lists must mask the estate admin phone.');
        $this->assertStringContainsString('**', $response->body(), 'A masked phone indicator is expected.');
    }

    /* ------------------------------------------------------------------ */
    /* Rejections + rollback                                              */
    /* ------------------------------------------------------------------ */

    public function testDuplicateEstateNameIsRejectedWithoutPartialState(): void
    {
        $before = $this->rowCounts();

        $response = $this->platformPost('/platform/estates', $this->provisionPayload([
            'estate_name' => 'Greenview Residences', // slug already exists
            'account_name' => 'Collision Holdings ' . bin2hex(random_bytes(3)),
        ]));

        $this->assertProvisionRejected($response, ['ESTATE_EXISTS', 'already exists', 'slug']);

        $this->assertSame($before, $this->rowCounts(), 'A rejected provisioning must leave no partial rows (account/estate/user/settings/membership/subscription).');
    }

    public function testInvalidAdminPhoneIsRejectedWithoutPartialState(): void
    {
        $before = $this->rowCounts();

        $response = $this->platformPost('/platform/estates', $this->provisionPayload([
            'admin_phone' => '12345',
            'account_name' => 'Bad Phone Holdings ' . bin2hex(random_bytes(3)),
        ]));

        $this->assertProvisionRejected($response, ['phone', 'valid', 'required']);

        $this->assertSame($before, $this->rowCounts(), 'An invalid admin phone must not create account/estate/settings rows.');
    }

    public function testUnknownPlanIsRejectedWithoutPartialState(): void
    {
        $before = $this->rowCounts();

        $response = $this->platformPost('/platform/estates', $this->provisionPayload([
            'plan_uuid' => $this->newUuid(),
            'plan_code' => 'does_not_exist',
            'account_name' => 'Bad Plan Holdings ' . bin2hex(random_bytes(3)),
        ]));

        $this->assertProvisionRejected($response, ['plan', 'not allowed', 'not found', 'valid']);

        $this->assertSame($before, $this->rowCounts(), 'An unknown plan must not commit a partial estate.');
    }

    /**
     * T64 exposes `provision(array $input, ?callable $afterEstateCreated)` as a
     * dedicated rollback seam; throwing right after the estate row exists proves
     * the whole transaction (account + estate + settings + user + membership +
     * subscription) rolls back with zero partial rows.
     */
    public function testInjectedFailureAfterEstateCreationRollsBackEveryRow(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        $context = new Context(
            new Request('GET', '/platform', [], [], [], null, '127.0.0.1', 'MtaaPass-Test/1.0'),
            'qa-platform-' . bin2hex(random_bytes(4))
        );
        $service = \App\Modules\Platform\PlatformEstateService::make($context);

        $before = $this->rowCounts();
        $estateName = 'Rollback Court ' . bin2hex(random_bytes(3));
        $phone = self::NEW_PHONE_PREFIX . random_int(10, 99);

        $thrown = false;

        try {
            $service->provision(
                $this->provisionPayload([
                    'estate_name' => $estateName,
                    'admin_full_name' => 'Rollback Admin',
                    'admin_phone' => $phone,
                ]),
                static function (array $created): void {
                    throw new \RuntimeException('forced rollback after estate create');
                }
            );
        } catch (\RuntimeException $exception) {
            $thrown = true;
            $this->assertStringContainsString('forced rollback', $exception->getMessage());
        }

        $this->assertTrue($thrown, 'The injected failure must propagate out of the transaction.');
        $this->assertSame(
            $before,
            $this->rowCounts(),
            'A mid-transaction failure must leave zero partial rows (account/estate/settings/user/membership/subscription).'
        );
        $this->assertNull($this->estateByName($estateName), 'The estate row must be rolled back.');
        $this->assertNull($this->userByPhone($phone), 'A newly created admin user must be rolled back.');
    }
}
