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
use App\Core\Session;
use App\Core\Uuid;

/**
 * Guard-side verification and gate operations (contract §4.4, §4.5).
 *
 * Gate context comes from the server-side session (`guard_gate_id`) and is
 * re-validated against an active `guard_gate_assignments` row on every call; a
 * browser-supplied gate uuid is never trusted. Verification attempts are always
 * recorded, and the public error is deliberately generic.
 */
final class AccessService
{
    private const VERIFY_CANDIDATE_SELECT = 'SELECT c.id AS credential_id, c.uuid AS credential_uuid,
                c.credential_hash, c.credential_type, c.status AS credential_status,
                c.expires_at AS credential_expires_at, c.max_uses, c.used_count,
                i.id, i.uuid AS invitation_uuid, i.estate_id, i.household_id, i.status AS invitation_status,
                i.valid_from, i.valid_until, i.purpose,
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
         WHERE c.estate_id = :estate_id
           AND c.credential_type = :credential_type
           AND c.status IN (\'active\', \'used\')';

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly RateLimiter $rateLimiter,
        private readonly VisitorNotificationService $notifications
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(
            $context,
            $context->db,
            $context->rateLimiter,
            VisitorNotificationService::make($context->db)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Gate context                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The gate selected for this guard session, validated against an active
     * assignment. No gate selected -> 409; gate not assigned -> 403.
     *
     * @return array{id: int, uuid: string, name: string}
     */
    public function resolveGate(): array
    {
        $selected = Session::get('guard_gate_id');
        $gate = $this->currentGate();

        if ($gate !== null) {
            return $gate;
        }

        if ($selected !== null) {
            throw new HttpException(
                403,
                'GATE_NOT_ASSIGNED',
                'You are not assigned to the selected gate.'
            );
        }

        throw new HttpException(
            409,
            'GATE_CONTEXT_REQUIRED',
            'Select a gate before verifying visitors.'
        );
    }

    /**
     * The selected gate when it is still valid, otherwise null (dashboard use).
     *
     * @return array{id: int, uuid: string, name: string}|null
     */
    public function currentGate(): ?array
    {
        $selected = Session::get('guard_gate_id');

        if (!is_int($selected) && !(is_string($selected) && ctype_digit($selected))) {
            return null;
        }

        $user = $this->context->auth->user();

        if ($user === null) {
            return null;
        }

        $membership = $this->context->tenant->active();

        if ($membership === null) {
            return null;
        }

        $gate = $this->db->selectOne(
            "SELECT g.id, g.uuid, g.name
             FROM gates g
             INNER JOIN guard_gate_assignments a
                     ON a.gate_id = g.id
                    AND a.estate_id = g.estate_id
                    AND a.guard_user_id = :user_id
                    AND a.status = 'active'
             WHERE g.id = :gate_id
               AND g.estate_id = :estate_id
               AND g.status = 'active'
             LIMIT 1",
            [
                'user_id' => (int) $user['id'],
                'gate_id' => (int) $selected,
                'estate_id' => (int) $membership['estate_id'],
            ]
        );

        if ($gate === null) {
            Session::forget('guard_gate_id');

            return null;
        }

        return ['id' => (int) $gate['id'], 'uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']];
    }

    /**
     * Select a gate for this guard session. Unknown/unassigned gates are 403 so
     * gates cannot be enumerated.
     *
     * @return array{id: int, uuid: string, name: string}
     */
    public function selectGate(string $gateUuid): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();

        $gate = $this->db->selectOne(
            "SELECT g.id, g.uuid, g.name
             FROM gates g
             INNER JOIN guard_gate_assignments a
                     ON a.gate_id = g.id
                    AND a.estate_id = g.estate_id
                    AND a.guard_user_id = :user_id
                    AND a.status = 'active'
             WHERE g.uuid = :uuid
               AND g.estate_id = :estate_id
               AND g.status = 'active'
             LIMIT 1",
            [
                'user_id' => (int) $user['id'],
                'uuid' => $gateUuid,
                'estate_id' => (int) $membership['estate_id'],
            ]
        );

        if ($gate === null) {
            throw new HttpException(
                403,
                'GATE_NOT_ASSIGNED',
                'You are not assigned to that gate.'
            );
        }

        Session::put('guard_gate_id', (int) $gate['id']);

        return ['id' => (int) $gate['id'], 'uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']];
    }

    /**
     * Gates in the estate with an assignment flag for this guard.
     *
     * @return array<int, array{uuid: string, name: string, assigned: bool}>
     */
    public function gateOptions(): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();

        $rows = $this->db->select(
            "SELECT g.uuid, g.name,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS assigned
             FROM gates g
             LEFT JOIN guard_gate_assignments a
                    ON a.gate_id = g.id
                   AND a.estate_id = g.estate_id
                   AND a.guard_user_id = :user_id
                   AND a.status = 'active'
             WHERE g.estate_id = :estate_id
               AND g.status = 'active'
             ORDER BY g.name ASC",
            ['user_id' => (int) $user['id'], 'estate_id' => (int) $membership['estate_id']]
        );

        return array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'name' => (string) $row['name'],
                'assigned' => (bool) $row['assigned'],
            ],
            $rows
        );
    }

    /* ------------------------------------------------------------------ */
    /* Verification                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Verify a six-digit PIN.
     *
     * @return array<string, mixed>
     */
    public function verifyPin(string $pin): array
    {
        return $this->verifyCredential('pin', trim($pin));
    }

    /**
     * Verify a pass code: either the raw 64-char token or a full pass URL that
     * contains `/v/{token}`.
     *
     * @return array<string, mixed>
     */
    public function verifyCode(string $code): array
    {
        $code = trim($code);
        $position = strpos($code, '/v/');

        if ($position !== false) {
            $code = substr($code, $position + 3);
        }

        $code = rtrim(explode('?', $code, 2)[0], '/');

        if (preg_match('/^[0-9a-f]{64}$/', $code) !== 1) {
            $this->recordAttempt('qr', null, false);
            $this->auditVerificationFailed('qr');

            // Contract §6: INVALID_CREDENTIAL is 422 (not a 400 request error).
            // Malformed input is deliberately indistinguishable from an unknown
            // credential so the response never reveals which check failed.
            throw new HttpException(422, 'INVALID_CREDENTIAL', 'The access credential is invalid.');
        }

        return $this->verifyCredential('qr', $code);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyCredential(string $type, string $plaintext): array
    {
        $gate = $this->resolveGate();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];

        $credentialMax = max(1, (int) Config::get('visitors.verification.pin_max_attempts', 5));
        $credentialWindow = max(1, (int) Config::get('visitors.verification.pin_window_seconds', 300));
        $ipMax = max(1, (int) Config::get('visitors.verification.ip_max_attempts', 30));
        $ipWindow = max(1, (int) Config::get('visitors.verification.ip_window_seconds', 300));
        $plainHash = hash('sha256', $type . '|' . $plaintext);

        foreach ([
            ['visitor:verify:credential:' . $plainHash, $credentialMax, $credentialWindow],
            ['visitor:verify:ip:' . $this->context->request->ip(), $ipMax, $ipWindow],
        ] as [$key, $max, $window]) {
            $result = $this->rateLimiter->attempt($key, $max, $window);

            if (!$result['allowed']) {
                throw HttpException::tooManyRequests(
                    'Too many verification attempts. Please wait and try again.',
                    $result['retry_after']
                );
            }
        }

        $candidates = $this->db->select(self::VERIFY_CANDIDATE_SELECT, [
            'estate_id' => $estateId,
            'credential_type' => $type,
        ]);

        $matched = null;
        $prefix = $type === 'pin' ? 'access-pin:' : 'access-qr:';

        foreach ($candidates as $candidate) {
            $expected = Crypto::hmac($prefix . (string) $candidate['credential_uuid'], $plaintext);

            if (Crypto::equals((string) $candidate['credential_hash'], $expected)) {
                $matched = $candidate;
                break;
            }
        }

        if ($matched === null) {
            $this->recordAttempt($type, null, false, $gate);
            $this->auditVerificationFailed($type);

            throw new HttpException(422, 'INVALID_CREDENTIAL', 'The access credential is invalid.');
        }

        $now = Clock::now();
        $credentialStatus = (string) $matched['credential_status'];
        $invitationStatus = (string) $matched['invitation_status'];
        $credentialExpires = Clock::fromDb(
            $matched['credential_expires_at'] === null ? null : (string) $matched['credential_expires_at']
        );
        $alreadyInside = false;

        $invalid = false;

        if ($credentialStatus === 'revoked') {
            $invalid = true;
        } elseif ($invitationStatus === 'checked_in') {
            $alreadyInside = true;
        } elseif ($invitationStatus !== 'approved') {
            $invalid = true;
        } elseif ($credentialExpires !== null && $credentialExpires <= $now) {
            $invalid = true;
        } elseif (
            $credentialStatus === 'used'
            || (int) $matched['used_count'] >= (int) $matched['max_uses']
        ) {
            $invalid = true;
        }

        $credentialHash = (string) $matched['credential_hash'];
        $this->recordAttempt($type, $credentialHash, !$invalid, $gate);

        if ($invalid) {
            $this->auditVerificationFailed($type, (int) $matched['id'], $matched);
            $this->context->audit->record(
                'visitor.verification_denied',
                'visitor_invitation',
                (int) $matched['id'],
                ['reason' => 'invitation_' . $invitationStatus, 'gate_id' => $gate['id']],
                $estateId
            );

            throw new HttpException(422, 'INVALID_CREDENTIAL', 'The access credential is invalid.');
        }

        return [
            'credential_uuid' => (string) $matched['credential_uuid'],
            'invitation_uuid' => (string) $matched['invitation_uuid'],
            'visitor_name' => (string) $matched['visitor_name'],
            'household_label' => AccessPresenter::householdLabel($matched),
            'purpose' => isset($matched['purpose']) && (string) $matched['purpose'] !== ''
                ? (string) $matched['purpose']
                : null,
            'valid_from' => AccessPresenter::iso(isset($matched['valid_from']) ? (string) $matched['valid_from'] : null),
            'valid_until' => AccessPresenter::iso(isset($matched['valid_until']) ? (string) $matched['valid_until'] : null),
            'status' => $invitationStatus,
            'already_inside' => $alreadyInside,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Check-in / checkout                                                */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{status: string, occurred_at: string}
     */
    public function checkin(string $invitationUuid, ?string $credentialUuid = null): array
    {
        $gate = $this->resolveGate();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $user = $this->context->auth->requireUser();
        $now = Clock::now();

        $outcome = $this->db->transaction(function () use (
            $invitationUuid,
            $credentialUuid,
            $estateId,
            $gate,
            $user,
            $now
        ): array {
            $locked = $this->db->selectOne(
                'SELECT id, uuid, status, valid_from, valid_until
                 FROM visitor_invitations
                 WHERE uuid = :uuid AND estate_id = :estate_id
                 LIMIT 1
                 FOR UPDATE',
                ['uuid' => $invitationUuid, 'estate_id' => $estateId]
            );

            if ($locked === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            $status = (string) $locked['status'];

            if ($status === 'checked_in') {
                throw new HttpException(409, 'ALREADY_INSIDE', 'This visitor is already checked in.');
            }

            if ($status !== 'approved') {
                throw new HttpException(409, 'INVITATION_NOT_ACTIVE', 'This invitation is no longer active.');
            }

            $validFrom = Clock::fromDb((string) $locked['valid_from']);
            $validUntil = Clock::fromDb((string) $locked['valid_until']);

            if ($validFrom === null || $validUntil === null || $now < $validFrom || $now > $validUntil) {
                throw new HttpException(422, 'OUTSIDE_VALIDITY', 'This pass is outside its valid visit window.');
            }

            if ($credentialUuid !== null && $credentialUuid !== '') {
                $credential = $this->db->selectOne(
                    'SELECT id, uuid, status, used_count, max_uses, expires_at
                     FROM access_credentials
                     WHERE uuid = :uuid
                       AND invitation_id = :invitation_id
                       AND estate_id = :estate_id
                     LIMIT 1
                     FOR UPDATE',
                    [
                        'uuid' => $credentialUuid,
                        'invitation_id' => (int) $locked['id'],
                        'estate_id' => $estateId,
                    ]
                );

                if ($credential === null) {
                    throw new HttpException(422, 'INVALID_CREDENTIAL', 'The access credential is invalid.');
                }

                $expires = Clock::fromDb(
                    $credential['expires_at'] === null ? null : (string) $credential['expires_at']
                );

                if (
                    (string) $credential['status'] !== 'active'
                    || (int) $credential['used_count'] >= (int) $credential['max_uses']
                    || ($expires !== null && $expires <= $now)
                ) {
                    throw new HttpException(422, 'INVALID_CREDENTIAL', 'The access credential is invalid.');
                }

                $nextCount = (int) $credential['used_count'] + 1;
                $nextStatus = $nextCount >= (int) $credential['max_uses'] ? 'used' : 'active';

                $this->db->execute(
                    'UPDATE access_credentials SET used_count = :used_count, status = :status WHERE id = :id',
                    ['used_count' => $nextCount, 'status' => $nextStatus, 'id' => (int) $credential['id']]
                );
            }

            $this->db->execute(
                'UPDATE visitor_invitations SET status = :status, updated_at = :updated WHERE id = :id',
                ['status' => 'checked_in', 'updated' => Clock::nowDb(), 'id' => (int) $locked['id']]
            );

            $occurredAt = Clock::nowDb();

            $this->db->insert('visitor_access_events', [
                'uuid' => Uuid::v4(),
                'event_uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'invitation_id' => (int) $locked['id'],
                'gate_id' => (int) $gate['id'],
                'event_type' => 'check_in',
                'actor_id' => (int) $user['id'],
                'occurred_at' => $occurredAt,
                'source' => 'online',
                'metadata' => json_encode(
                    ['credential_uuid' => $credentialUuid],
                    JSON_UNESCAPED_SLASHES
                ),
                'created_at' => $occurredAt,
            ]);

            $details = $this->db->selectOne(
                InvitationService::INVITATION_SELECT . ' WHERE i.id = :id LIMIT 1',
                ['id' => (int) $locked['id']]
            );

            return ['occurred_at' => $occurredAt, 'details' => $details];
        });

        $details = is_array($outcome['details']) ? $outcome['details'] : [];

        $this->context->audit->record(
            'visitor.checked_in',
            'visitor_invitation',
            (int) ($details['id'] ?? 0),
            ['gate_id' => $gate['id'], 'gate_name' => $gate['name'], 'invitation_uuid' => $invitationUuid],
            $estateId
        );

        $hostPhone = (string) ($details['host_phone'] ?? '');

        if ($hostPhone !== '') {
            $this->notifications->checkedIn(
                $estateId,
                $hostPhone,
                (string) ($details['visitor_name'] ?? ''),
                (string) ($details['estate_name'] ?? ''),
                (string) $outcome['occurred_at'],
                (string) ($details['estate_timezone'] ?? 'Africa/Nairobi')
            );
        }

        return [
            'status' => 'checked_in',
            'occurred_at' => AccessPresenter::iso((string) $outcome['occurred_at']) ?? (string) $outcome['occurred_at'],
        ];
    }

    /**
     * @return array{status: string}
     */
    public function checkout(string $invitationUuid): array
    {
        $gate = $this->resolveGate();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $user = $this->context->auth->requireUser();

        $details = $this->db->transaction(function () use ($invitationUuid, $estateId, $gate, $user): array {
            $locked = $this->db->selectOne(
                'SELECT id, uuid, status FROM visitor_invitations
                 WHERE uuid = :uuid AND estate_id = :estate_id
                 LIMIT 1
                 FOR UPDATE',
                ['uuid' => $invitationUuid, 'estate_id' => $estateId]
            );

            if ($locked === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available.');
            }

            if ((string) $locked['status'] !== 'checked_in') {
                throw new HttpException(409, 'NOT_INSIDE', 'This visitor is not currently inside.');
            }

            $now = Clock::nowDb();

            $this->db->execute(
                'UPDATE visitor_invitations SET status = :status, updated_at = :updated WHERE id = :id',
                ['status' => 'checked_out', 'updated' => $now, 'id' => (int) $locked['id']]
            );

            $this->db->insert('visitor_access_events', [
                'uuid' => Uuid::v4(),
                'event_uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'invitation_id' => (int) $locked['id'],
                'gate_id' => (int) $gate['id'],
                'event_type' => 'check_out',
                'actor_id' => (int) $user['id'],
                'occurred_at' => $now,
                'source' => 'online',
                'metadata' => null,
                'created_at' => $now,
            ]);

            $row = $this->db->selectOne(
                InvitationService::INVITATION_SELECT . ' WHERE i.id = :id LIMIT 1',
                ['id' => (int) $locked['id']]
            );

            return is_array($row) ? $row : [];
        });

        $this->context->audit->record(
            'visitor.checked_out',
            'visitor_invitation',
            (int) ($details['id'] ?? 0),
            ['gate_id' => $gate['id'], 'gate_name' => $gate['name'], 'invitation_uuid' => $invitationUuid],
            $estateId
        );

        $hostPhone = (string) ($details['host_phone'] ?? '');

        if ($hostPhone !== '') {
            $this->notifications->checkedOut(
                $estateId,
                $hostPhone,
                (string) ($details['visitor_name'] ?? ''),
                (string) ($details['estate_name'] ?? ''),
                Clock::nowDb(),
                (string) ($details['estate_timezone'] ?? 'Africa/Nairobi')
            );
        }

        return ['status' => 'checked_out'];
    }

    /* ------------------------------------------------------------------ */
    /* Lists + dashboard                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Visitors currently inside the estate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeVisitors(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->db->select(
            InvitationService::INVITATION_SELECT . "
             WHERE i.estate_id = :estate_id
               AND i.status = 'checked_in'
             ORDER BY i.updated_at DESC, i.id DESC
             LIMIT 100",
            ['estate_id' => $estateId]
        );

        return array_map([AccessPresenter::class, 'item'], $rows);
    }

    /**
     * Pending walk-in requests estate-wide (guard dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingWalkIns(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $rows = $this->db->select(
            "SELECT w.uuid, w.visitor_name, w.purpose, w.created_at,
                    h.unit_number, b.name AS building_name, g.name AS gate_name
             FROM walk_in_requests w
             INNER JOIN households h ON h.id = w.household_id AND h.estate_id = w.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             INNER JOIN gates g ON g.id = w.gate_id AND g.estate_id = w.estate_id
             WHERE w.estate_id = :estate_id
               AND w.status = 'pending'
             ORDER BY w.created_at DESC, w.id DESC
             LIMIT 50",
            ['estate_id' => $estateId]
        );

        return array_map([AccessPresenter::class, 'walkIn'], $rows);
    }

    /**
     * Guard dashboard payload (contract §6).
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];
        $gate = $this->currentGate();
        $active = $this->activeVisitors();
        $pending = $this->pendingWalkIns();
        $now = Clock::nowDb();

        $inside = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM visitor_invitations WHERE estate_id = :estate_id AND status = 'checked_in'",
            ['estate_id' => $estateId]
        );

        $expected = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM visitor_invitations
             WHERE estate_id = :estate_id AND status = 'approved'
               AND valid_from <= :window_from AND valid_until >= :window_until",
            ['estate_id' => $estateId, 'window_from' => $now, 'window_until' => $now]
        );

        $pendingCount = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM walk_in_requests WHERE estate_id = :estate_id AND status = 'pending'",
            ['estate_id' => $estateId]
        );

        return [
            'gates' => $this->gateOptions(),
            'selected_gate' => $gate === null ? null : ['uuid' => $gate['uuid'], 'name' => $gate['name']],
            'stats' => [
                'inside' => $inside,
                'expected' => $expected,
                'pending_walkins' => $pendingCount,
            ],
            'active' => $active,
            'pending_walkins' => $pending,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $gate
     */
    private function recordAttempt(string $type, ?string $hash, bool $success, ?array $gate = null): void
    {
        $membership = $this->context->tenant->active();

        if ($membership === null) {
            return;
        }

        try {
            $this->db->insert('access_verification_attempts', [
                'estate_id' => (int) $membership['estate_id'],
                'gate_id' => $gate === null ? null : (int) $gate['id'],
                'credential_type' => $type,
                'credential_hash' => $hash,
                'success' => $success ? 1 : 0,
                'ip_address' => $this->context->request->ip(),
                'user_agent' => $this->context->request->userAgent(),
                'created_at' => Clock::nowDb(),
            ]);
        } catch (\Throwable) {
            // Attempt logging must never break the guard's response.
        }
    }

    private function auditVerificationFailed(string $type, ?int $invitationId = null, ?array $row = null): void
    {
        $membership = $this->context->tenant->active();

        if ($membership === null) {
            return;
        }

        $this->context->audit->record(
            'visitor.verification_failed',
            'visitor_invitation',
            $invitationId,
            [
                'credential_type' => $type,
                'invitation_uuid' => is_array($row) ? (string) ($row['invitation_uuid'] ?? '') : null,
            ],
            (int) $membership['estate_id']
        );
    }
}
