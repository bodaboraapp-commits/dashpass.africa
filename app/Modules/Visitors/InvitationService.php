<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Uuid;
use App\Core\Validator;
use App\Modules\Parking\ParkingRepository;
use App\Modules\Parking\ParkingService;
use App\Modules\Vehicles\VehicleService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Visitor invitation lifecycle (contract §3, §4.1–§4.3).
 *
 * Credentials are issued here exactly once: a six-digit PIN stored only as a
 * context-bound HMAC, and an opaque 64-hex pass token stored as both an HMAC
 * (for lookup) and AES-256-GCM ciphertext (so the resident can re-display the
 * QR/pass link). No endpoint ever reveals an existing PIN again.
 *
 * All queries are estate-scoped from App\Core\Context; a browser-supplied
 * invitation uuid is only resolved through host/household membership, and a
 * foreign uuid is reported as 404 so it cannot be enumerated.
 */
final class InvitationService
{
    public const INVITATION_SELECT = 'SELECT i.id, i.uuid, i.estate_id, i.household_id, i.host_user_id,
                i.visitor_id, i.purpose, i.visitor_count, i.valid_from, i.valid_until, i.status,
                i.created_at, i.updated_at,
                v.name AS visitor_name, v.phone AS visitor_phone, v.uuid AS visitor_uuid,
                h.uuid AS household_uuid, h.unit_number, h.household_type,
                b.name AS building_name,
                e.name AS estate_name, e.slug AS estate_slug, e.timezone AS estate_timezone,
                host.full_name AS host_label, host.phone AS host_phone
         FROM visitor_invitations i
         INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
         INNER JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
         INNER JOIN estates e ON e.id = i.estate_id
         LEFT JOIN users host ON host.id = i.host_user_id';

