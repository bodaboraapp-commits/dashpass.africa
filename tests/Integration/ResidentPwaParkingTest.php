<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use Database\Seeds\DemoParkingSeeder;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Resident Increment-3 screens (vehicles-parking-contract §4): /app/vehicles,
 * /app/parking, the invite vehicle+parking controls and the pass parking card.
 * Frozen view-data keys are asserted through rendered content; no preview rows.
 */
final class ResidentPwaParkingTest extends ParkingModuleTestCase
{
    public function testVehiclesScreenListsRealHouseholdVehicles(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app/vehicles');
        $this->assertStatus(200, $response);

        $this->assertCleanMarkup($response, 'Vehicles');

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s, 'Vehicles must render exactly one h1.');
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Vehicles', $this->text($h1));

        $this->assertTextContains(self::PLATE_JANE, $response, 'Jane\'s real plate must be rendered.');
        $this->assertTextNotContains(self::PLATE_BRIAN, $response, 'Another household\'s plate must never render.');

        // Real attributes from the seeded fixture (white car).
        $this->assertTextContains('Car', $response);
        $this->assertTextContains('White', $response);
        $this->assertTextContains('Active', $response);

        // Add-vehicle form (plain POST, works without JavaScript).
        $forms = $this->xpath('//form[@action="/app/vehicles"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The vehicles screen must expose the add-vehicle form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="plate_number"]', $form));
        $this->assertCount(1, $this->xpath('.//*[@name="vehicle_type"]', $form));
        $this->assertCount(1, $this->xpath('.//*[@name="colour"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@name="_token"]', $form), 'Forms must carry the CSRF token.');
    }

    public function testVehiclesScreenHonestEmptyState(): void
    {
        // Remove Amina's seeded allocation + vehicle so her household has none.
        $vehicle = $this->seededVehicle(self::PLATE_AMINA);
        $this->db->execute('DELETE FROM parking_allocations WHERE vehicle_id = :id', ['id' => (int) $vehicle['id']]);
        $this->db->execute('DELETE FROM vehicles WHERE id = :id', ['id' => (int) $vehicle['id']]);

        $this->ensureLoggedInAs(self::AMINA);
        $response = $this->get('/app/vehicles');
        $this->assertStatus(200, $response);
        $this->assertTextNotContains(self::PLATE_AMINA, $response);

        $lower = strtolower($this->pageText($response));
        $this->assertTrue(
            str_contains($lower, 'no vehicle') || str_contains($lower, 'add your first'),
            'An empty vehicle list must render an honest empty state.'
        );
        $this->assertTextNotContains('Layout preview', $response);
    }

    public function testParkingScreenShowsAllocationAndSeededReservation(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app/parking');
        $this->assertStatus(200, $response);

        $this->assertCleanMarkup($response, 'My parking');

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s);
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertContains('parking', strtolower($this->text($h1)));

        // Core allocation: R-01 in Resident Zone A with Jane's vehicle.
        $this->assertTextContains(self::BAY_RESIDENT, $response);
        $this->assertTextContains('Resident Zone A', $response);
        $this->assertTextContains(self::PLATE_JANE, $response);

        // Seeded upcoming visitor reservation: V-01 / Visitor Zone V tomorrow.
        $this->assertTextContains(self::BAY_VISITOR, $response);
        $this->assertTextContains('Visitor Zone V', $response);
        $this->assertTextContains('Demo Visitor Three', $response);

        $this->assertTextNotContains('R-02', $response, 'Another household\'s allocation must not render.');
        $this->assertTextNotContains('Layout preview', $response);
    }

    public function testParkingScreenEmptyReservationsForSecondHousehold(): void
    {
        $this->ensureLoggedInAs(self::AMINA);
        $response = $this->get('/app/parking');
        $this->assertStatus(200, $response);

        $this->assertTextContains('R-03', $response, 'Amina must see her own allocation.');
        $this->assertTextNotContains('Demo Visitor Three', $response);

        $lower = strtolower($this->pageText($response));
        $this->assertTrue(
            str_contains($lower, 'no visitor') || str_contains($lower, 'no reservation') || str_contains($lower, 'no upcoming'),
            'A resident without visitor reservations must see an honest empty state.'
        );
    }

    public function testInviteFormOffersHouseholdVehiclesAndParkingCheckbox(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app/visitors/invite');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);

        // Real household vehicle in the select (option value = vehicle uuid).
        $select = $this->xpath('//select[@name="vehicle_id"]', $dom);
        $this->assertCount(1, $select, 'The invite form must offer a household vehicle select.');
        $selectNode = $select->item(0);
        $this->assertNotNull($selectNode);

        $options = $this->xpath('.//option', $selectNode);
        $this->assertTrue($options->length >= 2, 'The vehicle select must include the seeded household vehicle.');
        $this->assertTextContains(self::PLATE_JANE, $response);

        $vehicle = $this->seededVehicle(self::PLATE_JANE);
        $vehicleOption = $this->xpath('.//option[@value="' . $vehicle['uuid'] . '"]', $selectNode);
        $this->assertCount(1, $vehicleOption, 'The seeded vehicle uuid must be the select option value.');

        // Parking required checkbox.
        $parking = $this->xpath('//input[@type="checkbox" and @name="parking_required"]', $dom);
        $this->assertCount(1, $parking, 'The invite form must offer a "Visitor parking required" checkbox.');
        $parkingNode = $parking->item(0);
        $this->assertNotNull($parkingNode);
        $this->assertCount(1, $this->xpath('//label[@for="' . $parkingNode->getAttribute('id') . '"]', $dom), 'The parking checkbox must have a label.');

        // Progressive-enhancement hook for the availability count (server-rendered).
        $available = $this->xpath('//*[@data-parking-available] | //*[contains(@class,"parking-available")]', $dom);
        $this->assertTrue($available->length >= 1, 'The invite form must expose the parking availability hint hook.');
    }

    public function testInviteWithParkingSucceedsThroughTheWebForm(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $vehicle = $this->seededVehicle(self::PLATE_JANE);
        $from = (new \DateTimeImmutable('+2 days 09:00', new \DateTimeZone('Africa/Nairobi')))->format('Y-m-d\TH:i');
        $to = (new \DateTimeImmutable('+2 days 11:00', new \DateTimeZone('Africa/Nairobi')))->format('Y-m-d\TH:i');

        $response = $this->post('/app/visitors/invite', [
            'visitor_name' => 'Web Parking Visitor',
            'visitor_phone' => '+254700090781',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Web parking flow',
            'valid_from' => $from,
            'valid_until' => $to,
            'visitor_count' => 1,
            'vehicle_id' => (string) $vehicle['uuid'],
            'parking_required' => '1',
        ], true);

        $this->assertStatus(302, $response);
        $this->assertContains('/app/passes/', (string) $response->header('Location'), 'The web invite must land on the pass screen for the one-time PIN reveal.');

        $invitation = $this->db->selectOne(
            "SELECT id, uuid FROM visitor_invitations WHERE purpose = 'Web parking flow' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($invitation, 'A successful web invite with parking must create the invitation.');

        $reservation = $this->reservationForInvitation((string) $invitation['uuid']);
        $this->assertNotNull($reservation, 'The web invite must create the parking reservation inside the transaction.');
        $this->assertSame('reserved', (string) $reservation['status']);
        $this->assertSame(self::PLATE_JANE, (string) $reservation['plate_number']);
    }

    public function testPassScreenShowsTheReservedBay(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/app/passes/' . DemoVisitorSeeder::INVITATION_UPCOMING_UUID);
        $this->assertStatus(200, $response);

        $this->assertTextContains('Visitor parking', $response);
        $this->assertTextContains(self::BAY_VISITOR, $response);
        $this->assertTextContains('Visitor Zone V', $response);
        $this->assertTextNotContains('Layout preview', $response);
    }

    public function testSeededDemoIdentifiersMatchTheDocumentedFixtures(): void
    {
        $this->assertSame('KDA123A', DemoParkingSeeder::PLATE_JANE);
        $this->assertSame('V-01', DemoParkingSeeder::BAY_RESERVED_CODE);
        $this->assertSame('V-06', DemoParkingSeeder::BAY_MAINTENANCE_CODE);
    }
}
