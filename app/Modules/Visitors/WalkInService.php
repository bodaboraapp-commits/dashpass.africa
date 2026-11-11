<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Walk-in visitor flow (contract §4.6).
 *
 * A guard creates a pending request for a household; a resident of that
 * household approves or rejects. Approval creates the invitation + credentials
 * atomically with the request update; nothing is ever auto-admitted.
 */
final class WalkInService
{
    private const APPROVAL_VALIDITY_MINUTES = 120;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly UserHouseholdRepository $households,
        private readonly InvitationService $invitations,
        private readonly VisitorNotificationService $notifications
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(
            $context,
            $context->db,
            new UserHouseholdRepository($context->db),
            InvitationService::make($context),
            VisitorNotificationService::make($context->db)
        );
    }

    /**
     * Guard creates a pending walk-in request at their selected gate.
     *
     * @param array{id: int, uuid: string, name: string} $gate
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createByGuard(array $gate, array $input): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];

        $clean = Validator::validate(
            $input,
            [
                'visitor_name' => 'required|string|min:2|max:180',
                'visitor_phone' => 'required|phone',
                'household_uuid' => 'required|uuid',
                'purpose' => 'nullable|string|max:120',
            ],
            [
                'visitor_name' => 'visitor name',
                'visitor_phone' => 'visitor phone number',
                'household_uuid' => 'household',
                'purpose' => 'purpose',
            ]
        );

        $household = $this->households->findInEstate($estateId, (string) $clean['household_uuid']);

        if ($household === null) {
            throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available for this estate.');
        }

        $now = Clock::nowDb();
        $uuid = \App\Core\Uuid::v4();
        $purpose = isset($clean['purpose']) && $clean['purpose'] !== null ? (string) $clean['purpose'] : null;

        $id = $this->db->insert('walk_in_requests', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'household_id' => (int) $household['id'],
            'gate_id' => (int) $gate['id'],
            'requested_by_user_id' => (int) $user['id'],
            'visitor_name' => (string) $clean['visitor_name'],
            'visitor_phone' => (string) $clean['visitor_phone'],
            'purpose' => $purpose,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->context->audit->record(
            'visitor.walkin.created',
            'walk_in_request',
            $id,
            [
                'walk_in_uuid' => $uuid,
                'visitor_name' => (string) $clean['visitor_name'],
                'household_label' => (string) $household['label'],
                'gate_name' => (string) $gate['name'],
            ],
            $estateId
        );

        // Notify every active household member (dev SMS adapter).
        $label = (string) $household['label'];

        foreach ($this->households->membersWithPhones((int) $household['id'], $estateId) as $member) {
            $this->notifications->walkInRequest(
                $estateId,
                $member['phone'],
                (string) $clean['visitor_name'],
                (string) $gate['name'],
                $label
            );
        }

        return AccessPresenter::walkIn([
            'uuid' => $uuid,
            'visitor_name' => (string) $clean['visitor_name'],
            'purpose' => $purpose,
            'created_at' => $now,
            'gate_name' => (string) $gate['name'],
            'unit_number' => $household['unit_number'],
            'building_name' => $household['building_name'],
        ]) + ['status' => 'pending'];
    }

    /**
     * Pending walk-in requests for the resident's households.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForResident(): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $householdIds = $this->households->idsForUser((int) $user['id'], $estateId);

        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $rows = $this->db->select(
            'SELECT w.uuid, w.visitor_name, w.purpose, w.created_at,
                    h.unit_number, b.name AS building_name, g.name AS gate_name
             FROM walk_in_requests w
             INNER JOIN households h ON h.id = w.household_id AND h.estate_id = w.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             INNER JOIN gates g ON g.id = w.gate_id AND g.estate_id = w.estate_id
             WHERE w.estate_id = ?
               AND w.status = \'pending\'
               AND w.household_id IN (' . $placeholders . ')
             ORDER BY w.created_at DESC, w.id DESC
             LIMIT 50',
            array_merge([$estateId], $householdIds)
        );

        return array_map([AccessPresenter::class, 'walkIn'], $rows);
    }

    /**
     * Pending walk-in requests estate-wide (guard dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForEstate(): array
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
     * Resident approves a pending request: creates invitation + credentials,
     * links the request, notifies the visitor, audits.
     *
     * @return array{status: string, invitation_uuid: string, pin?: string}
     */
    public function approve(string $walkInUuid): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $row = $this->findForResident($walkInUuid);

        if ($row === null) {
            throw HttpException::notFound('WALKIN_NOT_FOUND', 'That walk-in request is not available for your account.');
        }

        $created = [];
        $now = Clock::now();

        $this->db->transaction(function () use ($row, $membership, $user, $now, &$created): void {
            $locked = $this->db->selectOne(
                'SELECT id, uuid, household_id, visitor_name, visitor_phone, purpose, status
                 FROM walk_in_requests
                 WHERE id = :id AND estate_id = :estate_id
                 LIMIT 1
                 FOR UPDATE',
                ['id' => (int) $row['id'], 'estate_id' => (int) $row['estate_id']]
            );

            if ($locked === null) {
                throw HttpException::notFound('WALKIN_NOT_FOUND', 'That walk-in request is not available.');
            }

            if ((string) $locked['status'] !== 'pending') {
                throw new HttpException(409, 'WALKIN_NOT_PENDING', 'This walk-in request is no longer pending.');
            }

            $created = $this->invitations->createInTransaction(
                (string) $locked['visitor_name'],
                (string) $locked['visitor_phone'],
                (int) $locked['household_id'],
                $locked['purpose'] === null ? null : (string) $locked['purpose'],
                1,
                $now,
                $now->modify('+' . self::APPROVAL_VALIDITY_MINUTES . ' minutes'),
                (int) $user['id'],
                $membership
            );

            $this->db->execute(
                'UPDATE walk_in_requests
                 SET status = :status, invitation_id = :invitation_id,
                     responded_by_user_id = :responded_by, responded_at = :responded_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'approved',
                    'invitation_id' => (int) $created['_invitation_id'],
                    'responded_by' => (int) $user['id'],
                    'responded_at' => Clock::nowDb(),
                    'updated_at' => Clock::nowDb(),
                    'id' => (int) $locked['id'],
                ]
            );
        });

        $this->context->audit->record(
            'visitor.walkin.approved',
            'walk_in_request',
            (int) $row['id'],
            [
                'walk_in_uuid' => (string) $row['uuid'],
                'invitation_uuid' => (string) $created['invitation_uuid'],
                'visitor_name' => (string) $created['visitor']['name'],
            ],
            $estateId
        );

        $this->notifications->walkInApproved(
            $estateId,
            (string) $created['visitor']['phone'],
            (string) $created['_estate_name'],
            (string) $created['_household_label'],
            (string) $created['_pin'],
            (string) $created['_pass_url'],
            (string) $created['_valid_until_db'],
            (string) $created['_timezone']
        );

        $data = [
            'status' => 'approved',
            'invitation_uuid' => (string) $created['invitation_uuid'],
        ];

        if ($this->pinRevealAllowed()) {
            $data['pin'] = (string) $created['_pin'];
        }

        return $data;
    }

    /**
     * Resident rejects a pending request (no credentials are created).
     *
     * @return array{status: string}
     */
    public function reject(string $walkInUuid): array
    {
        $user = $this->context->auth->requireUser();
        $row = $this->findForResident($walkInUuid);

        if ($row === null) {
            throw HttpException::notFound('WALKIN_NOT_FOUND', 'That walk-in request is not available for your account.');
        }

        $this->db->transaction(function () use ($row, $user): void {
            $locked = $this->db->selectOne(
                'SELECT id, status FROM walk_in_requests WHERE id = :id AND estate_id = :estate_id LIMIT 1 FOR UPDATE',
                ['id' => (int) $row['id'], 'estate_id' => (int) $row['estate_id']]
            );

            if ($locked === null) {
                throw HttpException::notFound('WALKIN_NOT_FOUND', 'That walk-in request is not available.');
            }

            if ((string) $locked['status'] !== 'pending') {
                throw new HttpException(409, 'WALKIN_NOT_PENDING', 'This walk-in request is no longer pending.');
            }

            $this->db->execute(
                'UPDATE walk_in_requests
                 SET status = :status, responded_by_user_id = :responded_by,
                     responded_at = :responded_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'rejected',
                    'responded_by' => (int) $user['id'],
                    'responded_at' => Clock::nowDb(),
                    'updated_at' => Clock::nowDb(),
                    'id' => (int) $locked['id'],
                ]
            );
        });

        $this->context->audit->record(
            'visitor.walkin.rejected',
            'walk_in_request',
            (int) $row['id'],
            ['walk_in_uuid' => (string) $row['uuid']],
            (int) $row['estate_id']
        );

        return ['status' => 'rejected'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findForResident(string $walkInUuid): ?array
    {
        $user = $this->context->auth->requireUser();
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        return $this->db->selectOne(
            "SELECT w.id, w.uuid, w.estate_id, w.household_id, w.status, w.visitor_name
             FROM walk_in_requests w
             WHERE w.estate_id = :estate_id
               AND w.uuid = :uuid
               AND EXISTS (
                   SELECT 1 FROM household_members hm
                   WHERE hm.household_id = w.household_id
                     AND hm.estate_id = w.estate_id
                     AND hm.user_id = :user_id
                     AND hm.status = 'active'
               )
             LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $walkInUuid, 'user_id' => (int) $user['id']]
        );
    }

    private function pinRevealAllowed(): bool
    {
        return (string) Config::get('app.env', 'production') !== 'production'
            && (bool) Config::get('sms.expose_development_otp', false);
    }

    /**
     * Kept for future expiry handling: never auto-admit a timed-out request.
     */
    public function approvalTimeoutSeconds(): int
    {
        $estateId = (int) $this->context->tenant->requireEstate()['estate_id'];

        $row = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings
             WHERE estate_id = :estate_id AND setting_key = :setting_key LIMIT 1',
            ['estate_id' => $estateId, 'setting_key' => 'walkin_approval_timeout_seconds']
        );

        if ($row !== null && $row['setting_value'] !== null) {
            $decoded = json_decode((string) $row['setting_value'], true);

            if (is_numeric($decoded)) {
                return max(30, (int) $decoded);
            }
        }

        return max(30, (int) Config::get('visitors.walk_in.approval_timeout_seconds', 300));
    }

    /**
     * Placeholder to keep the class honest about expired requests: shown as
     * "awaiting response" but never approved by a timer.
     */
    public function isAwaitingResponse(array $row): bool
    {
        $created = Clock::fromDb(isset($row['created_at']) ? (string) $row['created_at'] : null);

        if ($created === null) {
            return false;
        }

        return Clock::now()->getTimestamp() - $created->getTimestamp() > $this->approvalTimeoutSeconds();
    }
}
