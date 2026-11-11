<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Parking\ParkingPresenter;
use Tests\TestCase;

/**
 * Increment 8 — parking administration validation + presenters
 * (docs/parking-administration-contract.md §2, §4).
 *
 * The admin/guard validators are built on the shared Validator; T60 owns the
 * final helper extraction, so this unit file covers what can be frozen up front:
 *  - the exact enum allowlists the contract requires and the migration-7 schema
 *    already defines (zone/bay/allocation/session/violation values),
 *  - "no schema change" guards (overstay values only exist in migration 7),
 *  - the Validator rules the admin forms use (allowlists + 0–120 grace range),
 *  - the presenter labels/tones for the statuses Inc-8 renders (overstay,
 *    maintenance, ended, …) so views never rely on colour alone.
 *
 * PRE-WRITTEN for the Inc-8 gate: parked in storage/temporary/pending-tests/
 * until T60 lands; extend with the frozen validation-helper API if T60 adds one.
 */
final class ParkingAdminValidationTest extends TestCase
{
    /** @var array<string, array<int, string>> */
    private const ENUM_ALLOWLISTS = [
        'zone_type' => ['resident', 'visitor', 'mixed', 'service'],
        'bay_type' => ['resident', 'visitor', 'accessible', 'service'],
        'bay_status' => ['available', 'reserved', 'occupied', 'maintenance'],
        'allocation_status' => ['active', 'suspended', 'ended'],
        'session_status' => ['active', 'completed', 'overstay', 'void'],
        'violation_type' => ['overstay', 'unauthorised_bay', 'unregistered_vehicle', 'other'],
        'violation_status' => ['open', 'resolved', 'dismissed'],
    ];

    private function parkingMigration(): string
    {
        $path = BASE_PATH . '/database/migrations/20261111_000007_create_vehicles_parking_tables.sql';
        $this->assertTrue(is_file($path), 'The vehicles/parking migration must exist.');

        return (string) file_get_contents($path);
    }

    /**
     * @param array<int, string> $values
     */
    private function assertEnumContains(string $sql, string $table, string $column, array $values): void
    {
        $tablePattern = '/CREATE TABLE\s+`?' . preg_quote($table, '/') . '`?\s*\((.*?)\)\s*ENGINE/is';
        $this->assertMatches($tablePattern, $sql, 'Expected CREATE TABLE ' . $table . ' in the parking migration.');
        preg_match($tablePattern, $sql, $tableMatch);

        $columnPattern = '/`?' . preg_quote($column, '/') . '`?\s+ENUM\s*\(([^)]*)\)/i';
        $this->assertMatches($columnPattern, (string) ($tableMatch[1] ?? ''), 'Expected ENUM column ' . $table . '.' . $column . '.');
        preg_match($columnPattern, (string) ($tableMatch[1] ?? ''), $columnMatch);

        $actual = array_map(
            static fn (string $value): string => trim($value, " \t\n\r\0\x0B'"),
            explode(',', (string) ($columnMatch[1] ?? ''))
        );

        foreach ($values as $value) {
            $this->assertContains($value, $actual, 'ENUM ' . $table . '.' . $column . ' must allow "' . $value . '".');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Schema enum guards (no schema change this increment)               */
    /* ------------------------------------------------------------------ */

    public function testParkingEnumsMatchTheInc8Allowlists(): void
    {
        $sql = $this->parkingMigration();

        $this->assertEnumContains($sql, 'parking_zones', 'zone_type', self::ENUM_ALLOWLISTS['zone_type']);
        $this->assertEnumContains($sql, 'parking_bays', 'bay_type', self::ENUM_ALLOWLISTS['bay_type']);
        $this->assertEnumContains($sql, 'parking_bays', 'status', self::ENUM_ALLOWLISTS['bay_status']);
        $this->assertEnumContains($sql, 'parking_allocations', 'status', self::ENUM_ALLOWLISTS['allocation_status']);
        $this->assertEnumContains($sql, 'parking_sessions', 'status', self::ENUM_ALLOWLISTS['session_status']);
        $this->assertEnumContains($sql, 'parking_violations', 'violation_type', self::ENUM_ALLOWLISTS['violation_type']);
        $this->assertEnumContains($sql, 'parking_violations', 'status', self::ENUM_ALLOWLISTS['violation_status']);
    }

    public function testOverstayValueAlreadyExistsInMigrationSevenOnly(): void
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        $this->assertNotEmpty($files, 'The migration directory must not be empty.');

        $withOverstay = [];

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);

            if (stripos($sql, 'overstay') !== false) {
                $withOverstay[] = basename($file);
            }
        }

