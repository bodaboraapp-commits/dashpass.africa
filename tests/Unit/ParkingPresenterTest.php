<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Parking\ParkingPresenter;
use Tests\TestCase;

/**
 * Parking presentation contract (vehicles-parking-contract §2, §4, frozen
 * view-data contract): status labels always accompany a colour tone, and the
 * canonical shapes expose exactly the documented keys — never raw internal ids.
 */
final class ParkingPresenterTest extends TestCase
{
    public function testBayStatusLabelsAndTones(): void
    {
        $this->assertSame('Available', ParkingPresenter::bayLabel('available'));
        $this->assertSame('Reserved', ParkingPresenter::bayLabel('reserved'));
        $this->assertSame('Occupied', ParkingPresenter::bayLabel('occupied'));
        $this->assertSame('Maintenance', ParkingPresenter::bayLabel('maintenance'));

        $this->assertSame('ok', ParkingPresenter::bayTone('available'));
        $this->assertSame('warn', ParkingPresenter::bayTone('reserved'));
        $this->assertSame('danger', ParkingPresenter::bayTone('occupied'));
        $this->assertSame('neutral', ParkingPresenter::bayTone('maintenance'));
    }

    public function testReservationAllocationAndSessionLabels(): void
    {
        $this->assertSame('Reserved', ParkingPresenter::reservationLabel('reserved'));
        $this->assertSame('Checked in', ParkingPresenter::reservationLabel('checked_in'));
        $this->assertSame('Completed', ParkingPresenter::reservationLabel('completed'));
        $this->assertSame('Cancelled', ParkingPresenter::reservationLabel('cancelled'));
        $this->assertSame('Expired', ParkingPresenter::reservationLabel('expired'));
        $this->assertSame('ok', ParkingPresenter::reservationTone('checked_in'));
        $this->assertSame('danger', ParkingPresenter::reservationTone('cancelled'));

        $this->assertSame('Active', ParkingPresenter::allocationLabel('active'));
        $this->assertSame('Suspended', ParkingPresenter::allocationLabel('suspended'));
        $this->assertSame('Ended', ParkingPresenter::allocationLabel('ended'));
        $this->assertSame('ok', ParkingPresenter::allocationTone('active'));

        $this->assertSame('Active', ParkingPresenter::sessionLabel('active'));
        $this->assertSame('Completed', ParkingPresenter::sessionLabel('completed'));
        $this->assertSame('Overstay', ParkingPresenter::sessionLabel('overstay'));
        $this->assertSame('Void', ParkingPresenter::sessionLabel('void'));
        $this->assertSame('danger', ParkingPresenter::sessionTone('overstay'));
    }

    public function testUnknownStatusesFallBackGracefully(): void
    {
        $this->assertSame('Some future state', ParkingPresenter::bayLabel('some_future_state'));
        $this->assertSame('neutral', ParkingPresenter::bayTone('some_future_state'));
        $this->assertSame('Some future state', ParkingPresenter::reservationLabel('some_future_state'));
        $this->assertSame('neutral', ParkingPresenter::sessionTone('some_future_state'));
    }

    public function testBayShape(): void
    {
        $shape = ParkingPresenter::bay([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'bay_code' => 'V-01',
            'zone_name' => 'Visitor Zone V',
            'bay_type' => 'visitor',
            'status' => 'available',
        ], true);

        $this->assertSame(
            ['uuid', 'bay_code', 'zone_name', 'bay_type', 'status', 'available'],
            array_keys($shape)
        );
        $this->assertTrue($shape['available']);
        $this->assertSame('V-01', $shape['bay_code']);
        $this->assertFalse(ParkingPresenter::bay([], false)['available']);
    }

