<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Database;
use App\Core\Uuid;

/**
 * Estate-scoped parking queries.
 *
 * Every read/write is constrained by the resolved active estate id. Bay
 * reservation conflict checks always consider both overlapping reservations
 * (`reserved`/`checked_in`) and active parking sessions.
 */
final class ParkingRepository
{
    private const BAY_SELECT = 'SELECT b.id, b.uuid, b.estate_id, b.zone_id, b.bay_code, b.bay_type, b.status,
                z.name AS zone_name
         FROM parking_bays b
         INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id';

    private const RESERVATION_SELECT = 'SELECT r.id, r.uuid, r.invitation_id, r.bay_id, r.vehicle_id,
                r.plate_number, r.starts_at, r.ends_at, r.status,
                i.uuid AS invitation_uuid, i.household_id,
                vis.name AS visitor_name,
                b.bay_code, z.name AS zone_name
         FROM parking_reservations r
         INNER JOIN visitor_invitations i ON i.id = r.invitation_id AND i.estate_id = r.estate_id
         INNER JOIN visitors vis ON vis.id = i.visitor_id AND vis.estate_id = i.estate_id
         INNER JOIN parking_bays b ON b.id = r.bay_id AND b.estate_id = r.estate_id
         INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id';

    private const SESSION_SELECT = 'SELECT s.id, s.uuid, s.bay_id, s.vehicle_id, s.invitation_id,
                s.checkin_at, s.checkout_at, s.status,
                b.bay_code, z.name AS zone_name,
                i.uuid AS invitation_uuid,
                vis.name AS visitor_name,
                COALESCE(r.plate_number, veh.plate_number) AS plate_number
         FROM parking_sessions s
         INNER JOIN parking_bays b ON b.id = s.bay_id AND b.estate_id = s.estate_id
         INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
         LEFT JOIN visitor_invitations i ON i.id = s.invitation_id AND i.estate_id = s.estate_id
         LEFT JOIN visitors vis ON vis.id = i.visitor_id AND vis.estate_id = i.estate_id
         LEFT JOIN parking_reservations r ON r.invitation_id = s.invitation_id AND r.estate_id = s.estate_id
         LEFT JOIN vehicles veh ON veh.id = s.vehicle_id AND veh.estate_id = s.estate_id';

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /* ------------------------------------------------------------------ */
    /* Bays + availability                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Candidate visitor bays locked for the reservation transaction, in
     * deterministic bay_code order to avoid deadlocks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visitorBaysForUpdate(int $estateId): array
    {
        return $this->db->select(
            self::BAY_SELECT . "
             WHERE b.estate_id = :estate_id
               AND b.bay_type IN ('visitor', 'accessible')
               AND b.status <> 'maintenance'
             ORDER BY b.bay_code ASC
             FOR UPDATE",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bayForUpdate(int $estateId, int $bayId): ?array
    {
        return $this->db->selectOne(
            self::BAY_SELECT . ' WHERE b.estate_id = :estate_id AND b.id = :bay_id LIMIT 1 FOR UPDATE',
            ['estate_id' => $estateId, 'bay_id' => $bayId]
        );
    }

    /**
     * Bays of the requested type with an availability flag for the window.
     * Maintenance bays are excluded (contract §2).
     *
     * @return array<int, array<string, mixed>>
     */
    public function baysWithAvailability(int $estateId, string $type, string $fromDb, string $toDb): array
    {
        $typeFilter = match ($type) {
            'resident' => "b.bay_type IN ('resident', 'accessible')",
            'service' => "b.bay_type = 'service'",
            'mixed' => "b.bay_type IN ('visitor', 'accessible', 'resident')",
            default => "b.bay_type IN ('visitor', 'accessible')",
        };

        return $this->db->select(
            'SELECT b.id, b.uuid, b.bay_code, b.bay_type, b.status, z.name AS zone_name,
                    CASE
                        WHEN b.status = \'maintenance\' THEN 0
                        WHEN EXISTS (
                            SELECT 1 FROM parking_reservations r
                            WHERE r.bay_id = b.id
                              AND r.estate_id = b.estate_id
                              AND r.status IN (\'reserved\', \'checked_in\')
                              AND r.starts_at < :window_to
                              AND r.ends_at > :window_from
                        ) THEN 0
                        WHEN EXISTS (
                            SELECT 1 FROM parking_sessions s
                            WHERE s.bay_id = b.id
                              AND s.estate_id = b.estate_id
                              AND s.status = \'active\'
                        ) THEN 0
                        ELSE 1
                    END AS available
             FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
             WHERE b.estate_id = :estate_id
               AND b.status <> \'maintenance\'
               AND ' . $typeFilter . '
             ORDER BY b.bay_code ASC',
            [
                'estate_id' => $estateId,
                'window_from' => $fromDb,
                'window_to' => $toDb,
            ]
        );
    }

    public function countAvailableBays(int $estateId, string $fromDb, string $toDb): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM parking_bays b
             WHERE b.estate_id = :estate_id
               AND b.bay_type IN ('visitor', 'accessible')
               AND b.status <> 'maintenance'
               AND NOT EXISTS (
                   SELECT 1 FROM parking_reservations r
                   WHERE r.bay_id = b.id
                     AND r.estate_id = b.estate_id
                     AND r.status IN ('reserved', 'checked_in')
                     AND r.starts_at < :window_to
                     AND r.ends_at > :window_from
               )
               AND NOT EXISTS (
                   SELECT 1 FROM parking_sessions s
                   WHERE s.bay_id = b.id
                     AND s.estate_id = b.estate_id
                     AND s.status = 'active'
               )",
            ['estate_id' => $estateId, 'window_from' => $fromDb, 'window_to' => $toDb]
        );
    }

    public function bayHasConflict(int $estateId, int $bayId, string $fromDb, string $toDb): bool
    {
        $reservation = $this->db->selectOne(
            "SELECT 1 AS conflict FROM parking_reservations
             WHERE estate_id = :estate_id
               AND bay_id = :bay_id
               AND status IN ('reserved', 'checked_in')
               AND starts_at < :window_to
               AND ends_at > :window_from
             LIMIT 1",
            [
                'estate_id' => $estateId,
                'bay_id' => $bayId,
                'window_from' => $fromDb,
                'window_to' => $toDb,
            ]
        );

        if ($reservation !== null) {
            return true;
        }

        return $this->db->selectOne(
            "SELECT 1 AS conflict FROM parking_sessions
             WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active'
             LIMIT 1",
            ['estate_id' => $estateId, 'bay_id' => $bayId]
        ) !== null;
    }

    public function setBayStatus(int $estateId, int $bayId, string $status): void
    {
        $this->db->execute(
            'UPDATE parking_bays SET status = :status, updated_at = :updated
             WHERE id = :id AND estate_id = :estate_id',
            ['status' => $status, 'updated' => \App\Core\Clock::nowDb(), 'id' => $bayId, 'estate_id' => $estateId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Reservations                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null
     */
    public function reservationForInvitation(int $estateId, int $invitationId): ?array
    {
        return $this->db->selectOne(
            self::RESERVATION_SELECT . '
             WHERE r.estate_id = :estate_id AND r.invitation_id = :invitation_id
             ORDER BY r.id DESC LIMIT 1',
            ['estate_id' => $estateId, 'invitation_id' => $invitationId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reservationForStayForUpdate(int $estateId, int $invitationId): ?array
    {
        return $this->db->selectOne(
            'SELECT r.id, r.uuid, r.bay_id, r.vehicle_id, r.plate_number, r.status, r.starts_at, r.ends_at
             FROM parking_reservations r
             WHERE r.estate_id = :estate_id AND r.invitation_id = :invitation_id
             ORDER BY r.id DESC LIMIT 1
             FOR UPDATE',
            ['estate_id' => $estateId, 'invitation_id' => $invitationId]
        );
    }

    /**
     * @param array<int, int> $householdIds
     * @return array<int, array<string, mixed>>
     */
    public function reservationsForHouseholds(int $estateId, array $householdIds, string $mode = 'all'): array
    {
        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $filter = $mode === 'upcoming'
            ? "AND r.status IN ('reserved', 'checked_in') AND r.ends_at >= ?"
            : '';

        $params = array_merge([$estateId], $householdIds);

        if ($mode === 'upcoming') {
            $params[] = \App\Core\Clock::nowDb();
        }

        $order = $mode === 'upcoming' ? 'ORDER BY r.starts_at ASC' : 'ORDER BY r.starts_at DESC';

        return $this->db->select(
            self::RESERVATION_SELECT . '
             WHERE r.estate_id = ?
               AND i.household_id IN (' . $placeholders . ')
               ' . $filter . '
             ' . $order . '
             LIMIT 50',
            $params
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertReservation(array $data): int
    {
        return $this->db->insert('parking_reservations', $data);
    }

    /* ------------------------------------------------------------------ */
    /* Allocations                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, int> $householdIds
     * @return array<int, array<string, mixed>>
     */
    public function allocationsForHouseholds(int $estateId, array $householdIds): array
    {
        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));

        return $this->db->select(
            "SELECT a.id, a.uuid, a.status, a.starts_at, a.ends_at,
                    b.bay_code, b.bay_type, z.name AS zone_name,
                    veh.plate_number AS vehicle_plate,
                    veh.vehicle_type AS vehicle_type,
                    veh.colour AS vehicle_colour
             FROM parking_allocations a
             INNER JOIN parking_bays b ON b.id = a.bay_id AND b.estate_id = a.estate_id
             INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
             LEFT JOIN vehicles veh ON veh.id = a.vehicle_id AND veh.estate_id = a.estate_id
             WHERE a.estate_id = ?
               AND a.household_id IN (" . $placeholders . ")
               AND a.status = 'active'
             ORDER BY b.bay_code ASC",
            array_merge([$estateId], array_values($householdIds))
        );
    }

    /* ------------------------------------------------------------------ */
    /* Sessions                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null
     */
    public function activeSessionForInvitationForUpdate(int $estateId, int $invitationId): ?array
    {
        return $this->db->selectOne(
            "SELECT id, uuid, bay_id, vehicle_id, invitation_id, checkin_at, checkout_at, status
             FROM parking_sessions
             WHERE estate_id = :estate_id AND invitation_id = :invitation_id AND status = 'active'
             ORDER BY id DESC LIMIT 1
             FOR UPDATE",
            ['estate_id' => $estateId, 'invitation_id' => $invitationId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sessionForUpdate(int $estateId, int $sessionId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, bay_id, vehicle_id, invitation_id, checkin_at, checkout_at, status
             FROM parking_sessions
             WHERE estate_id = :estate_id AND id = :id LIMIT 1 FOR UPDATE',
            ['estate_id' => $estateId, 'id' => $sessionId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sessionByUuid(int $estateId, string $sessionUuid): ?array
    {
        return $this->db->selectOne(
            self::SESSION_SELECT . ' WHERE s.estate_id = :estate_id AND s.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $sessionUuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sessionById(int $estateId, int $sessionId): ?array
    {
        return $this->db->selectOne(
            self::SESSION_SELECT . ' WHERE s.estate_id = :estate_id AND s.id = :id LIMIT 1',
            ['estate_id' => $estateId, 'id' => $sessionId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSessionsForEstate(int $estateId): array
    {
        return $this->db->select(
            self::SESSION_SELECT . "
             WHERE s.estate_id = :estate_id AND s.status = 'active'
             ORDER BY s.checkin_at ASC, s.id ASC
             LIMIT 100",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertSession(array $data): int
    {
        return $this->db->insert('parking_sessions', $data);
    }

    public function completeSession(int $estateId, int $sessionId): void
    {
        $this->db->execute(
            "UPDATE parking_sessions
             SET status = 'completed', checkout_at = :checkout_at, updated_at = :updated
             WHERE id = :id AND estate_id = :estate_id",
            [
                'checkout_at' => \App\Core\Clock::nowDb(),
                'updated' => \App\Core\Clock::nowDb(),
                'id' => $sessionId,
                'estate_id' => $estateId,
            ]
        );
    }

    public function markReservationCheckedIn(int $estateId, int $reservationId): void
    {
        $this->db->execute(
            "UPDATE parking_reservations
             SET status = 'checked_in', updated_at = :updated
             WHERE id = :id AND estate_id = :estate_id",
            ['updated' => \App\Core\Clock::nowDb(), 'id' => $reservationId, 'estate_id' => $estateId]
        );
    }

    public function markReservationCompletedForInvitation(int $estateId, int $invitationId): void
    {
        $this->db->execute(
            "UPDATE parking_reservations
             SET status = 'completed', updated_at = :updated
             WHERE invitation_id = :invitation_id AND estate_id = :estate_id AND status = 'checked_in'",
            ['updated' => \App\Core\Clock::nowDb(), 'invitation_id' => $invitationId, 'estate_id' => $estateId]
        );
    }

    /**
     * Cancel any still-reserved visit parking when an invitation is revoked.
     *
     * @return int Affected rows.
     */
    public function cancelReservedReservationsForInvitation(int $estateId, int $invitationId): int
    {
        return $this->db->execute(
            "UPDATE parking_reservations
             SET status = 'cancelled', updated_at = :updated
             WHERE invitation_id = :invitation_id AND estate_id = :estate_id AND status = 'reserved'",
            ['updated' => \App\Core\Clock::nowDb(), 'invitation_id' => $invitationId, 'estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invitationByUuid(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, household_id, status FROM visitor_invitations
             WHERE estate_id = :estate_id AND uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * Booking-side visitor bays (non-locking) with resolver-friendly shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visitorBays(int $estateId): array
    {
        return $this->db->select(
            self::BAY_SELECT . "
             WHERE b.estate_id = :estate_id AND b.bay_type IN ('visitor', 'accessible')
             ORDER BY b.bay_code ASC",
            ['estate_id' => $estateId]
        );
    }

    public function uuid(): string
    {
        return Uuid::v4();
    }
}