    public function __construct(
        private readonly Context $context,
        private readonly UserHouseholdRepository $households,
        private readonly VisitorRepository $visitors,
        private readonly VisitorNotificationService $notifications,
        private readonly Database $db,
        private readonly RateLimiter $rateLimiter,
        private readonly VehicleService $vehicleService,
        private readonly ParkingService $parkingService,
        private readonly ParkingRepository $parkingRepository
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(
            $context,
            new UserHouseholdRepository($context->db),
            new VisitorRepository($context->db),
            VisitorNotificationService::make($context->db),
            $context->db,
            $context->rateLimiter,
            VehicleService::make($context),
            ParkingService::make($context),
            ParkingRepository::make($context->db)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Create (resident)                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Validate, create and notify a resident invitation.
     *
     * @param array<string, mixed> $input Raw form/JSON input.
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $userId = (int) $user['id'];
        $estateId = (int) $membership['estate_id'];

        $limit = $this->rateLimiter->attempt('visitor:create:user:' . $userId, 10, 600);

        if (!$limit['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many invitations created. Please wait a moment and try again.',
                $limit['retry_after']
            );
        }

        $clean = $this->validateCreateInput($input);
        $household = $this->households->findForUser($userId, (string) $clean['household_uuid'], $estateId);

        if ($household === null) {
            throw HttpException::notFound(
                'HOUSEHOLD_NOT_FOUND',
                'That household is not available for your account.'
            );
        }

        $timezone = (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi');
        $now = Clock::now();
        $validFrom = $this->parseMoment($clean['valid_from'] ?? null, $timezone) ?? $now;
        $validUntil = $this->parseMoment($clean['valid_until'] ?? null, $timezone)
            ?? $validFrom->modify('+' . $this->defaultValidityMinutes($estateId) . ' minutes');

        $this->assertValidity($validFrom, $validUntil, $now);

        // Increment 3: optional household vehicle + visitor parking reservation.
        $vehicle = null;
        $vehicleId = isset($clean['vehicle_id']) && is_string($clean['vehicle_id']) ? $clean['vehicle_id'] : null;

        if ($vehicleId !== null && $vehicleId !== '') {
            $vehicle = $this->vehicleService->findActiveForHousehold($estateId, (int) $household['id'], $vehicleId);

            if ($vehicle === null) {
                throw HttpException::notFound(
                    'VEHICLE_NOT_FOUND',
                    'That vehicle is not available for the selected household.'
                );
            }
        }

        $parkingRequired = (bool) ($clean['parking_required'] ?? false);

        $created = [];

        $this->db->transaction(function () use (
            $clean,
            $household,
            $userId,
            $estateId,
            $validFrom,
            $validUntil,
            $membership,
            $vehicle,
            $parkingRequired,
            &$created
        ): void {
            $created = $this->prepareInvitation(
                (string) $clean['visitor_name'],
                (string) $clean['visitor_phone'],
                (int) $household['id'],
                isset($clean['purpose']) && $clean['purpose'] !== null ? (string) $clean['purpose'] : null,
                (int) ($clean['visitor_count'] ?? 1),
                $validFrom,
                $validUntil,
                $userId,
                $membership
            );

            if ($parkingRequired) {
                // Same transaction as the invitation + credentials: when no bay
                // is free this throws 409 NO_PARKING_AVAILABLE and the whole
                // invitation rolls back (no partial promise).
                $created['_parking'] = $this->parkingService->reserveForInvitation(
                    (int) $created['_invitation_id'],
                    $estateId,
                    $validFrom,
                    $validUntil,
                    $vehicle
                );
            }
        });

        $this->context->audit->record(
            'visitor.invitation.created',
            'visitor_invitation',
            (int) $created['_invitation_id'],
            [
                'invitation_uuid' => (string) $created['invitation_uuid'],
                'visitor_name' => (string) $created['visitor']['name'],
                'household_label' => (string) $created['_household_label'],
            ],
            $estateId
        );

        $this->notifications->invitation(
            $estateId,
            (string) $created['visitor']['phone'],
            (string) $created['_estate_name'],
            (string) $created['_household_label'],
            (string) $created['_pin'],
            (string) $created['_pass_url'],
            (string) $created['_valid_from_db'],
            (string) $created['_valid_until_db'],
            (string) $created['_timezone']
        );

        if (isset($created['_parking']) && is_array($created['_parking'])) {
            $this->parkingService->auditReserved($created['_parking'], $estateId, (int) $created['_invitation_id']);

            $this->notifications->parkingConfirmed(
                $estateId,
                (string) $created['visitor']['phone'],
                (string) $created['_parking']['bay_code'],
                (string) $created['_parking']['zone_name'],
                (string) $created['_valid_from_db'],
                (string) $created['_valid_until_db'],
                (string) $created['_timezone']
            );
        }

        return $created;
    }

    /**
     * Insert an invitation + both credentials inside an existing transaction.
     * Used by {@see WalkInService} so the approval and its invitation commit
     * atomically. The caller owns the transaction, audit and SMS.
     *
     * @param array<string, mixed> $membership TenantContext::active() row.
     * @return array<string, mixed>
     */
    public function createInTransaction(
        string $visitorName,
        string $visitorPhone,
        int $householdId,
        ?string $purpose,
        int $visitorCount,
        DateTimeImmutable $validFrom,
        DateTimeImmutable $validUntil,
        int $hostUserId,
        array $membership
    ): array {
        return $this->prepareInvitation(
            $visitorName,
            $visitorPhone,
            $householdId,
            $purpose,
            $visitorCount,
            $validFrom,
            $validUntil,
            $hostUserId,
            $membership
        );
    }

    /**
     * Public create payload (contract §6).
     *
     * @param array<string, mixed> $created
     * @return array<string, mixed>
     */
    public static function publicCreateData(array $created): array
    {
        return [
            'invitation_uuid' => (string) $created['invitation_uuid'],
            'pin' => (string) $created['_pin'],
            'pass_url' => (string) $created['_pass_url'],
            'qr_url' => (string) $created['qr_url'],
            'valid_from' => (string) $created['valid_from'],
            'valid_until' => (string) $created['valid_until'],
            'visitor' => [
                'name' => (string) $created['visitor']['name'],
                'phone' => (string) $created['visitor']['phone'],
            ],
            'parking' => self::parkingPublic($created),
        ];
    }

    /**
     * Reservation-lite shape for create responses/pass screens (§3 frozen #5/#7).
     *
     * @param array<string, mixed> $created
     * @return array<string, mixed>|null
     */
    public static function parkingPublic(array $created): ?array
    {
        $parking = $created['_parking'] ?? null;

        if (!is_array($parking)) {
            return null;
        }

        return [
            'reservation_uuid' => (string) ($parking['reservation_uuid'] ?? ''),
            'bay_code' => (string) ($parking['bay_code'] ?? ''),
            'zone_name' => (string) ($parking['zone_name'] ?? ''),
            'starts_at' => (string) ($parking['starts_at'] ?? ''),
            'ends_at' => (string) ($parking['ends_at'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Lists + lookups                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Resident upcoming/history lists (contract §4.2).
     *
     * @return array{tab: string, upcoming: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    public function listForResident(string $tab): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $userId = (int) $user['id'];
        $estateId = (int) $membership['estate_id'];
        $householdIds = $this->households->idsForUser($userId, $estateId);

        if ($householdIds === []) {
            return ['tab' => $tab, 'upcoming' => [], 'history' => []];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $params = array_merge([$estateId], $householdIds);

        $upcomingRows = $this->db->select(
            self::INVITATION_SELECT . '
             WHERE i.estate_id = ?
               AND i.status IN (\'approved\', \'checked_in\')
               AND i.household_id IN (' . $placeholders . ')
             ORDER BY i.valid_from ASC, i.id ASC',
            $params
        );

        $historyRows = $this->db->select(
            self::INVITATION_SELECT . '
             WHERE i.estate_id = ?
               AND i.status IN (\'checked_out\', \'revoked\', \'expired\', \'denied\')
               AND i.household_id IN (' . $placeholders . ')
             ORDER BY i.valid_until DESC, i.id DESC
             LIMIT 50',
            $params
        );

        return [
            'tab' => $tab,
            'upcoming' => array_map([AccessPresenter::class, 'item'], $upcomingRows),
            'history' => array_map([AccessPresenter::class, 'item'], $historyRows),
        ];
    }

    /**
     * The resident's next usable pass, for the home QR sheet (contract §7.1).
     *
     * @return array<string, mixed>|null
     */
    public function nextPassForResident(): ?array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $householdIds = $this->households->idsForUser((int) $user['id'], (int) $membership['estate_id']);

        if ($householdIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $row = $this->db->selectOne(
            self::INVITATION_SELECT . '
             WHERE i.estate_id = ?
               AND i.status = \'approved\'
               AND i.valid_until >= ?
               AND i.household_id IN (' . $placeholders . ')
             ORDER BY i.valid_from ASC, i.id ASC
             LIMIT 1',
            array_merge([(int) $membership['estate_id'], Clock::nowDb()], $householdIds)
        );

        if ($row === null) {
            return null;
        }

        return [
            'invitation_uuid' => (string) $row['uuid'],
            'visitor_name' => (string) $row['visitor_name'],
            'pass_url' => $this->passUrlFor($row) ?? '',
            'valid_until' => AccessPresenter::iso((string) $row['valid_until']),
            'status' => (string) $row['status'],
            'status_label' => AccessPresenter::statusLabel((string) $row['status']),
            'status_tone' => AccessPresenter::statusTone((string) $row['status']),
            'household_label' => AccessPresenter::householdLabel($row),
        ];
    }

    /**
     * Resolve an invitation for the authenticated resident, enforcing that the
     * caller is the host or an active member of its household.
     *
     * @return array<string, mixed>
     */
    public function findForResident(string $invitationUuid): array
    {
        $row = $this->findInEstate($invitationUuid);

        if ($row === null || !$this->canAccess($row)) {
            throw HttpException::notFound(
                'INVITATION_NOT_FOUND',
                'That visitor invitation is not available for your account.'
            );
        }

        return $row;
    }

    /**
     * Resident pass data (contract §7.1): Item + estate/host/visitor metadata
     * plus QR and pass URLs.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function passData(array $row): array
    {
        return AccessPresenter::pass($row) + [
            'qr_url' => url('/app/passes/' . (string) $row['uuid'] . '/qr.png'),
            'pass_url' => $this->passUrlFor($row) ?? '',
            'parking' => $this->parkingService->passParkingForInvitation((int) $row['estate_id'], (int) $row['id']),
        ];
    }

    /**
     * QR PNG for a resident pass. The QR encodes the public pass URL (never the
     * PIN or any personal data) and is rendered server-side.
     */
    public function qrPng(string $invitationUuid): string
    {
        $row = $this->findForResident($invitationUuid);
        $token = $this->passTokenFor($row);

        if ($token === null) {
            throw HttpException::notFound(
                'INVITATION_NOT_FOUND',
                'That visitor invitation is not available for your account.'
            );
        }

        return QrCode::png(url('/v/' . $token));
    }

    /* ------------------------------------------------------------------ */
    /* Revoke / extend / rotate PIN                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{status: string}
     */
    public function revoke(string $invitationUuid): array
    {
        $row = $this->findForResident($invitationUuid);
        $estateId = (int) $row['estate_id'];
        $now = Clock::nowDb();
        $newStatus = 'revoked';

        $this->db->transaction(function () use ($row, $estateId, $now, &$newStatus): void {
            $locked = $this->lockInvitation((int) $row['id'], $estateId);

            if ($locked === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            $current = (string) $locked['status'];

            if ($current === 'checked_out' || $current === 'revoked') {
                throw new HttpException(409, 'INVITATION_NOT_ACTIVE', 'This invitation is no longer active.');
            }

            if ($current === 'checked_in') {
                // The visit stays manageable, but re-entry must be impossible.
                $newStatus = 'checked_in';
            } elseif ($current !== 'approved') {
                throw new HttpException(409, 'INVITATION_NOT_ACTIVE', 'This invitation is no longer active.');
            }

            if ($newStatus === 'revoked') {
                $this->db->execute(
                    'UPDATE visitor_invitations SET status = :status, updated_at = :updated WHERE id = :id',
                    ['status' => 'revoked', 'updated' => $now, 'id' => (int) $locked['id']]
                );
            }

            $this->db->execute(
                "UPDATE access_credentials
                 SET status = 'revoked', revoked_at = :revoked_at
                 WHERE invitation_id = :invitation_id
                   AND estate_id = :estate_id
                   AND status IN ('active', 'used')",
                ['revoked_at' => $now, 'invitation_id' => (int) $locked['id'], 'estate_id' => $estateId]
            );
        });

        $this->context->audit->record(
            'visitor.invitation.revoked',
            'visitor_invitation',
            (int) $row['id'],
            [
                'invitation_uuid' => (string) $row['uuid'],
                'previous_status' => (string) $row['status'],
                'resulting_status' => $newStatus,
            ],
            $estateId
        );

        $cancelled = $this->parkingRepository->cancelReservedReservationsForInvitation($estateId, (int) $row['id']);

        if ($cancelled > 0) {
            $this->context->audit->record(
                'parking.reservation_cancelled',
                'parking_reservation',
                null,
                ['invitation_uuid' => (string) $row['uuid'], 'cancelled' => $cancelled],
                $estateId
            );
        }

        return ['status' => $newStatus];
    }

    /**
     * @return array{valid_until: ?string}
     */
    public function extend(string $invitationUuid, int $minutes): array
    {
        if ($minutes < 15 || $minutes > 1440) {
            throw new HttpException(
                422,
                'VALIDATION_FAILED',
                'Extend by between 15 and 1440 minutes.',
                ['errors' => ['minutes' => ['Extend by between 15 and 1440 minutes.']]]
            );
        }

        $row = $this->findForResident($invitationUuid);
        $estateId = (int) $row['estate_id'];
        $maxHours = max(1, (int) Config::get('visitors.invitation.max_validity_hours', 168));
        $newValidUntil = null;

        $this->db->transaction(function () use ($row, $estateId, $minutes, $maxHours, &$newValidUntil): void {
            $locked = $this->lockInvitation((int) $row['id'], $estateId);

            if ($locked === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            $status = (string) $locked['status'];

            if ($status !== 'approved' && $status !== 'checked_in') {
                throw new HttpException(409, 'INVITATION_NOT_ACTIVE', 'This invitation is no longer active.');
            }

            $now = Clock::now();
            $currentUntil = Clock::fromDb((string) $locked['valid_until']) ?? $now;
            $base = $currentUntil > $now ? $currentUntil : $now;
            $candidate = $base->modify('+' . $minutes . ' minutes');
            $validFrom = Clock::fromDb((string) $locked['valid_from']) ?? $now;
            $windowSeconds = $candidate->getTimestamp() - $validFrom->getTimestamp();

            if ($windowSeconds > $maxHours * 3600) {
                throw new HttpException(
                    422,
                    'VALIDITY_INVALID',
                    'The extended pass would exceed the maximum validity window.'
                );
            }

            $newValidUntil = $candidate;

            $this->db->execute(
                'UPDATE visitor_invitations SET valid_until = :valid_until, updated_at = :updated WHERE id = :id',
                ['valid_until' => Clock::toDb($candidate), 'updated' => Clock::nowDb(), 'id' => (int) $locked['id']]
            );

            $this->db->execute(
                "UPDATE access_credentials
                 SET expires_at = :expires_at
                 WHERE invitation_id = :invitation_id
                   AND estate_id = :estate_id
                   AND status <> 'revoked'",
                [
                    'expires_at' => Clock::toDb($candidate),
                    'invitation_id' => (int) $locked['id'],
                    'estate_id' => $estateId,
                ]
            );
        });

        $this->context->audit->record(
            'visitor.invitation.extended',
            'visitor_invitation',
            (int) $row['id'],
            [
                'invitation_uuid' => (string) $row['uuid'],
                'minutes' => $minutes,
                'valid_until' => $newValidUntil === null ? null : AccessPresenter::iso(Clock::toDb($newValidUntil)),
            ],
            $estateId
        );

        return ['valid_until' => $newValidUntil === null ? null : AccessPresenter::iso(Clock::toDb($newValidUntil))];
    }

    /**
     * @return array{pin: string}
     */
    public function rotatePin(string $invitationUuid): array
    {
        $row = $this->findForResident($invitationUuid);
        $estateId = (int) $row['estate_id'];
        $pin = null;

        $this->db->transaction(function () use ($row, $estateId, &$pin): void {
            $locked = $this->lockInvitation((int) $row['id'], $estateId);

            if ($locked === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            if ((string) $locked['status'] !== 'approved') {
                throw new HttpException(409, 'INVITATION_NOT_ACTIVE', 'Only an active invitation PIN can be rotated.');
            }

            $now = Clock::nowDb();

            $this->db->execute(
                "UPDATE access_credentials
                 SET status = 'revoked', revoked_at = :revoked_at
                 WHERE invitation_id = :invitation_id
                   AND estate_id = :estate_id
                   AND credential_type = 'pin'
                   AND status = 'active'",
                ['revoked_at' => $now, 'invitation_id' => (int) $locked['id'], 'estate_id' => $estateId]
            );

            $pin = $this->issuePinCredential(
                (int) $locked['id'],
                (int) $locked['estate_id'],
                (string) $locked['valid_until'],
                $now
            );
        });

        $this->context->audit->record(
            'visitor.invitation.pin_rotated',
            'visitor_invitation',
            (int) $row['id'],
            ['invitation_uuid' => (string) $row['uuid']],
            $estateId
        );

        return ['pin' => (string) $pin];
    }

    /* ------------------------------------------------------------------ */
    /* Public pass lookup                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Find an invitation by its opaque QR pass token.
     *
     * The schema stores only a context-bound HMAC of the token, so the lookup
     * compares the submitted token against each QR credential of this estate
     * class. There is no plaintext token column.
     *
     * @return array<string, mixed>|null
     */
    public function findByPassToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $rows = $this->db->select(
            'SELECT c.id AS credential_id, c.uuid AS credential_uuid, c.credential_hash,
                    c.status AS credential_status, c.expires_at AS credential_expires_at,
                    c.revoked_at AS credential_revoked_at,
                    i.id, i.uuid, i.estate_id, i.household_id, i.host_user_id, i.purpose,
                    i.visitor_count, i.valid_from, i.valid_until, i.status, i.created_at,
                    v.name AS visitor_name,
                    h.uuid AS household_uuid, h.unit_number, h.household_type,
                    b.name AS building_name,
                    e.name AS estate_name, e.timezone AS estate_timezone
             FROM access_credentials c
             INNER JOIN visitor_invitations i ON i.id = c.invitation_id AND i.estate_id = c.estate_id
             INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
             INNER JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             INNER JOIN estates e ON e.id = i.estate_id
             WHERE c.credential_type = \'qr\''
        );

        foreach ($rows as $row) {
            $expected = Crypto::hmac('access-qr:' . (string) $row['credential_uuid'], $token);

            if (Crypto::equals((string) $row['credential_hash'], $expected)) {
                return $row;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Insert visitor + invitation + PIN/QR credentials inside the caller's
     * transaction.
     *
     * @param array<string, mixed> $membership
     * @return array<string, mixed>
     */
    private function prepareInvitation(
        string $visitorName,
        string $visitorPhone,
        int $householdId,
        ?string $purpose,
        int $visitorCount,
        DateTimeImmutable $validFrom,
        DateTimeImmutable $validUntil,
        int $hostUserId,
        array $membership
    ): array {
        $estateId = (int) $membership['estate_id'];
        $now = Clock::nowDb();

        $visitor = $this->visitors->findOrCreate($estateId, $visitorPhone, $visitorName);

        $invitationUuid = Uuid::v4();
        $invitationId = $this->db->insert('visitor_invitations', [
            'uuid' => $invitationUuid,
            'estate_id' => $estateId,
            'household_id' => $householdId,
            'host_user_id' => $hostUserId,
            'visitor_id' => $visitor['id'],
            'purpose' => $purpose,
            'visitor_count' => max(1, $visitorCount),
            'valid_from' => Clock::toDb($validFrom),
            'valid_until' => Clock::toDb($validUntil),
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pin = $this->issuePinCredential($invitationId, $estateId, Clock::toDb($validUntil), $now);
        $token = $this->issueQrCredential($invitationId, $estateId, Clock::toDb($validUntil), $now);

        // The invitation's household label comes from the resolved household row.
        $label = $this->householdLabelById($householdId, $estateId);

        return [
            'invitation_uuid' => $invitationUuid,
            'pin' => $pin,
            'pass_url' => url('/v/' . $token),
            'qr_url' => url('/app/passes/' . $invitationUuid . '/qr.png'),
            'valid_from' => AccessPresenter::iso(Clock::toDb($validFrom)),
            'valid_until' => AccessPresenter::iso(Clock::toDb($validUntil)),
            'status' => 'approved',
            'visitor' => [
                'name' => $visitor['name'],
                'phone' => $visitor['phone'],
            ],
            '_invitation_id' => $invitationId,
            '_estate_id' => $estateId,
            '_household_label' => $label,
            '_estate_name' => (string) ($membership['estate_name'] ?? ''),
            '_timezone' => (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi'),
            '_pin' => $pin,
            '_pass_url' => url('/v/' . $token),
            '_pass_token' => $token,
            '_valid_from_db' => Clock::toDb($validFrom),
            '_valid_until_db' => Clock::toDb($validUntil),
            '_host_user_id' => $hostUserId,
        ];
    }

    private function issuePinCredential(int $invitationId, int $estateId, string $expiresAtDb, string $nowDb): string
    {
        $length = max(4, (int) Config::get('visitors.invitation.default_pin_length', 6));
        $pin = Crypto::numericCode($length);
        $credentialUuid = Uuid::v4();

        $this->db->insert('access_credentials', [
            'uuid' => $credentialUuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'credential_hash' => Crypto::hmac('access-pin:' . $credentialUuid, $pin),
            'credential_type' => 'pin',
            'encrypted_secret' => null,
            'expires_at' => $expiresAtDb,
            'max_uses' => $this->maxUses(),
            'used_count' => 0,
            'status' => 'active',
            'created_at' => $nowDb,
        ]);

        return $pin;
    }

    private function issueQrCredential(int $invitationId, int $estateId, string $expiresAtDb, string $nowDb): string
    {
        $bytes = max(16, min(64, (int) Config::get('visitors.pass.token_bytes', 32)));
        $token = Crypto::randomToken($bytes);
        $credentialUuid = Uuid::v4();

        $this->db->insert('access_credentials', [
            'uuid' => $credentialUuid,
            'estate_id' => $estateId,
            'invitation_id' => $invitationId,
            'credential_hash' => Crypto::hmac('access-qr:' . $credentialUuid, $token),
            'credential_type' => 'qr',
            'encrypted_secret' => Crypto::encrypt($token, 'access-qr:' . $credentialUuid),
            'expires_at' => $expiresAtDb,
            'max_uses' => $this->maxUses(),
            'used_count' => 0,
            'status' => 'active',
            'created_at' => $nowDb,
        ]);

        return $token;
    }

    private function maxUses(): int
    {
        return max(1, min(10, (int) Config::get('visitors.invitation.default_max_uses', 1)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInEstate(string $invitationUuid): ?array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        return $this->db->selectOne(
            self::INVITATION_SELECT . ' WHERE i.estate_id = :estate_id AND i.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $invitationUuid]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canAccess(array $row): bool
    {
        $userId = (int) $this->context->auth->requireUser()['id'];
        $estateId = (int) $row['estate_id'];

        if ((int) $row['host_user_id'] === $userId) {
            return true;
        }

        return $this->households->isMember($userId, (int) $row['household_id'], $estateId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockInvitation(int $invitationId, int $estateId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, estate_id, household_id, valid_from, valid_until, status
             FROM visitor_invitations
             WHERE id = :id AND estate_id = :estate_id
             LIMIT 1
             FOR UPDATE',
            ['id' => $invitationId, 'estate_id' => $estateId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function passTokenFor(array $row): ?string
    {
        $credential = $this->db->selectOne(
            "SELECT uuid, encrypted_secret FROM access_credentials
             WHERE invitation_id = :invitation_id AND estate_id = :estate_id AND credential_type = 'qr'
             ORDER BY id DESC LIMIT 1",
            ['invitation_id' => (int) $row['id'], 'estate_id' => (int) $row['estate_id']]
        );

        if ($credential === null) {
            return null;
        }

        return Crypto::decrypt(
            $credential['encrypted_secret'] === null ? null : (string) $credential['encrypted_secret'],
            'access-qr:' . (string) $credential['uuid']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function passUrlFor(array $row): ?string
    {
        $token = $this->passTokenFor($row);

        return $token === null ? null : url('/v/' . $token);
    }

    private function householdLabelById(int $householdId, int $estateId): string
    {
        $row = $this->db->selectOne(
            'SELECT h.unit_number, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.id = :id AND h.estate_id = :estate_id
             LIMIT 1',
            ['id' => $householdId, 'estate_id' => $estateId]
        );

        return $row === null ? '' : AccessPresenter::householdLabel($row);
    }

    private function defaultValidityMinutes(int $estateId): int
    {
        $row = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings
             WHERE estate_id = :estate_id AND setting_key = :setting_key
             LIMIT 1',
            ['estate_id' => $estateId, 'setting_key' => 'visitor_validity_minutes']
        );

        if ($row !== null && $row['setting_value'] !== null) {
            $decoded = json_decode((string) $row['setting_value'], true);

            if (is_numeric($decoded)) {
                return max(1, (int) $decoded);
            }
        }

        return max(1, (int) Config::get('visitors.invitation.default_validity_minutes', 240));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateCreateInput(array $input): array
    {
        return Validator::validate(
            $input,
            [
                'visitor_name' => 'required|string|min:2|max:180',
                'visitor_phone' => 'required|phone',
                'household_uuid' => 'required|uuid',
                'purpose' => 'nullable|string|max:120',
                'valid_from' => 'nullable|string',
                'valid_until' => 'nullable|string',
                'visitor_count' => 'nullable|integer|between:1,' . max(1, (int) Config::get('visitors.invitation.visitor_count_max', 10)),
                'vehicle_id' => 'nullable|uuid',
                'parking_required' => 'nullable|boolean',
            ],
            [
                'visitor_name' => 'visitor name',
                'visitor_phone' => 'visitor phone number',
                'household_uuid' => 'household',
                'purpose' => 'purpose',
                'valid_from' => 'valid from',
                'valid_until' => 'valid until',
                'visitor_count' => 'visitor count',
                'vehicle_id' => 'vehicle',
                'parking_required' => 'visitor parking option',
            ]
        );
    }

    private function parseMoment(?string $value, string $timezone): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            // datetime-local form value (estate-local wall clock).
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
                return (new DateTimeImmutable($value . ':00', new DateTimeZone($timezone)))->setTimezone(Clock::utc());
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
                return (new DateTimeImmutable($value . ':00', new DateTimeZone($timezone)))->setTimezone(Clock::utc());
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return (new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone($timezone)))->setTimezone(Clock::utc());
            }

            return (new DateTimeImmutable($value))->setTimezone(Clock::utc());
        } catch (Throwable) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'Enter a valid date and time for the visit window.');
        }
    }

    private function assertValidity(DateTimeImmutable $validFrom, DateTimeImmutable $validUntil, DateTimeImmutable $now): void
    {
        if ($validFrom >= $validUntil) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'The visit must end after it starts.');
        }

        if ($validUntil <= $now) {
            throw new HttpException(422, 'VALIDITY_INVALID', 'The visit must end in the future.');
        }

        $maxHours = max(1, (int) Config::get('visitors.invitation.max_validity_hours', 168));

        if ($validUntil->getTimestamp() - $validFrom->getTimestamp() > $maxHours * 3600) {
            throw new HttpException(
                422,
                'VALIDITY_INVALID',
                'The visit window is longer than the maximum allowed duration.'
            );
        }
    }
}
