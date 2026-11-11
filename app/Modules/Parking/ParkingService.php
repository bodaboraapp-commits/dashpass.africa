<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Modules\Visitors\AccessPresenter;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Visitor parking availability, reservations and sessions (Increment 3 §2).
 *
 * Reservations are created only inside the invitation transaction:
 * {@see reserveForInvitation()} locks candidate bays in bay_code order, re-checks
 * overlap and throws 409 NO_PARKING_AVAILABLE when none is free so the caller's
 * transaction (invitation + credentials) rolls back entirely.
 *
 * Guard session side effects are separate transactional helpers used by
 * AccessService inside its check-in/check-out transaction.
 */
final class ParkingService
{
    private const MAX_WINDOW_SECONDS = 7 * 86400;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly ParkingRepository $parking
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new ParkingRepository($context->db));
    }

    /* ------------------------------------------------------------------ */
    /* Availability                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{bays: array<int, array<string, mixed>>, from: string, to: string}
     */
    public function availability(?string $fromInput, ?string $toInput, string $type = 'visitor'): array
    {
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $timezone = (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi');
        [$from, $to] = $this->resolveWindow($fromInput, $toInput, $timezone);

        $rows = $this->parking->baysWithAvailability($estateId, $type, Clock::toDb($from), Clock::toDb($to));
        $bays = array_map(
            static fn (array $row): array => ParkingPresenter::bay($row, (int) $row['available'] === 1),
            $rows
        );

        return [
            'bays' => $bays,
            'from' => AccessPresenter::iso(Clock::toDb($from)),
            'to' => AccessPresenter::iso(Clock::toDb($to)),
        ];
    }

    /**
     * Count of visitor bays available for the caller's default window (invite form hint).
     */
    public function availableVisitorBayCount(?string $fromInput = null, ?string $toInput = null): int
    {
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $timezone = (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi');

        try {
            [$from, $to] = $this->resolveWindow($fromInput, $toInput, $timezone);
        } catch (Throwable) {
            $from = Clock::now();
            $to = $from->modify('+24 hours');
        }

        return $this->parking->countAvailableBays($estateId, Clock::toDb($from), Clock::toDb($to));
    }

    /**
     * Resident allocations (own households).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allocationsForResident(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->parking->allocationsForHouseholds($estateId, $this->householdIds());

        return array_map([ParkingPresenter::class, 'allocation'], $rows);
    }

    /**
     * Upcoming visitor reservations for the resident's households.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reservationsForResident(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->parking->reservationsForHouseholds($estateId, $this->householdIds(), 'upcoming');

        return array_map([ParkingPresenter::class, 'reservation'], $rows);
    }

    /**
     * All reservations for the resident's households (Bookings → My Bookings).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reservationsForBookings(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->parking->reservationsForHouseholds($estateId, $this->householdIds(), 'all');

        return array_map([ParkingPresenter::class, 'reservation'], $rows);
    }

    /**
     * Reservation summary for a pass screen when one exists.
     *
     * @return array<string, mixed>|null
     */
    public function passParkingForInvitation(int $estateId, int $invitationId): ?array
    {
        $row = $this->parking->reservationForInvitation($estateId, $invitationId);

        return $row === null ? null : ParkingPresenter::passParking($row);
    }

    /* ------------------------------------------------------------------ */
    /* Reservation (inside the invitation transaction)                    */
    /* ------------------------------------------------------------------ */

    /**
     * Reserve the first conflict-free visitor bay for an invitation.
     *
     * MUST be called inside the caller's DB transaction. Locks candidate bays in
     * deterministic order, re-checks overlap/active sessions, and throws
     * 409 NO_PARKING_AVAILABLE (rolling the caller's invitation back) when none
     * is free. Auditing/SMS are the caller's responsibility (after commit).
     *
     * @param array<string, mixed>|null $vehicle Row with id + normalized plate_number.
     * @return array<string, mixed>
     */
    public function reserveForInvitation(
        int $invitationId,
        int $estateId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?array $vehicle = null
    ): array {
        $fromDb = Clock::toDb($startsAt);
        $toDb = Clock::toDb($endsAt);

        $chosen = null;

        foreach ($this->parking->visitorBaysForUpdate($estateId) as $bay) {
            if ((string) $bay['bay_type'] === 'resident') {
                // Defensive: visitor reservations can never use resident bays.
                continue;
            }

            if ((string) $bay['status'] === 'maintenance') {
                continue;
            }

            if ($this->parking->bayHasConflict($estateId, (int) $bay['id'], $fromDb, $toDb)) {
                continue;
            }

            $chosen = $bay;
            break;
        }

        if ($chosen === null) {
            throw new HttpException(
                409,
                'NO_PARKING_AVAILABLE',
                'No visitor parking is available for the selected visit window.'
            );
        }

        $uuid = $this->parking->uuid();
        $now = Clock::nowDb();
        $plate = $vehicle !== null && isset($vehicle['plate_number'])
            ? (string) $vehicle['plate_number']
            : null;

        $reservationId = $this->parking->insertReservation([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'bay_id' => (int) $chosen['id'],
            'vehicle_id' => $vehicle === null ? null : (int) $vehicle['id'],
            'plate_number' => $plate === '' ? null : $plate,
            'starts_at' => $fromDb,
            'ends_at' => $toDb,
            'status' => 'reserved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'reservation_uuid' => $uuid,
            'bay_code' => (string) $chosen['bay_code'],
            'zone_name' => (string) $chosen['zone_name'],
            'starts_at' => AccessPresenter::iso($fromDb),
            'ends_at' => AccessPresenter::iso($toDb),
            'status' => 'reserved',
            'status_label' => ParkingPresenter::reservationLabel('reserved'),
            'status_tone' => ParkingPresenter::reservationTone('reserved'),
            '_reservation_id' => $reservationId,
            '_bay_id' => (int) $chosen['id'],
            '_plate_number' => $plate,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Session side effects (inside visitor check-in/out transaction)     */
    /* ------------------------------------------------------------------ */

    /**
     * Start a parking session for an invitation's `reserved` reservation.
     * MUST be called inside the caller's transaction. Returns null when the
     * invitation has no reserved parking (no side effect, no failure).
     *
     * @return array<string, mixed>|null
     */
    public function startSessionForInvitation(int $estateId, int $invitationId): ?array
    {
        $reservation = $this->parking->reservationForStayForUpdate($estateId, $invitationId);

        if ($reservation === null || (string) $reservation['status'] !== 'reserved') {
            return null;
        }

        $bay = $this->parking->bayForUpdate($estateId, (int) $reservation['bay_id']);

        if ($bay === null || !in_array((string) $bay['status'], ['available', 'reserved'], true)) {
            return null;
        }

        $now = Clock::nowDb();
        $sessionId = $this->parking->insertSession([
            'uuid' => $this->parking->uuid(),
            'estate_id' => $estateId,
            'bay_id' => (int) $reservation['bay_id'],
            'vehicle_id' => $reservation['vehicle_id'] === null ? null : (int) $reservation['vehicle_id'],
            'invitation_id' => $invitationId,
            'checkin_at' => $now,
            'checkout_at' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->parking->markReservationCheckedIn($estateId, (int) $reservation['id']);
        $this->parking->setBayStatus($estateId, (int) $reservation['bay_id'], 'occupied');

        $row = $this->parking->sessionById($estateId, $sessionId);

        return $row === null ? null : ParkingPresenter::session($row);
    }

    /**
     * Complete the invitation's active parking session and free its bay.
     * MUST be called inside the caller's transaction. Null when nothing active.
     *
     * @return array<string, mixed>|null
     */
    public function completeSessionForInvitation(int $estateId, int $invitationId): ?array
    {
        $session = $this->parking->activeSessionForInvitationForUpdate($estateId, $invitationId);

        if ($session === null) {
            return null;
        }

        $this->parking->completeSession($estateId, (int) $session['id']);
        $this->parking->markReservationCompletedForInvitation($estateId, $invitationId);
        $this->parking->setBayStatus($estateId, (int) $session['bay_id'], 'available');

        $row = $this->parking->sessionById($estateId, (int) $session['id']);

        return $row === null ? null : ParkingPresenter::session($row);
    }

    /* ------------------------------------------------------------------ */
    /* Guard parking endpoints                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function guardCheckin(string $invitationUuid): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $invitation = $this->parking->invitationByUuid($estateId, $invitationUuid);

        if ($invitation === null) {
            throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
        }

        $result = $this->db->transaction(function () use ($estateId, $invitation, $invitationUuid): array {
            $session = $this->startSessionForInvitation($estateId, (int) $invitation['id']);

            if ($session === null) {
                throw new HttpException(
                    409,
                    'NO_PARKING_RESERVATION',
                    'This invitation has no reserved parking to start.'
                );
            }

            $this->context->audit->record(
                'parking.session_started',
                'parking_session',
                null,
                [
                    'session_uuid' => (string) $session['session_uuid'],
                    'bay_code' => (string) $session['bay_code'],
                    'invitation_uuid' => $invitationUuid,
                ],
                $estateId
            );

            return $session;
        });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function guardCheckout(?string $invitationUuid, ?string $sessionUuid): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $invitationId = null;

        if ($invitationUuid !== null && $invitationUuid !== '') {
            $invitation = $this->parking->invitationByUuid($estateId, $invitationUuid);

            if ($invitation === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            $invitationId = (int) $invitation['id'];
        } elseif ($sessionUuid !== null && $sessionUuid !== '') {
            $sessionRow = $this->parking->sessionByUuid($estateId, $sessionUuid);

            if ($sessionRow === null || $sessionRow['invitation_id'] === null) {
                throw HttpException::notFound('NO_ACTIVE_SESSION', 'That parking session is not available.');
            }

            $invitationId = (int) $sessionRow['invitation_id'];
        }

        if ($invitationId === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Provide an invitation or session identifier.');
        }

        $resolvedInvitationId = $invitationId;

        $result = $this->db->transaction(function () use ($estateId, $resolvedInvitationId): array {
            $session = $this->completeSessionForInvitation($estateId, $resolvedInvitationId);

            if ($session === null) {
                throw new HttpException(409, 'NO_ACTIVE_SESSION', 'This visit has no active parking session.');
            }

            $this->context->audit->record(
                'parking.session_completed',
                'parking_session',
                null,
                [
                    'session_uuid' => (string) $session['session_uuid'],
                    'bay_code' => (string) $session['bay_code'],
                ],
                $estateId
            );

            return $session;
        });

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSessions(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        return array_map(
            [ParkingPresenter::class, 'session'],
            $this->parking->activeSessionsForEstate($estateId)
        );
    }

    /**
     * @param array<string, mixed> $reservation
     */
    public function auditReserved(array $reservation, int $estateId, int $invitationId): void
    {
        $this->context->audit->record(
            'parking.reserved',
            'parking_reservation',
            isset($reservation['_reservation_id']) ? (int) $reservation['_reservation_id'] : null,
            [
                'reservation_uuid' => (string) ($reservation['reservation_uuid'] ?? ''),
                'bay_code' => (string) ($reservation['bay_code'] ?? ''),
                'invitation_id' => $invitationId,
            ],
            $estateId
        );
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function resolveWindow(?string $fromInput, ?string $toInput, string $timezone): array
    {
        $now = Clock::now();
        $from = $this->parseMoment($fromInput, $timezone) ?? $now;
        $to = $this->parseMoment($toInput, $timezone) ?? $from->modify('+24 hours');

        if ($to <= $from) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'The availability window must end after it starts.');
        }

        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'The availability window is too long.');
        }

        return [$from, $to];
    }

    private function parseMoment(?string $value, string $timezone): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
                return (new DateTimeImmutable($value . ':00', new DateTimeZone($timezone)))->setTimezone(Clock::utc());
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
                return (new DateTimeImmutable($value . ':00', new DateTimeZone($timezone)))->setTimezone(Clock::utc());
            }

            return (new DateTimeImmutable($value))->setTimezone(Clock::utc());
        } catch (Throwable) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'Enter a valid availability window.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function householdIds(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $rows = $this->db->select(
            "SELECT household_id FROM household_members
             WHERE user_id = :user_id AND estate_id = :estate_id AND status = 'active'",
            ['user_id' => (int) $user['id'], 'estate_id' => $estateId]
        );

        return array_map(static fn (array $row): int => (int) $row['household_id'], $rows);
    }
}
