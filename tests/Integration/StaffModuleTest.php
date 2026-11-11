<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Phone;
use App\Core\RateLimiter;
use App\Core\Response;

/**
 * Increment 6 — household staff directory (docs/staff-incidents-contract.md
 * §0–§3, §5).
 *
 * Covers resident CRUD for the caller's own household, staff phone masking,
 * duplicate/archived-household/foreign-household rejection codes, cross-tenant
 * isolation (Greenview ↔ Riverside), the admin estate-wide directory, guard /
 * unprivileged-role denial, soft deactivation (never a hard delete), CSRF on the
 * new POSTs, XSS escaping of staff fields and the exact audit actions.
 *
 * Dedicated fixtures are created through the real admin/resident flows; the
 * seeded demo staff rows are only ever read, never mutated.
 */
final class StaffModuleTest extends AdminModuleTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);

        foreach (['staff:', 'otp:', 'throttle:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures + helpers                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * A distinct, valid E.164 number for each generated fixture. The +25470096
     * range never collides with the seeded demo staff numbers (+25470009xxxx).
     */
    private function generatedPhone(): string
    {
        return '+25470096' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function staffRow(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, estate_id, household_id, full_name, phone, staff_type, notes, status
             FROM household_staff WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            $this->fail('Staff row ' . $uuid . ' was not found.');
        }

        return $row;
    }

    /**
     * One seeded demo staff member for a household (read-only fixture lookup).
     *
     * @return array<string, mixed>
     */
    private function seededStaff(string $unit): array
    {
        $row = $this->db->selectOne(
            "SELECT s.id, s.uuid, s.full_name, s.phone, s.staff_type, s.status, h.unit_number
             FROM household_staff s
             INNER JOIN households h ON h.id = s.household_id AND h.estate_id = s.estate_id
             WHERE h.unit_number = :unit AND s.status = 'active'
             ORDER BY s.id ASC LIMIT 1",
            ['unit' => $unit]
        );

        if ($row === null) {
            $this->fail(
                'Seeded demo staff for household ' . $unit
                . ' was not found (run the Increment-6 seeder).'
            );
        }

        return $row;
    }

    private function countStaff(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM household_staff');
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFrom(array $data, string $key): array
    {
        $candidate = $data[$key] ?? $data;

        if (is_array($candidate) && array_is_list($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
            $candidate = $candidate[0];
        }

        if (!is_array($candidate) || !isset($candidate['uuid'])) {
            $this->fail(
                'Expected the response to expose a ' . $key . ' object with a uuid. '
                . 'Payload: ' . (string) json_encode($data)
            );
        }

        return $candidate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsFrom(array $data, string $key): array
    {
        $candidate = $data[$key] ?? null;

        if (is_array($candidate)) {
            return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row)));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, static fn (mixed $row): bool => is_array($row)));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createStaffApi(array $overrides = [], string $resident = self::JANE): Response
    {
        $this->ensureLoggedInAs($resident);

        return $this->post('/api/v1/resident/staff', array_merge([
            'full_name' => 'QA Staff ' . bin2hex(random_bytes(2)),
            'phone' => $this->generatedPhone(),
            'staff_type' => 'domestic',
            'notes' => 'QA increment-6 staff fixture',
            'household_uuid' => $this->householdUuid('A-001'),
        ], $overrides), true);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createStaff(array $overrides = [], string $resident = self::JANE): array
    {
        $payload = $this->assertSuccess(
            $this->createStaffApi($overrides, $resident),
            'Staff creation failed for ' . $resident . '.'
        );

        return $this->itemFrom($payload['data'], 'staff');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listStaff(string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        return $this->itemsFrom($this->assertSuccess($this->get('/api/v1/resident/staff'))['data'], 'staff');
    }

    /**
     * Resident web forms are PRG or inline-422; accept either and require a
     * human-readable rejection marker (mirrors AdminModuleTestCase).
     *
     * @param array<int, string> $markers
     */
    private function assertWebRejected(Response $response, string $fallbackPath, array $markers): void
    {
        if ($response->status() === 422) {
            foreach ($markers as $marker) {
                if (str_contains($response->body(), $marker)) {
                    return;
                }
            }

            $this->fail('The 422 response must contain one of: ' . implode(', ', $markers));
        }

        if (in_array($response->status(), [302, 303], true)) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);

            foreach ($markers as $marker) {
                if (str_contains($follow->body(), $marker)) {
                    return;
                }
            }

            $this->fail(
                'The redirected page must render one of: ' . implode(', ', $markers)
                . ' (got ' . $location . ').'
            );
        }

        $this->fail('Expected a validation rejection (422 or PRG 302), got HTTP ' . $response->status() . '.');
    }

    private function riversideHouseholdUuid(): string
    {
        $estate = $this->seededEstate('riverside-court');
        $uuid = $this->db->scalar(
            'SELECT uuid FROM households WHERE estate_id = :id ORDER BY id ASC LIMIT 1',
            ['id' => (int) $estate['id']]
        );

        if (!is_string($uuid) || $uuid === '') {
            $this->fail('No Riverside household fixture was found.');
        }

        return $uuid;
    }

    /**
     * Build a dedicated (non-seeded) household with a resident, then archive it.
     * The resident stays an active estate member so they can still sign in and
     * attempt a staff create against their archived household.
     *
     * @return array{household_uuid: string, phone: string}
     */
    private function archivedHouseholdWithResident(): array
    {
        $this->ensureLoggedInAs(self::GRACE);

        $unit = 'QA-STAFF-' . random_int(100, 999);
        $building = $this->seededBuilding('Block A');

        $this->assertAdminRedirect(
            $this->adminPost('/admin/households', [
                'unit_number' => $unit,
                'building_uuid' => (string) $building['uuid'],
                'household_type' => 'apartment',
            ]),
            '/admin/households'
        );

        $householdUuid = (string) $this->db->scalar(
            'SELECT uuid FROM households WHERE estate_id = :estate_id AND unit_number = :unit LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'unit' => $unit]
        );

        if ($householdUuid === '') {
            $this->fail('The dedicated QA household was not created.');
        }

        $phone = $this->generatedPhone();

        $this->assertAdminRedirect(
            $this->adminPost('/admin/residents', [
                'full_name' => 'QA Archived Household Resident',
                'phone' => $phone,
                'role_slug' => 'resident',
                'household_uuid' => $householdUuid,
                'relationship' => 'owner',
            ]),
            '/admin/residents'
        );

        $this->assertAdminRedirect(
            $this->adminPost('/admin/households/' . $householdUuid . '/archive', []),
            '/admin/households'
        );

        $this->assertSame(
            'inactive',
            (string) $this->db->scalar('SELECT status FROM households WHERE uuid = :uuid', ['uuid' => $householdUuid]),
            'The dedicated QA household must be archived for the fixture.'
        );

        $this->reloginAs($phone);

        return ['household_uuid' => $householdUuid, 'phone' => $phone];
    }

    /* ------------------------------------------------------------------ */
    /* Resident directory                                                */
    /* ------------------------------------------------------------------ */

    public function testResidentCreatesListsAndMasksOwnHouseholdStaff(): void
    {
        $phone = $this->generatedPhone();
        $before = $this->countStaff();

        $created = $this->createStaff([
            'full_name' => 'QA Directory Staff',
            'phone' => $phone,
            'staff_type' => 'nanny',
            'notes' => 'QA directory notes',
        ]);

        $this->assertSame($before + 1, $this->countStaff(), 'Creating staff must add exactly one row.');
        $this->assertSame('active', (string) ($created['status'] ?? ''));

        $row = $this->staffRow((string) $created['uuid']);
        $householdId = (int) $this->db->scalar(
            'SELECT id FROM households WHERE uuid = :uuid',
            ['uuid' => $this->householdUuid('A-001')]
        );
        $this->assertSame($householdId, (int) $row['household_id'], 'Staff must belong to the caller\'s household.');
        $this->assertSame('QA Directory Staff', (string) $row['full_name']);
        $this->assertSame('nanny', (string) $row['staff_type']);

        $masked = (string) ($created['phone_masked'] ?? '');
        $this->assertNotEmpty($masked, 'The API must expose a masked staff phone.');
        $this->assertStringContainsString('*', $masked, 'Staff phones must be masked in API responses.');
        $this->assertStringNotContainsString($phone, (string) json_encode($created), 'The raw staff phone must never be returned by the API.');

        $items = $this->listStaff();
        $uuids = array_map(static fn (array $item): string => (string) ($item['uuid'] ?? ''), $items);
        $this->assertContains((string) $created['uuid'], $uuids, 'The new staff member must appear in the household directory.');

        $seeded = $this->seededStaff('A-001');
        $this->assertContains((string) $seeded['uuid'], $uuids, 'A resident must see the seeded staff of their own household.');

        foreach ($items as $item) {
            $this->assertArrayHasKey('phone_masked', $item, 'Every staff list item must expose a masked phone.');
            $this->assertStringNotContainsString(
                (string) $seeded['phone'],
                (string) json_encode($item),
                'Staff list items must never expose raw phone numbers.'
            );
        }

        $page = $this->get('/app/staff');
        $this->assertStatus(200, $page);
        $this->assertTextContains('QA Directory Staff', $page);
        $this->assertBodyContains($masked, $page, 'The web directory must render the masked phone.');
        $this->assertBodyNotContains($phone, $page, 'The web directory must never render the raw staff phone.');
        $this->assertCleanMarkup($page, 'Resident staff directory');
    }

    public function testStaffListIsScopedToTheCallersOwnHousehold(): void
    {
        $janeStaff = $this->createStaff(['full_name' => 'QA Jane Scoped Staff']);

        $janeUuids = array_map(
            static fn (array $item): string => (string) ($item['uuid'] ?? ''),
            $this->listStaff(self::JANE)
        );
        $this->assertContains((string) $janeStaff['uuid'], $janeUuids);

        $brianItems = $this->listStaff(self::BRIAN);
        $brianUuids = array_map(static fn (array $item): string => (string) ($item['uuid'] ?? ''), $brianItems);
        $this->assertNotContains((string) $janeStaff['uuid'], $brianUuids, 'Brian must not see Jane\'s staff.');

        $seededA = $this->seededStaff('A-001');
        $seededB = $this->seededStaff('A-002');
        $this->assertNotContains((string) $seededA['uuid'], $brianUuids, 'A household\'s staff must not leak to another household.');
        $this->assertContains((string) $seededB['uuid'], $brianUuids, 'Brian must see the seeded staff of his own household.');

        $aminaUuids = array_map(
            static fn (array $item): string => (string) ($item['uuid'] ?? ''),
            $this->listStaff(self::AMINA)
        );
        $this->assertNotContains((string) $janeStaff['uuid'], $aminaUuids);
        $this->assertNotContains((string) $seededA['uuid'], $aminaUuids);
        $this->assertNotContains((string) $seededB['uuid'], $aminaUuids);
    }

    public function testDuplicateActiveStaffPhoneInSameHouseholdReturnsStaffExists(): void
    {
        $phone = $this->generatedPhone();
        $this->createStaff(['phone' => $phone, 'full_name' => 'QA Duplicate Original']);

        $before = $this->countStaff();
        $payload = $this->assertErrorCode(
            'STAFF_EXISTS',
            $this->createStaffApi(['phone' => $phone, 'full_name' => 'QA Duplicate Second']),
            422
        );
        $this->assertArrayHasKey(
            'phone',
            (array) ($payload['error']['errors'] ?? []),
            'The duplicate error must identify the phone field.'
        );
        $this->assertSame($before, $this->countStaff(), 'A rejected duplicate must not persist a row.');

        // The web form rejects the duplicate too (422 inline or PRG flash).
        $this->ensureLoggedInAs(self::JANE);
        $web = $this->post('/app/staff', [
            'full_name' => 'QA Duplicate Web',
            'phone' => $phone,
            'staff_type' => 'domestic',
            'household_uuid' => $this->householdUuid('A-001'),
        ], true);
        $this->assertWebRejected($web, '/app/staff', ['STAFF_EXISTS', 'already']);
        $this->assertSame($before, $this->countStaff());
    }

    public function testForeignAndCrossTenantHouseholdsAreNotFound(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $before = $this->countStaff();

        $this->assertErrorCode(
            'HOUSEHOLD_NOT_FOUND',
            $this->createStaffApi(['household_uuid' => $this->householdUuid('A-002')]),
            404
        );

        $riverside = $this->seededEstate('riverside-court');
        $riversideHousehold = (string) $this->db->scalar(
            'SELECT uuid FROM households WHERE estate_id = :id ORDER BY id ASC LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertNotSame('', $riversideHousehold, 'A Riverside household fixture is required.');

        $this->assertErrorCode(
            'HOUSEHOLD_NOT_FOUND',
            $this->createStaffApi(['household_uuid' => $riversideHousehold]),
            404
        );

        $this->assertSame($before, $this->countStaff(), 'Rejected cross-household creates must not persist a row.');
    }

    public function testArchivedHouseholdCannotReceiveStaff(): void
    {
        $fixture = $this->archivedHouseholdWithResident();
        $before = $this->countStaff();

        $response = $this->createStaffApi(['household_uuid' => $fixture['household_uuid']], $fixture['phone']);

        $this->assertErrorCode('HOUSEHOLD_INACTIVE', $response, 422);
        $this->assertSame($before, $this->countStaff(), 'An archived household must not receive staff rows.');
    }

    /* ------------------------------------------------------------------ */
    /* Status lifecycle + audit                                           */
    /* ------------------------------------------------------------------ */

    public function testDeactivateAndReactivateAreSoftStatusChanges(): void
    {
        $phone = $this->generatedPhone();
        $created = $this->createStaff(['full_name' => 'QA Status Staff', 'phone' => $phone]);
        $uuid = (string) $created['uuid'];
        $before = $this->countStaff();

        $this->ensureLoggedInAs(self::JANE);

        $deactivate = $this->post('/app/staff/' . $uuid . '/deactivate', [], true);
        $this->assertRedirect($deactivate, '/app/staff');
        $this->assertSame('inactive', (string) $this->staffRow($uuid)['status']);
        $this->assertSame($before, $this->countStaff(), 'Deactivation must never hard-delete the row.');

        $page = $this->get('/app/staff');
        $this->assertStatus(200, $page);
        $this->assertTextContains('QA Status Staff', $page, 'Inactive staff must stay visible in the directory.');

        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'staff.deactivated' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Deactivation must be audited as staff.deactivated.');

        $metadata = (string) $audit['metadata'];
        $this->assertStringNotContainsString($phone, $metadata, 'Audit metadata must never contain the staff phone.');
        $this->assertStringNotContainsString('QA Status Staff', $metadata, 'Audit metadata must never contain the staff name.');

        $reactivate = $this->post('/app/staff/' . $uuid . '/reactivate', [], true);
        $this->assertRedirect($reactivate, '/app/staff');
        $this->assertSame('active', (string) $this->staffRow($uuid)['status']);
        $this->assertSame($before, $this->countStaff(), 'Reactivation must never create or delete rows.');

        $reactivatedAudits = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action IN ('staff.updated', 'staff.reactivated')"
        );
        $this->assertTrue($reactivatedAudits > 0, 'Reactivation must be audited (staff.updated/staff.reactivated).');
    }

    public function testStaffCreationIsAuditedWithoutPii(): void
    {
        $phone = $this->generatedPhone();
        $created = $this->createStaff(['full_name' => 'QA Audited Staff', 'phone' => $phone]);

        $audit = $this->db->selectOne(
            "SELECT entity_type, metadata FROM audit_logs WHERE action = 'staff.created' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Staff creation must be audited as staff.created.');

        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $created['uuid'], $metadata, 'Audit metadata must reference the staff uuid.');
        $this->assertStringNotContainsString($phone, $metadata, 'Audit metadata must never contain the staff phone.');
        $this->assertStringNotContainsString('QA Audited Staff', $metadata, 'Audit metadata must never contain the staff name.');
    }

    /* ------------------------------------------------------------------ */
    /* Security                                                           */
    /* ------------------------------------------------------------------ */

    public function testStaffPostsRequireCsrf(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $before = $this->countStaff();

        $body = [
            'full_name' => 'QA CSRF Staff',
            'phone' => $this->generatedPhone(),
            'staff_type' => 'domestic',
            'household_uuid' => $this->householdUuid('A-001'),
        ];

        $web = $this->post('/app/staff', $body, false);
        $this->assertStatus(403, $web);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $web);

        $api = $this->post('/api/v1/resident/staff', $body, false);
        $this->assertErrorCode('CSRF_TOKEN_INVALID', $api, 403);

        $this->assertSame($before, $this->countStaff(), 'A CSRF-rejected POST must not persist a row.');
    }

    public function testStaffNameAndNotesAreEscaped(): void
    {
        $namePayload = 'QA <script>alert("staff")</script>';
        $notesPayload = 'QA notes <img src=x onerror="alert(1)">';

        $created = $this->createStaff(['full_name' => $namePayload, 'notes' => $notesPayload]);

        // Stored verbatim (escaping is a render concern), never mutated.
        $this->assertSame($namePayload, (string) $this->staffRow((string) $created['uuid'])['full_name']);

        $resident = $this->get('/app/staff');
        $this->assertStatus(200, $resident);
        $this->assertBodyNotContains($namePayload, $resident, 'Staff names must be escaped on the resident directory.');
        $this->assertBodyContains('&lt;script&gt;', $resident, 'The escaped staff name must still be visible as text.');
        $this->assertBodyNotContains($notesPayload, $resident, 'Staff notes must be escaped wherever they render.');
        $this->assertCleanMarkup($resident, 'Resident staff directory (XSS)');

        $admin = $this->adminGet('/admin/staff');
        $this->assertStatus(200, $admin);
        $this->assertBodyNotContains($namePayload, $admin, 'Staff names must be escaped in the admin directory.');
        $this->assertBodyContains('&lt;script&gt;', $admin);
        $this->assertBodyNotContains($notesPayload, $admin, 'Staff notes must be escaped in the admin directory.');
        $this->assertCleanMarkup($admin, 'Admin staff directory (XSS)');
    }

    public function testGuardAndUnprivilegedRolesCannotAccessStaff(): void
    {
        $this->loginGuard();

        $this->assertStatus(403, $this->get('/app/staff'), 'A guard must not reach the resident staff directory.');
        $this->assertStatus(403, $this->get('/api/v1/resident/staff'), 'A guard must not call the resident staff API.');

        $before = $this->countStaff();
        $guardCreate = $this->post('/api/v1/resident/staff', [
            'full_name' => 'QA Guard Staff',
            'phone' => $this->generatedPhone(),
            'staff_type' => 'domestic',
        ], true);
        $this->assertStatus(403, $guardCreate, 'A guard must not be able to create staff.');
        $this->assertSame($before, $this->countStaff());

        $this->reloginAs(self::PARKING_MANAGER);
        $this->assertStatus(403, $this->get('/app/staff'), 'A parking manager holds no staff permission.');
        $this->assertStatus(403, $this->get('/admin/staff'), 'The admin staff directory requires the admin area.');

        $this->reloginAs(self::JANE);
        $this->assertStatus(403, $this->get('/admin/staff'), 'A resident must not reach the estate-wide staff directory.');
    }

    /* ------------------------------------------------------------------ */
    /* Admin directory + cross-tenant isolation                          */
    /* ------------------------------------------------------------------ */

    public function testAdminStaffDirectoryIsEstateWideWithMaskedPhones(): void
    {
        $this->assertSame(
            3,
            (int) $this->db->scalar('SELECT COUNT(*) FROM household_staff'),
            'The Increment-6 demo seed must install exactly three demo staff rows.'
        );

        $page = $this->adminGet('/admin/staff');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'Admin staff directory');

        foreach (['A-001', 'A-002', 'B-001'] as $unit) {
            $row = $this->seededStaff($unit);

            $this->assertTextContains((string) $row['full_name'], $page, 'The estate-wide directory must list the ' . $unit . ' staff member.');
            $this->assertTextContains($unit, $page, 'The directory must label the staff member household.');
            $this->assertBodyContains(Phone::mask((string) $row['phone']), $page, 'Staff phones must be masked on the admin directory.');
            $this->assertBodyNotContains((string) $row['phone'], $page, 'Raw staff phones must never render on the admin directory.');
        }
    }

    public function testRiversideAdminCannotSeeOrTouchGreenviewStaff(): void
    {
        $greenviewStaff = $this->seededStaff('A-001');

        $page = $this->adminGet('/admin/staff', self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $page);
        $this->assertBodyNotContains((string) $greenviewStaff['full_name'], $page, 'A Riverside admin must never see Greenview staff.');
        $this->assertBodyNotContains((string) $greenviewStaff['phone'], $page, 'A Riverside admin must never see Greenview staff phones.');

        // A dedicated Riverside resident may not create staff in a Greenview household.
        $riversidePhone = $this->generatedPhone();
        $this->assertAdminRedirect(
            $this->adminPost('/admin/residents', [
                'full_name' => 'QA Riverside Resident',
                'phone' => $riversidePhone,
                'role_slug' => 'resident',
                'household_uuid' => $this->riversideHouseholdUuid(),
                'relationship' => 'owner',
            ], self::DAVID_RIVERSIDE),
            '/admin/residents'
        );

        $this->reloginAs($riversidePhone);
        $this->assertSame([], $this->listStaff($riversidePhone), 'A fresh Riverside resident must not see Greenview staff.');

        $before = $this->countStaff();
        $response = $this->post('/api/v1/resident/staff', [
            'full_name' => 'QA Cross Tenant Staff',
            'phone' => $this->generatedPhone(),
            'staff_type' => 'domestic',
            'household_uuid' => $this->householdUuid('A-001'),
        ], true);

        $this->assertErrorCode('HOUSEHOLD_NOT_FOUND', $response, 404);
        $this->assertSame($before, $this->countStaff(), 'A cross-tenant staff create must not persist a row.');
    }

    /* ------------------------------------------------------------------ */
    /* Validation + web form                                              */
    /* ------------------------------------------------------------------ */

    public function testStaffPhoneIsNormalizedStoredAndMasked(): void
    {
        $created = $this->createStaff(['phone' => '0712 345 678', 'full_name' => 'QA Normalized Staff']);

        $row = $this->staffRow((string) $created['uuid']);
        $this->assertSame('+254712345678', (string) $row['phone'], 'Local phone formats must be normalized to E.164.');
        $this->assertStringContainsString('***', (string) ($created['phone_masked'] ?? ''), 'The normalized phone must be returned masked.');

        $before = $this->countStaff();
        $payload = $this->assertErrorCode(
            'VALIDATION_FAILED',
            $this->createStaffApi(['phone' => '12345', 'full_name' => 'QA Bad Phone']),
            422
        );
        $this->assertArrayHasKey('phone', (array) ($payload['error']['errors'] ?? []));
        $this->assertSame($before, $this->countStaff());
    }

    public function testResidentWebStaffFormCreatesADirectoryEntry(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $page = $this->get('/app/staff');
        $this->assertStatus(200, $page);

        $forms = $this->xpath('//form[@action="/app/staff"]', $this->dom($page));
        $this->assertTrue($forms->length >= 1, 'The staff screen must offer an add-staff form.');

        $form = $forms->item(0);
        $this->assertNotNull($form);

        foreach (['full_name', 'phone', 'staff_type'] as $field) {
            $this->assertCount(
                1,
                $this->xpath('.//*[@name="' . $field . '"]', $form),
                'The add-staff form must expose a ' . $field . ' field.'
            );
        }

        $phone = $this->generatedPhone();
        $response = $this->post('/app/staff', [
            'full_name' => 'QA Web Staff',
            'phone' => $phone,
            'staff_type' => 'gardener',
            'notes' => 'QA web form entry',
        ], true);

        $this->assertRedirect($response, '/app/staff');

        $row = $this->db->selectOne(
            'SELECT uuid, status, staff_type, household_id FROM household_staff WHERE phone = :phone LIMIT 1',
            ['phone' => $phone]
        );
        $this->assertNotNull($row, 'The web form must persist the staff member.');

        $this->assertSame('active', (string) $row['status']);
        $this->assertSame('gardener', (string) $row['staff_type']);
        $this->assertSame(
            (int) $this->db->scalar('SELECT id FROM households WHERE uuid = :uuid', ['uuid' => $this->householdUuid('A-001')]),
            (int) $row['household_id'],
            'The web form must create staff for the caller\'s household.'
        );

        $this->assertTextContains('QA Web Staff', $this->get('/app/staff'));
    }
}
