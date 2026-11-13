<?php

declare(strict_types=1);

namespace App\Modules\Vehicles;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Parking\ParkingPresenter;
use App\Modules\Visitors\UserHouseholdRepository;

/**
 * Household-scoped vehicle management (Increment 3 contract §2).
 *
 * Residents manage vehicles of households where they are an active member in
 * the active estate; staff with `vehicle.manage_all` may list the estate and
 * change any vehicle's status. Plates are normalised app-side and unique per
 * estate (409 PLATE_ALREADY_REGISTERED). Ownership failures are 404 so vehicle
 * uuids cannot be enumerated.
 */
final class VehicleService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly VehicleRepository $vehicles,
        private readonly UserHouseholdRepository $households
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(
            $context,
            $context->db,
            new VehicleRepository($context->db),
            new UserHouseholdRepository($context->db)
        );
    }

    /**
     * Vehicles visible to the caller (own households, or the whole estate for
     * staff with vehicle.view_all). Canonical item shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listVisible(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->canViewAll()
            ? $this->vehicles->forEstate($estateId)
            : $this->vehicles->forHouseholds($estateId, $this->householdIds());

        return array_map([ParkingPresenter::class, 'vehicle'], $rows);
    }

    /**
     * Active vehicles belonging to the caller's households (invite options).
     *
     * @return array<int, array<string, mixed>>
     */
    public function vehiclesForInvite(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $rows = $this->vehicles->activeForHouseholds($estateId, $this->householdIds());

        return array_map([ParkingPresenter::class, 'vehicle'], $rows);
    }

    /**
     * Resolve an active vehicle that belongs to a specific household (invitation
     * flow). Returns null when it is not the household's active vehicle.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForHousehold(int $estateId, int $householdId, string $vehicleUuid): ?array
    {
        return $this->vehicles->findActiveForHousehold($estateId, $householdId, $vehicleUuid);
    }

    /**
     * Create a vehicle. Canonical item shape plus internal id.
     *
     * @param array<string, mixed> $input
     * @return array{vehicle: array<string, mixed>, id: int}
     */
    public function create(array $input): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $userId = (int) $user['id'];

        $clean = Validator::validate(
            $input,
            [
                'plate_number' => 'required|string|max:20',
                'vehicle_type' => 'nullable|in:' . implode(',', VehicleStatus::typeValues()),
                'colour' => 'nullable|string|max:40',
                'household_uuid' => 'nullable|uuid',
            ],
            [
                'plate_number' => 'number plate',
                'vehicle_type' => 'vehicle type',
                'colour' => 'colour',
                'household_uuid' => 'household',
            ]
        );

        $plate = VehicleStatus::normalizePlate((string) $clean['plate_number']);

        if (strlen($plate) < 4 || strlen($plate) > 12) {
            throw new ValidationException([
                'plate_number' => ['Enter a valid number plate (4 to 12 letters and digits).'],
            ]);
        }

        $household = $this->resolveHousehold($clean, $userId, $estateId);

        if ($household === null) {
            throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available for your account.');
        }

        if ($this->vehicles->findByPlate($estateId, $plate) !== null) {
            throw new HttpException(
                409,
                'PLATE_ALREADY_REGISTERED',
                'That number plate is already registered for this estate.'
            );
        }

        $uuid = $this->vehicles->uuid();
        $now = Clock::nowDb();
        $vehicleType = isset($clean['vehicle_type']) && $clean['vehicle_type'] !== null
            ? (string) $clean['vehicle_type']
            : 'car';
        $colour = isset($clean['colour']) && $clean['colour'] !== null && trim((string) $clean['colour']) !== ''
            ? trim((string) $clean['colour'])
            : null;

        $id = $this->vehicles->insert([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'household_id' => (int) $household['id'],
            'plate_number' => $plate,
            'vehicle_type' => $vehicleType,
            'colour' => $colour,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $this->vehicles->findInEstate($estateId, $uuid) ?? [];

        $this->context->audit->record(
            'vehicle.created',
            'vehicle',
            $id,
            [
                'vehicle_uuid' => $uuid,
                'plate_number' => $plate,
                'vehicle_type' => $vehicleType,
                'household_label' => $household['label'],
            ],
            $estateId
        );

        return ['vehicle' => ParkingPresenter::vehicle($row), 'id' => $id];
    }

    /**
     * Deactivate a vehicle (sets status inactive).
     *
     * @return array{status: string}
     */
    public function deactivate(string $vehicleUuid): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $row = $this->findScopedVehicle($estateId, $vehicleUuid);

        if ($row === null) {
            throw HttpException::notFound('VEHICLE_NOT_FOUND', 'That vehicle is not available for your account.');
        }

        $this->vehicles->updateStatus((int) $row['id'], 'inactive');

        $this->context->audit->record(
            'vehicle.deactivated',
            'vehicle',
            (int) $row['id'],
            [
                'vehicle_uuid' => (string) $row['uuid'],
                'plate_number' => (string) $row['plate_number'],
                'previous_status' => (string) $row['status'],
            ],
            $estateId
        );

        return ['status' => 'inactive'];
    }

    /**
     * Update mutable vehicle fields (service-level for admin/tests; the public
     * routes in this increment only create and deactivate).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $vehicleUuid, array $input): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $row = $this->findScopedVehicle($estateId, $vehicleUuid);

        if ($row === null) {
            throw HttpException::notFound('VEHICLE_NOT_FOUND', 'That vehicle is not available for your account.');
        }

        $clean = Validator::validate(
            $input,
            [
                'plate_number' => 'nullable|string|max:20',
                'vehicle_type' => 'nullable|in:' . implode(',', VehicleStatus::typeValues()),
                'colour' => 'nullable|string|max:40',
            ],
            [
                'plate_number' => 'number plate',
                'vehicle_type' => 'vehicle type',
                'colour' => 'colour',
            ]
        );

        $fields = [];
        $changed = [];

        if (isset($clean['plate_number']) && $clean['plate_number'] !== null) {
            $plate = VehicleStatus::normalizePlate((string) $clean['plate_number']);

            if (strlen($plate) < 4 || strlen($plate) > 12) {
                throw new ValidationException([
                    'plate_number' => ['Enter a valid number plate (4 to 12 letters and digits).'],
                ]);
            }

            $existing = $this->vehicles->findByPlate($estateId, $plate);

            if ($existing !== null && (int) $existing['id'] !== (int) $row['id']) {
                throw new HttpException(
                    409,
                    'PLATE_ALREADY_REGISTERED',
                    'That number plate is already registered for this estate.'
                );
            }

            $fields['plate_number'] = $plate;
            $changed[] = 'plate_number';
        }

        if (isset($clean['vehicle_type']) && $clean['vehicle_type'] !== null) {
            $fields['vehicle_type'] = (string) $clean['vehicle_type'];
            $changed[] = 'vehicle_type';
        }

        if (array_key_exists('colour', $clean)) {
            $fields['colour'] = $clean['colour'] === null || trim((string) $clean['colour']) === ''
                ? null
                : trim((string) $clean['colour']);
            $changed[] = 'colour';
        }

        if ($fields !== []) {
            $this->vehicles->updateFields((int) $row['id'], $fields);

            $this->context->audit->record(
                'vehicle.updated',
                'vehicle',
                (int) $row['id'],
                ['vehicle_uuid' => (string) $row['uuid'], 'changed' => $changed],
                $estateId
            );
        }

        return ParkingPresenter::vehicle($this->vehicles->findInEstate($estateId, $vehicleUuid) ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findScopedVehicle(int $estateId, string $vehicleUuid): ?array
    {
        if ($this->canManageAll()) {
            return $this->vehicles->findInEstate($estateId, $vehicleUuid);
        }

        return $this->vehicles->findForHouseholds($estateId, $this->householdIds(), $vehicleUuid);
    }

    /**
     * @param array<string, mixed> $clean
     * @return array<string, mixed>|null
     */
    private function resolveHousehold(array $clean, int $userId, int $estateId): ?array
    {
        $requested = isset($clean['household_uuid']) && is_string($clean['household_uuid'])
            ? $clean['household_uuid']
            : null;

        if ($requested !== null) {
            if ($this->canManageAll()) {
                return $this->households->findInEstate($estateId, $requested);
            }

            return $this->households->findForUser($userId, $requested, $estateId);
        }

        $households = $this->households->forUserInEstate($userId, $estateId);

        return $households[0] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private function householdIds(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        return $this->households->idsForUser((int) $user['id'], $estateId);
    }

    private function canViewAll(): bool
    {
        return $this->context->permission->can('vehicle.view_all');
    }

    private function canManageAll(): bool
    {
        return $this->context->permission->can('vehicle.manage_all');
    }
}