    public function testReservationShapeHasNoInternalIds(): void
    {
        $shape = ParkingPresenter::reservation([
            'id' => 99,
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'invitation_uuid' => '33333333-3333-4333-8333-333333333333',
            'visitor_name' => 'Demo Visitor Three',
            'plate_number' => 'KDA123A',
            'bay_code' => 'V-01',
            'zone_name' => 'Visitor Zone V',
            'starts_at' => '2026-11-12 07:00:00.000',
            'ends_at' => '2026-11-12 11:00:00.000',
            'status' => 'reserved',
        ]);

        $this->assertSame(
            ['uuid', 'invitation_uuid', 'visitor_name', 'plate_number', 'bay_code', 'zone_name', 'starts_at', 'ends_at', 'status', 'status_label', 'status_tone'],
            array_keys($shape)
        );
        $this->assertArrayNotHasKey('id', $shape);
        $this->assertSame('Reserved', $shape['status_label']);
        $this->assertSame('warn', $shape['status_tone']);
        $this->assertSame('V-01', $shape['bay_code']);

        $empty = ParkingPresenter::reservation(['uuid' => 'x']);
        $this->assertNull($empty['plate_number'], 'An absent plate must be null, never an empty string leak.');
    }

    public function testAllocationShapeIncludesVehicleOrNull(): void
    {
        $shape = ParkingPresenter::allocation([
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'bay_code' => 'R-01',
            'zone_name' => 'Resident Zone A',
            'status' => 'active',
            'vehicle_plate' => 'KDA123A',
            'vehicle_type' => 'car',
            'vehicle_colour' => 'White',
        ]);

        $this->assertSame(['uuid', 'bay_code', 'zone_name', 'status', 'status_label', 'status_tone', 'vehicle'], array_keys($shape));
        $this->assertSame('Active', $shape['status_label']);
        $this->assertTrue(is_array($shape['vehicle']), 'The allocation vehicle must be an array.');
        $this->assertSame('KDA123A', $shape['vehicle']['plate_number']);
        $this->assertSame('White', $shape['vehicle']['colour']);

        $withoutVehicle = ParkingPresenter::allocation(['uuid' => 'x', 'bay_code' => 'R-02', 'status' => 'active']);
        $this->assertNull($withoutVehicle['vehicle']);
    }

    public function testPassParkingShapeOmitsThePlate(): void
    {
        $shape = ParkingPresenter::passParking([
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'bay_code' => 'V-01',
            'zone_name' => 'Visitor Zone V',
            'starts_at' => '2026-11-12 07:00:00.000',
            'ends_at' => '2026-11-12 11:00:00.000',
            'status' => 'reserved',
            'plate_number' => 'KDA123A',
        ]);

        $this->assertSame(['reservation_uuid', 'bay_code', 'zone_name', 'starts_at', 'ends_at', 'status', 'status_label', 'status_tone'], array_keys($shape));
        $this->assertArrayNotHasKey('plate_number', $shape, 'The resident pass parking summary must not expose the plate.');
    }

    public function testPublicParkingShapeHasBayAndWindowOnly(): void
    {
        $shape = ParkingPresenter::publicParking([
            'bay_code' => 'V-01',
            'zone_name' => 'Visitor Zone V',
            'starts_at' => '2026-11-12 07:00:00.000',
            'ends_at' => '2026-11-12 11:00:00.000',
            'plate_number' => 'KDA123A',
            'visitor_name' => 'Demo Visitor Three',
        ]);

        $this->assertSame(['bay_code', 'zone_name', 'starts_at', 'ends_at'], array_keys($shape));
        $this->assertArrayNotHasKey('plate_number', $shape, 'The public pass page must not expose a vehicle plate.');
        $this->assertArrayNotHasKey('visitor_name', $shape);
    }

    public function testSessionShape(): void
    {
        $shape = ParkingPresenter::session([
            'uuid' => '66666666-6666-4666-8666-666666666666',
            'invitation_uuid' => '77777777-7777-4777-8777-777777777777',
            'bay_code' => 'V-01',
            'zone_name' => 'Visitor Zone V',
            'visitor_name' => 'Demo Visitor Three',
            'plate_number' => 'KDA123A',
            'checkin_at' => '2026-11-12 07:05:00.000',
            'checkout_at' => null,
            'status' => 'active',
        ]);

        $this->assertSame('66666666-6666-4666-8666-666666666666', $shape['session_uuid']);
        $this->assertArrayNotHasKey('uuid', $shape, 'Guard session shape uses session_uuid, not raw uuid.');
        $this->assertNull($shape['checkout_at']);
        $this->assertSame('Active', $shape['status_label']);
        $this->assertSame('ok', $shape['status_tone']);
    }
}
