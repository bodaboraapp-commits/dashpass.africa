<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Vehicles\VehicleStatus;
use Tests\TestCase;

/**
 * Number-plate normalization (vehicles-parking-contract §2): uppercase, letters
 * and digits only, stored normalized and matched normalized; the 4–12 character
 * length rule is enforced by VehicleService (covered in VehicleModuleTest).
 */
final class PlateNormalizationTest extends TestCase
{
    public function testUpperCasesAndStripsSeparators(): void
    {
        $this->assertSame('KDA123A', VehicleStatus::normalizePlate('kda123a'));
        $this->assertSame('KDA123A', VehicleStatus::normalizePlate('KDA 123A'));
        $this->assertSame('KDA123A', VehicleStatus::normalizePlate('kda-123a'));
        $this->assertSame('KDA123A', VehicleStatus::normalizePlate('  K.d.A 1 2 3 A  '));
        $this->assertSame('KCA456B', VehicleStatus::normalizePlate("kca\t456\nb"));
        $this->assertSame('KD123ABC', VehicleStatus::normalizePlate('KD/123·ABC'));
    }

    public function testNormalizationIsIdempotent(): void
    {
        foreach (['kda123a', 'KDA-123A', '  kd 999 z ', 'kca_456_b'] as $input) {
            $normalized = VehicleStatus::normalizePlate($input);
            $this->assertSame($normalized, VehicleStatus::normalizePlate($normalized), 'Normalizing twice must be stable.');
            $this->assertMatches('/^[A-Z0-9]+$/', $normalized);
        }
    }

    public function testPlatesThatBecomeTooShortAreDetectedByLength(): void
    {
        // Normalization keeps the alphanumerics; the caller enforces 4–12 chars.
        $this->assertSame('AB1', VehicleStatus::normalizePlate('a-b-1'));
        $this->assertTrue(strlen(VehicleStatus::normalizePlate('AB1')) < 4);
        $this->assertTrue(strlen(VehicleStatus::normalizePlate('ABCDEFGHIJKLM')) > 12);
    }

    public function testSymbolOnlyInputNormalizesToEmpty(): void
    {
        foreach (['!!!', '   ', '- - -', '·/·'] as $input) {
            $this->assertSame('', VehicleStatus::normalizePlate($input), 'Symbols/spaces must not become a plate.');
        }
    }

    public function testPlateEqualityAfterNormalization(): void
    {
        // Different user formats of the same plate must normalize to the same key.
        $variants = ['KDA123A', 'kda123a', 'kda 123a', 'KDA-123-A', ' kda123a '];
        $first = VehicleStatus::normalizePlate($variants[0]);

        foreach ($variants as $variant) {
            $this->assertSame($first, VehicleStatus::normalizePlate($variant), 'All display variants must match one stored plate.');
        }
    }

    public function testVehicleTypeAndStatusPresentationHelpers(): void
    {
        $types = VehicleStatus::typeValues();
        $this->assertSame(['car', 'motorcycle', 'van', 'truck', 'other'], $types);
        $this->assertCount(5, VehicleStatus::types());

        foreach (VehicleStatus::types() as $type) {
            $this->assertArrayHasKey('value', $type);
            $this->assertArrayHasKey('label', $type);
            $this->assertContains($type['value'], $types);
        }

        $this->assertSame('Active', VehicleStatus::label('active'));
        $this->assertSame('Inactive', VehicleStatus::label('inactive'));
        $this->assertSame('Blacklisted', VehicleStatus::label('blacklisted'));
        $this->assertSame('ok', VehicleStatus::tone('active'));
        $this->assertSame('neutral', VehicleStatus::tone('inactive'));
        $this->assertSame('danger', VehicleStatus::tone('blacklisted'));

        // Unknown values fall back to a readable label/tone, never an error.
        $this->assertSame('Some future status', VehicleStatus::label('some future status'));
        $this->assertSame('neutral', VehicleStatus::tone('unknown'));
    }
}
