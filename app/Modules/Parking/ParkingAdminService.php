<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Modules\Vehicles\VehicleStatus;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Parking administration service (Increment 8 §2).
 *
 * Admin manages zones, bays, resident allocations and violations for the active
 * estate; guards report violations at their session gate and see only their own
 * reports. Nothing is hard-deleted (bays -> maintenance, allocations -> ended,
 * violations -> resolved/dismissed). All mutations are audited with uuid/enum/
 * status metadata only. Escaping is a view concern.
 */
final class ParkingAdminService
{
    private const ADMIN_GRACE_KEY = 'parking_overstay_grace_minutes';

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly ParkingAdminRepository $parking,
        private readonly ParkingAdminPresenter $presenter
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(
            $context,
            $context->db,
            new ParkingAdminRepository($context->db),
            new ParkingAdminPresenter()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Options                                                            */
    /* ------------------------------------------------------------------ */

    public function zoneTypes(): array
    {
        return $this->presenter->zoneTypes();
    }

    public function bayTypes(): array
    {
        return $this->presenter->bayTypes();
    }

    public function bayStatusOptions(): array
    {
        return $this->presenter->bayStatuses();
    }

    public function violationTypes(): array
    {
        return $this->presenter->violationTypes();
    }

    public function violationStatusOptions(): array
    {
        return $this->presenter->violationStatuses();
    }

    /* ------------------------------------------------------------------ */
    /* Overview / lists                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $estateId = $this->estateId();

        return [
            'stats' => $this->parking->stats($estateId),
            'zones' => array_map(static fn (array $row): array => ParkingAdminPresenter::zone($row), $this->parking->zones($estateId)),
            'bays' => array_map(static fn (array $row): array => ParkingAdminPresenter::bay($row), $this->parking->bays($estateId)),
            'allocations' => array_map(static fn (array $row): array => ParkingAdminPresenter::allocation($row), $this->parking->allocations($estateId)),
            'violations' => array_map(static fn (array $row): array => ParkingAdminPresenter::violation($row), $this->parking->violations($estateId, null, null)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminZones(): array
    {
        return [
            'zones' => array_map(
                static fn (array $row): array => ParkingAdminPresenter::zone($row),
                $this->parking->zones($this->estateId())
            ),
            'zone_types' => $this->zoneTypes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminBays(): array
    {
        return [
            'bays' => array_map(
                static fn (array $row): array => ParkingAdminPresenter::bay($row),
                $this->parking->bays($this->estateId())
            ),
            'zones' => $this->zoneOptions(),
            'bay_types' => $this->bayTypes(),
            'status_options' => $this->bayStatusOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminAllocations(): array
    {
        return [
            'allocations' => array_map(
                static fn (array $row): array => ParkingAdminPresenter::allocation($row),
                $this->parking->allocations($this->estateId())
            ),
            'bays' => $this->baySelectOptions(),
            'households' => $this->householdOptions(),
            'vehicles' => $this->vehicleOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminViolations(?string $status = null, ?string $type = null): array
    {
        $status = $this->filterValue($status, ParkingAdminPresenter::VIOLATION_STATUSES);
        $type = $this->filterValue($type, ParkingAdminPresenter::VIOLATION_TYPES);
        $estateId = $this->estateId();

        return [
            'violations' => array_map(
                static fn (array $row): array => ParkingAdminPresenter::violation($row),
                $this->parking->violations($estateId, $status, $type)
            ),
            'bays' => $this->baySelectOptions(),
            'filters' => ['status' => $status ?? 'all', 'type' => $type ?? 'all'],
            'status_options' => $this->violationStatusOptions(),
            'type_options' => $this->violationTypes(),
            'counts' => $this->parking->violationCounts($estateId),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Zones                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createZone(array $input): array
    {
        $clean = Validator::validate($input, [
            'name' => 'required|string|min:2|max:100',
            'zone_type' => 'required|in:' . implode(',', ParkingAdminPresenter::ZONE_TYPES),
        ], ['name' => 'zone name', 'zone_type' => 'zone type']);

        $name = trim((string) $clean['name']);
        $estateId = $this->estateId();

        if ($this->parking->findZoneByName($name, $estateId) !== null) {
            throw $this->conflict('ZONE_EXISTS', 'A zone with that name already exists in this estate.');
        }

        $uuid = Uuid::v4();
        $this->parking->insertZone([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'name' => $name,
            'zone_type' => (string) $clean['zone_type'],
            'created_at' => Clock::nowDb(),
        ]);

        $this->audit('parking.zone_created', 'parking_zone', [
            'zone_uuid' => $uuid,
            'zone_type' => (string) $clean['zone_type'],
        ]);

        return $this->zoneByUuid($uuid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateZone(string $uuid, array $input): array
    {
        $zone = $this->findZone($uuid);
        $clean = Validator::validate($input, [
            'name' => 'required|string|min:2|max:100',
            'zone_type' => 'required|in:' . implode(',', ParkingAdminPresenter::ZONE_TYPES),
        ], ['name' => 'zone name', 'zone_type' => 'zone type']);

        $name = trim((string) $clean['name']);

        if ($this->parking->findZoneByName($name, $this->estateId(), (int) $zone['id']) !== null) {
            throw $this->conflict('ZONE_EXISTS', 'A zone with that name already exists in this estate.');
        }

        $this->parking->updateZone((int) $zone['id'], [
            'name' => $name,
            'zone_type' => (string) $clean['zone_type'],
        ]);

        $this->audit('parking.zone_updated', 'parking_zone', [
            'zone_uuid' => (string) $zone['uuid'],
            'zone_type' => (string) $clean['zone_type'],
            'fields' => ['name', 'zone_type'],
        ]);

        return $this->zoneByUuid((string) $zone['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Bays                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBay(array $input): array
    {
        $clean = Validator::validate($input, [
            'bay_code' => 'required|string|min:2|max:30',
            'zone_uuid' => 'required|uuid',
            'bay_type' => 'required|in:' . implode(',', ParkingAdminPresenter::BAY_TYPES),
        ], ['bay_code' => 'bay code', 'zone_uuid' => 'zone', 'bay_type' => 'bay type']);

        $code = strtoupper(trim((string) $clean['bay_code']));
        $zone = $this->findZone((string) $clean['zone_uuid']);
        $estateId = $this->estateId();

        if ($this->parking->findBayByCode($code, $estateId) !== null) {
            throw $this->conflict('BAY_EXISTS', 'A bay with that code already exists in this estate.');
        }

        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $this->parking->insertBay([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'zone_id' => (int) $zone['id'],
            'bay_code' => $code,
            'bay_type' => (string) $clean['bay_type'],
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('parking.bay_created', 'parking_bay', [
            'bay_uuid' => $uuid,
            'zone_uuid' => (string) $zone['uuid'],
            'bay_type' => (string) $clean['bay_type'],
        ]);

        return $this->bayByUuid($uuid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateBay(string $uuid, array $input): array
    {
        $bay = $this->findBay($uuid);
        $clean = Validator::validate($input, [
            'bay_code' => 'required|string|min:2|max:30',
            'zone_uuid' => 'required|uuid',
            'bay_type' => 'required|in:' . implode(',', ParkingAdminPresenter::BAY_TYPES),
        ], ['bay_code' => 'bay code', 'zone_uuid' => 'zone', 'bay_type' => 'bay type']);

        $code = strtoupper(trim((string) $clean['bay_code']));
        $zone = $this->findZone((string) $clean['zone_uuid']);

        if ($this->parking->findBayByCode($code, $this->estateId(), (int) $bay['id']) !== null) {
            throw $this->conflict('BAY_EXISTS', 'A bay with that code already exists in this estate.');
        }

        $this->parking->updateBay((int) $bay['id'], [
            'bay_code' => $code,
            'zone_id' => (int) $zone['id'],
            'bay_type' => (string) $clean['bay_type'],
            'updated_at' => Clock::nowDb(),
        ]);

        $this->audit('parking.bay_updated', 'parking_bay', [
            'bay_uuid' => (string) $bay['uuid'],
            'zone_uuid' => (string) $zone['uuid'],
            'bay_type' => (string) $clean['bay_type'],
            'fields' => ['bay_code', 'zone_id', 'bay_type'],
        ]);

        return $this->bayByUuid((string) $bay['uuid']);
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveBay(string $uuid): array
    {
        $bay = $this->findBay($uuid);

        if ((string) $bay['status'] === 'maintenance') {
            return ParkingAdminPresenter::bay($bay);
        }

        if ($this->parking->bayInUse($this->estateId(), (int) $bay['id'])) {
            throw new HttpException(
                422,
                'BAY_IN_USE',
                'This bay has an active allocation, reservation or parking session and cannot be archived.',
                ['errors' => ['bay' => ['This bay is in use and cannot be archived.']]]
            );
        }

        $this->parking->updateBay((int) $bay['id'], ['status' => 'maintenance', 'updated_at' => Clock::nowDb()]);
        $this->audit('parking.bay_archived', 'parking_bay', ['bay_uuid' => (string) $bay['uuid']]);

        $fresh = $this->parking->findBay((string) $bay['uuid'], $this->estateId());

        return ParkingAdminPresenter::bay($fresh ?? $bay);
    }

    /* ------------------------------------------------------------------ */
    /* Allocations                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createAllocation(array $input): array
    {
        $clean = Validator::validate($input, [
            'bay_uuid' => 'required|uuid',
            'household_uuid' => 'required|uuid',
            'vehicle_uuid' => 'nullable|uuid',
            'starts_at' => 'nullable|string|max:40',
            'ends_at' => 'nullable|string|max:40',
        ], [
            'bay_uuid' => 'bay',
            'household_uuid' => 'household',
            'vehicle_uuid' => 'vehicle',
            'starts_at' => 'start time',
            'ends_at' => 'end time',
        ]);

        $estateId = $this->estateId();
        $bay = $this->findBay((string) $clean['bay_uuid']);
        $household = $this->findHousehold((string) $clean['household_uuid']);

        if ((string) $household['status'] !== 'active') {
            throw new HttpException(422, 'HOUSEHOLD_INACTIVE', 'Archived households cannot receive parking allocations.');
        }

        if ((string) $bay['status'] === 'maintenance') {
            throw new ValidationException(['bay_uuid' => ['That bay is under maintenance and cannot be allocated.']]);
        }

        if ((string) $bay['bay_type'] === 'resident'
            && !$this->parking->householdHasResident($estateId, (int) $household['id'])) {
            throw new ValidationException([
                'household_uuid' => ['Resident bays can only be allocated to households with an active resident or tenant.'],
            ]);
        }

        if ($this->parking->activeAllocationForBay($estateId, (int) $bay['id']) !== null) {
            throw $this->conflict('BAY_ALLOCATED', 'That bay already has an active allocation.');
        }

        if ($this->parking->activeAllocationForHousehold($estateId, (int) $household['id']) !== null) {
            throw $this->conflict('HOUSEHOLD_ALLOCATED', 'That household already has an active parking allocation.');
        }

        $vehicle = null;
        if (isset($clean['vehicle_uuid']) && is_string($clean['vehicle_uuid']) && $clean['vehicle_uuid'] !== '') {
            $vehicle = $this->parking->findVehicle($estateId, (string) $clean['vehicle_uuid']);

            if ($vehicle === null) {
                throw HttpException::notFound('VEHICLE_NOT_FOUND', 'That vehicle is not available for this estate.');
            }

            if ((int) $vehicle['household_id'] !== (int) $household['id']) {
                throw new ValidationException([
                    'vehicle_uuid' => ['That vehicle does not belong to the selected household.'],
                ]);
            }
        }

        $startsAt = $this->parseMoment(
            isset($clean['starts_at']) && is_string($clean['starts_at']) ? $clean['starts_at'] : null
        ) ?? Clock::now();

        $endsAt = $this->parseMoment(
            isset($clean['ends_at']) && is_string($clean['ends_at']) ? $clean['ends_at'] : null
        );

        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new ValidationException(['ends_at' => ['The end time must be after the start time.']]);
        }

        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $this->parking->insertAllocation([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'bay_id' => (int) $bay['id'],
            'household_id' => (int) $household['id'],
            'vehicle_id' => $vehicle === null ? null : (int) $vehicle['id'],
            'starts_at' => Clock::toDb($startsAt),
            'ends_at' => $endsAt === null ? null : Clock::toDb($endsAt),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('parking.allocation_created', 'parking_allocation', [
            'allocation_uuid' => $uuid,
            'bay_uuid' => (string) $bay['uuid'],
            'household_uuid' => (string) $household['uuid'],
            'vehicle_uuid' => $vehicle === null ? null : (string) $vehicle['uuid'],
        ]);

        return $this->allocationByUuid($uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function endAllocation(string $uuid): array
    {
        $allocation = $this->parking->findAllocation($uuid, $this->estateId());

        if ($allocation === null) {
            throw HttpException::notFound('ALLOCATION_NOT_FOUND', 'That parking allocation is not available.');
        }

        if ((string) $allocation['status'] !== 'ended') {
            $this->parking->updateAllocation((int) $allocation['id'], [
                'status' => 'ended',
                'ends_at' => Clock::nowDb(),
                'updated_at' => Clock::nowDb(),
            ]);
            $this->audit('parking.allocation_ended', 'parking_allocation', [
                'allocation_uuid' => (string) $allocation['uuid'],
                'bay_uuid' => (string) $allocation['bay_uuid'],
                'household_uuid' => (string) $allocation['household_uuid'],
            ]);
            $allocation = $this->parking->findAllocation($uuid, $this->estateId()) ?? $allocation;
        }

        return ParkingAdminPresenter::allocation($allocation);
    }

    /* ------------------------------------------------------------------ */
    /* Violations                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createViolation(array $input): array
    {
        $clean = $this->validateViolation($input);
        $bay = $this->findBay((string) $clean['bay_uuid']);

        return $this->insertViolation($clean, (int) $bay['id'], (string) $bay['uuid'], null, 'parking.violation_created');
    }

    /**
     * @param array{id: int, uuid: string, name: string} $gate
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reportViolation(array $gate, array $input): array
    {
        $clean = $this->validateViolation($input);
        $bay = $this->findBay((string) $clean['bay_uuid']);
        $user = $this->context->auth->requireUser();

        return $this->insertViolation(
            $clean,
            (int) $bay['id'],
            (string) $bay['uuid'],
            [(int) $user['id'], (int) $gate['id'], (string) $gate['uuid']],
            'parking.violation.reported'
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolveViolation(string $uuid, array $input): array
    {
        $violation = $this->parking->findViolation($uuid, $this->estateId());

        if ($violation === null) {
            throw HttpException::notFound('VIOLATION_NOT_FOUND', 'That parking violation is not available.');
        }

        $status = (string) $violation['status'];

        if ($status === 'resolved' || $status === 'dismissed') {
            throw new HttpException(409, 'VIOLATION_CLOSED', 'This violation is already closed.', [
                'errors' => ['status' => ['This violation is already closed.']],
            ]);
        }

        $clean = Validator::validate($input, [
            'status' => 'required|in:resolved,dismissed',
            'notes' => 'nullable|string|min:10|max:500',
        ], ['status' => 'status', 'notes' => 'resolution notes']);

        $target = (string) $clean['status'];
        $notes = isset($clean['notes']) && $clean['notes'] !== null ? trim((string) $clean['notes']) : null;

        if ($target === 'resolved' && ($notes === null || $notes === '')) {
            throw new ValidationException(['notes' => ['Add resolution notes (10-500 characters) to resolve this violation.']]);
        }

        $now = Clock::nowDb();
        $this->parking->updateViolation((int) $violation['id'], [
            'status' => $target,
            'notes' => $notes,
            'resolved_at' => $now,
        ]);

        $this->audit('parking.violation_resolved', 'parking_violation', [
            'violation_uuid' => (string) $violation['uuid'],
            'bay_uuid' => (string) $violation['bay_uuid'],
            'type' => (string) $violation['violation_type'],
            'status' => $target,
        ]);

        $fresh = $this->parking->findViolation($uuid, $this->estateId());

        return ParkingAdminPresenter::violation($fresh ?? $violation);
    }

    /**
     * @return array<string, mixed>
     */
    public function guardList(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();

        return [
            'violations' => array_map(
                static fn (array $row): array => ParkingAdminPresenter::violation($row),
                $this->parking->violationsForReporter($estateId, (int) $user['id'])
            ),
            'bays' => array_map(static fn (array $bay): array => [
                'uuid' => (string) $bay['uuid'],
                'bay_code' => (string) $bay['bay_code'],
                'zone_name' => (string) ($bay['zone_name'] ?? ''),
            ], $this->parking->bayOptions($estateId)),
            'type_options' => $this->violationTypes(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $clean
     * @param array<int, mixed>|null $reporterContext [userId, gateId, gateUuid] for guard reports
     * @return array<string, mixed>
     */
    private function insertViolation(
        array $clean,
        int $bayId,
        string $bayUuid,
        ?array $reporterContext,
        string $auditAction
    ): array {
        $estateId = $this->estateId();
        $user = $this->context->auth->requireUser();
        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $plate = isset($clean['plate_number']) && is_string($clean['plate_number'])
            ? trim((string) $clean['plate_number'])
            : '';
        $normalizedPlate = $plate === '' ? null : VehicleStatus::normalizePlate($plate);
        $notes = isset($clean['notes']) && $clean['notes'] !== null ? trim((string) $clean['notes']) : null;

        $this->parking->insertViolation([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'bay_id' => $bayId,
            'vehicle_id' => null,
            'plate_number' => $normalizedPlate === '' ? null : $normalizedPlate,
            'violation_type' => (string) $clean['type'],
            'status' => 'open',
            'notes' => $notes === null || $notes === '' ? null : $notes,
            'reported_by_user_id' => (int) $user['id'],
            'created_at' => $now,
        ]);

        $metadata = [
            'violation_uuid' => $uuid,
            'bay_uuid' => $bayUuid,
            'type' => (string) $clean['type'],
        ];

        if ($reporterContext !== null) {
            $metadata['gate_uuid'] = (string) $reporterContext[2];
        }

        $this->audit($auditAction, 'parking_violation', $metadata);

        $fresh = $this->parking->findViolation($uuid, $estateId);

        return $fresh === null
            ? ['uuid' => $uuid, 'type' => (string) $clean['type'], 'status' => 'open']
            : ParkingAdminPresenter::violation($fresh);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateViolation(array $input): array
    {
        $clean = Validator::validate($input, [
            'bay_uuid' => 'required|uuid',
            'type' => 'required|in:' . implode(',', ParkingAdminPresenter::VIOLATION_TYPES),
            'plate_number' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ], [
            'bay_uuid' => 'bay',
            'type' => 'violation type',
            'plate_number' => 'plate number',
            'notes' => 'notes',
        ]);

        $this->findBay((string) $clean['bay_uuid']);

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneByUuid(string $uuid): array
    {
        $row = $this->parking->findZone($uuid, $this->estateId());

        return ParkingAdminPresenter::zone($row ?? ['uuid' => $uuid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bayByUuid(string $uuid): array
    {
        $row = $this->parking->findBay($uuid, $this->estateId());

        return ParkingAdminPresenter::bay($row ?? ['uuid' => $uuid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationByUuid(string $uuid): array
    {
        $row = $this->parking->findAllocation($uuid, $this->estateId());

        return ParkingAdminPresenter::allocation($row ?? ['uuid' => $uuid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findZone(string $uuid): array
    {
        $zone = $this->parking->findZone($uuid, $this->estateId());

        if ($zone === null) {
            throw HttpException::notFound('ZONE_NOT_FOUND', 'That parking zone is not available.');
        }

        return $zone;
    }

    /**
     * @return array<string, mixed>
     */
    private function findBay(string $uuid): array
    {
        $bay = $this->parking->findBay($uuid, $this->estateId());

        if ($bay === null) {
            throw HttpException::notFound('BAY_NOT_FOUND', 'That parking bay is not available.');
        }

        return $bay;
    }

    /**
     * @return array<string, mixed>
     */
    private function findHousehold(string $uuid): array
    {
        $household = $this->parking->findHousehold($this->estateId(), $uuid);

        if ($household === null) {
            throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available.');
        }

        return $household;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function zoneOptions(): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'zone_type' => (string) $row['zone_type'],
            'zone_type_label' => ParkingAdminPresenter::zoneTypeLabel((string) $row['zone_type']),
        ], $this->parking->zones($this->estateId()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function baySelectOptions(): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'bay_code' => (string) $row['bay_code'],
            'bay_type' => (string) $row['bay_type'],
            'bay_type_label' => ParkingAdminPresenter::bayTypeLabel((string) $row['bay_type']),
            'status' => (string) $row['status'],
            'status_label' => ParkingAdminPresenter::bayStatusLabel((string) $row['status']),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
        ], $this->parking->allocatableBays($this->estateId()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function householdOptions(): array
    {
        return array_map(static function (array $row): array {
            $unit = (string) $row['unit_number'];
            $building = (string) ($row['building_name'] ?? '');

            return [
                'uuid' => (string) $row['uuid'],
                'label' => $building === '' ? $unit : $unit . ' · ' . $building,
                'household_type' => (string) ($row['household_type'] ?? ''),
            ];
        }, $this->parking->householdOptions($this->estateId()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vehicleOptions(): array
    {
        return array_map(static function (array $row): array {
            $unit = (string) $row['unit_number'];
            $building = (string) ($row['building_name'] ?? '');

            return [
                'uuid' => (string) $row['uuid'],
                'plate_number' => (string) $row['plate_number'],
                'household_uuid' => (string) $row['household_uuid'],
                'label' => (string) $row['plate_number'] . ' — ' . ($building === '' ? $unit : $unit . ' · ' . $building),
            ];
        }, $this->parking->vehicleOptions($this->estateId()));
    }

    private function parseMoment(?string $raw): ?DateTimeImmutable
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
                $moment = new DateTimeImmutable($value, new DateTimeZone($this->timezone()));
            } else {
                $moment = Clock::parse($value);
            }
        } catch (Throwable) {
            throw new ValidationException(['starts_at' => ['Enter a valid date/time.']]);
        }

        return $moment->setTimezone(Clock::utc());
    }

    /**
     * @param array<int, string> $allowed
     */
    private function filterValue(?string $requested, array $allowed): ?string
    {
        $value = trim((string) $requested);

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function conflict(string $code, string $message): HttpException
    {
        return new HttpException(422, $code, $message);
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    private function timezone(): string
    {
        return (string) ($this->context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $entityType, array $metadata): void
    {
        $this->context->audit->record($action, $entityType, null, $metadata, $this->estateId());
    }
}
