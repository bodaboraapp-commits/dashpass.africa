<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;

/**
 * Inc-13 permissions/ownership pre-authored tests (fold when the route slice lands).
 *
 * Frozen gates (docs/automated-billing-contract.md §6/§8):
 *  - `GET /admin/utility-bills`: admin area, estate-scoped (Grace/Peter 200 own estate;
 *    David sees only Riverside; residents/guards 403; unauth 302).
 *  - `GET /app/bills`, `GET /app/bills/{uuid}`, POST pay-link: auth + active household
 *    membership ownership; no permission node; guard none; foreign uuid 404; CSRF.
 *  - `GET /pay/{token}` public (no session); tampered/invalid token rejected safely.
 *  - webhooks: public + CSRF-exempt (signature auth) — no-CSRF POST must never be a
 *    CSRF 403; bad signature rejected without writing a payment row.
 */
final class Inc13PermissionsTest extends AdminModuleTestCase
{
    private const ADMIN_UTILITY = '/admin/utility-bills';

    /** @return array<string, mixed> */
    private function seededBillForJane(): array
    {
        $row = $this->db->selectOne(
            "SELECT uuid FROM billing_invoices WHERE uuid = '7b000001-0000-4000-8000-000000000001'"
        );
        $this->assertNotNull($row, 'The seeded utility invoice must exist.');

        return $row;
    }

    // ---------------------------------------------------------------------
    // Estate utility overview (admin/guard/resident boundaries)
    // ---------------------------------------------------------------------

    public function testAdminUtilityOverviewIsEstateAdminOnly(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $page = $this->get(self::ADMIN_UTILITY);
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'admin utility overview');
        $this->assertCount(1, $this->xpath('//h1', $this->dom($page)));

        // A resident and a guard must never reach the admin area.
        foreach ([self::JANE, self::GUARD_MAIN] as $phone) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(403, $this->get(self::ADMIN_UTILITY), $phone . ' must get 403 on the utility overview.');
        }

        $this->resetSession();
        $this->assertStatus(302, $this->get(self::ADMIN_UTILITY));
    }

    public function testAdminUtilityOverviewIsEstateScoped(): void
    {
        $greenview = $this->seededEstate(self::ESTATE_SLUG);

        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $page = $this->get(self::ADMIN_UTILITY);
        $this->assertStatus(200, $page);
        $this->assertBodyNotContains('A-001', $page, 'A Riverside admin must not see Greenview utility charges.');
        $this->assertBodyNotContains('Water bill', $page, 'Riverside has no utility demo rows.');
        $this->assertNotSame(0, (int) $greenview['id']);
    }

    // ---------------------------------------------------------------------
    // Resident My Bills ownership
    // ---------------------------------------------------------------------

    public function testResidentBillsListIsOwnershipScoped(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $page = $this->get('/app/bills');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'resident bills list');
        $this->assertCount(1, $this->xpath('//h1', $this->dom($page)));
        $this->assertBodyContains('Water bill', $page, 'Jane (A-001) must see the seeded A-001 utility bill.');

        // Brian is A-002: he must not see Jane's bill.
        $this->ensureLoggedInAs(self::BRIAN);
        $other = $this->get('/app/bills');
        $this->assertStatus(200, $other);
        $this->assertBodyNotContains('A-001', $other, 'A-002 must never see A-001 bills.');

        // Guard: no resident bills.
        $this->loginGuard();
        $this->assertStatus(403, $this->get('/app/bills'));

        $this->resetSession();
        $this->assertStatus(302, $this->get('/app/bills'));
    }

    public function testResidentBillDetailRejectsForeignOrUnknownBill(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $own = $this->seededBillForJane();

        $detail = $this->get('/app/bills/' . (string) $own['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertCleanMarkup($detail, 'resident bill detail');
        $this->assertStringNotContainsString($this->csrfToken(), $detail->body(), 'The CSRF token must not leak into bill body text.');

        $this->assertStatus(404, $this->get('/app/bills/' . Uuid::v4()), 'Unknown bill uuid must be 404.');

        // A different household must not read Jane's bill.
        $this->ensureLoggedInAs(self::BRIAN);
        $this->assertStatus(404, $this->get('/app/bills/' . (string) $own['uuid']), 'Foreign bill must be 404 (no enumeration).');
    }

    public function testResidentPayLinkRequiresCsrfAndOwnership(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $own = $this->seededBillForJane();

        $noCsrf = $this->post('/app/bills/' . (string) $own['uuid'] . '/pay-link', [], false);
        $this->assertStatus(403, $noCsrf, 'Pay-link creation must be CSRF-protected.');

        $this->ensureLoggedInAs(self::BRIAN);
        $foreign = $this->post('/app/bills/' . (string) $own['uuid'] . '/pay-link', [], true);
        $this->assertStatus(404, $foreign, 'A resident must not create a pay link for a foreign bill.');

        $this->resetSession();
        $unauth = $this->post('/app/bills/' . (string) $own['uuid'] . '/pay-link', [], false);
        $this->assertStatus(302, $unauth);
    }

    // ---------------------------------------------------------------------
    // Public pay page + webhook CSRF exemption
    // ---------------------------------------------------------------------

    public function testPublicPayPageRejectsTamperedTokensSafely(): void
    {
        $this->resetSession();

        foreach (['not-a-token', 'abc.def.ghi', str_repeat('a', 80)] as $token) {
            $response = $this->get('/pay/' . $token);
            $this->assertTrue(
                in_array($response->status(), [403, 404, 410], true),
                'A tampered pay token must be rejected safely, got HTTP ' . $response->status() . '.'
            );
            $this->assertStringNotContainsString('Traceback', $response->body());
            $this->assertStringNotContainsString('Exception', $response->body());
        }
    }

    public function testWebhookRoutesAreCsrfExemptButSignatureProtected(): void
    {
        $this->resetSession();

        $paymentsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        foreach (['/webhooks/payments/mpesa', '/webhooks/payments/card'] as $path) {
            $response = $this->request('POST', $path, [], ['Content-Type' => 'application/json'], false);

            // CSRF-exempt: never the web-form 403. The provider signature is the auth,
            // so a bad/absent signature must be a 4xx from the webhook controller.
            $this->assertTrue(
                in_array($response->status(), [400, 401, 403, 404, 422], true),
                $path . ' must be signature-protected (4xx) — got HTTP ' . $response->status() . '.'
            );

            if ($response->status() === 403) {
                $this->assertStringNotContainsString('CSRF', $response->body(), $path . ' must not be rejected by the CSRF middleware.');
            }
        }

        $this->assertSame($paymentsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'), 'A rejected webhook must never create a payment row.');
    }

    // ---------------------------------------------------------------------
    // Platform automation + pay-link creation (platform gates)
    // ---------------------------------------------------------------------

    public function testPlatformAutomationIsPlatformOnly(): void
    {
        $this->ensureLoggedInAs('+254700000020');
        $this->assertStatus(200, $this->get('/platform/billing/automation'));

        foreach ([self::GRACE, self::MANAGER, self::JANE, self::GUARD_MAIN] as $phone) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(403, $this->get('/platform/billing/automation'), $phone . ' must not reach platform automation.');
        }

        $this->resetSession();
        $this->assertStatus(302, $this->get('/platform/billing/automation'));
    }
}