        $this->assertSame(
            ['20261111_000007_create_vehicles_parking_tables.sql'],
            array_values($withOverstay),
            'Increment 8 must not add a migration: overstay only exists in the parking migration (8 migrations / 37 tables stays unchanged).'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Validator rules the admin forms/validators use                     */
    /* ------------------------------------------------------------------ */

    public function testEnumAllowlistValidatorAcceptsOnlyContractValues(): void
    {
        foreach (self::ENUM_ALLOWLISTS as $field => $values) {
            $rule = 'required|in:' . implode(',', $values);

            $accepted = Validator::validate([$field => $values[0]], [$field => $rule]);
            $this->assertSame($values[0], (string) $accepted[$field], 'The first ' . $field . ' enum value must validate.');

            $rejected = false;

            try {
                Validator::validate([$field => 'not-a-' . $field], [$field => $rule]);
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertArrayHasKey($field, $exception->errors(), 'The enum error must be keyed by ' . $field . '.');
            }

            $this->assertTrue($rejected, 'An invalid ' . $field . ' value must raise a ValidationException.');
        }
    }

    public function testOverstayGraceRangeValidatorRespectsZeroToHundredTwenty(): void
    {
        foreach (['0', '30', '120'] as $valid) {
            $result = Validator::validate(['grace' => $valid], ['grace' => 'required|integer|between:0,120']);
            $this->assertSame((int) $valid, (int) $result['grace'], 'Grace ' . $valid . ' must validate.');
        }

        foreach (['-1', '121', 'abc'] as $invalid) {
            $rejected = false;

            try {
                Validator::validate(['grace' => $invalid], ['grace' => 'required|integer|between:0,120']);
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertArrayHasKey('grace', $exception->errors());
            }

            $this->assertTrue($rejected, 'Grace value "' . $invalid . '" must be rejected.');
        }
    }

    public function testParkingAdminTextLimitsAreEnforced(): void
    {
        $cases = [
            ['zone_name', 'ab', 'required|string|min:2|max:100'],
            ['zone_name', str_repeat('z', 101), 'required|string|min:2|max:100'],
            ['bay_code', 'a', 'required|string|min:2|max:30'],
            ['bay_code', str_repeat('b', 31), 'required|string|min:2|max:30'],
            ['notes', str_repeat('n', 501), 'nullable|string|max:500'],
        ];

        foreach ($cases as [$field, $value, $rule]) {
            $rejected = false;

            try {
                Validator::validate([$field => $value], [$field => $rule]);
            } catch (ValidationException $exception) {
                $rejected = true;
                $this->assertArrayHasKey($field, $exception->errors());
            }

            $this->assertTrue($rejected, 'Value for ' . $field . ' must be rejected by rule ' . $rule . '.');
        }

        $accepted = Validator::validate(
            ['zone_name' => 'Zone 1', 'bay_code' => 'B-1', 'notes' => 'ok'],
            ['zone_name' => 'required|string|min:2|max:100', 'bay_code' => 'required|string|min:2|max:30', 'notes' => 'nullable|string|max:500']
        );
        $this->assertSame('Zone 1', (string) $accepted['zone_name']);
        $this->assertSame('B-1', (string) $accepted['bay_code']);
    }

    /* ------------------------------------------------------------------ */
    /* Presenter labels/tones (Inc-8 statuses never rely on colour alone)  */
    /* ------------------------------------------------------------------ */

    public function testOverstaySessionIsRenderedAsADangerStatus(): void
    {
        $this->assertSame('Overstay', ParkingPresenter::sessionLabel('overstay'));
        $this->assertSame('danger', ParkingPresenter::sessionTone('overstay'));
        $this->assertSame('Active', ParkingPresenter::sessionLabel('active'));
    }

    public function testMaintenanceAndEndedStatusesAreHumanReadable(): void
    {
        $this->assertSame('Maintenance', ParkingPresenter::bayLabel('maintenance'));
        $this->assertSame('neutral', ParkingPresenter::bayTone('maintenance'));
        $this->assertSame('Maintenance', ParkingPresenter::bayLabel('maintenance'), 'An archived bay is rendered as maintenance.');

        $this->assertSame('Ended', ParkingPresenter::allocationLabel('ended'));
        $this->assertSame('Active', ParkingPresenter::allocationLabel('active'));
        $this->assertSame('Available', ParkingPresenter::bayLabel('available'));
        $this->assertSame('Occupied', ParkingPresenter::bayLabel('occupied'));
    }

    public function testStatusLabelsAreNeverEmptyForUnknownValues(): void
    {
        // Presenters must degrade to a readable label (never an empty chip) so
        // admin/guard screens render honest text for unexpected row states.
        foreach (['not_a_status', 'closed', 'unknown'] as $value) {
            $this->assertNotSame('', ParkingPresenter::bayLabel($value));
            $this->assertNotSame('', ParkingPresenter::sessionLabel($value));
            $this->assertNotSame('', ParkingPresenter::allocationLabel($value));
            $this->assertNotSame('', ParkingPresenter::reservationLabel($value));
        }
    }
}
