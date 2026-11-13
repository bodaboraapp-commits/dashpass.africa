<?php

declare(strict_types=1);

namespace App\Modules\Goods;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Notifications\NotificationLogger;

/**
 * Goods in/out register (owner request 2026-11-13).
 *
 * Flow: the tenant initiates a goods check-in/check-out request for their
 * household, the Estate Admin approves or declines it, and the guard verifies the
 * approved movement at the gate (completing it). Residents are notified in-app on
 * each decision and gate result; every step is audited.
 *
 * Scoping: residents only see/request for their active household in the active
 * estate; admins act on their estate; guards verify for their estate/gate.
 */
final class GoodsMovementService
{
    public const DIRECTIONS = ['in', 'out'];

    public const STATUSES = ['pending', 'approved', 'declined', 'completed'];

    public const STATUS_LABELS = [
        'pending' => 'Pending approval',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'completed' => 'Verified at gate',
    ];

    public const DIRECTION_LABELS = [
        'in' => 'Goods in',
        'out' => 'Goods out',
    ];

    public function __construct(
        private readonly Context $context,
        private readonly Database $db
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db);
    }

    /**
     * Requests for the signed-in resident's household, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function residentList(): array
    {
        $household = $this->residentHousehold();

        $rows = $this->db->select(
            "SELECT gm.*, h.unit_number, b.name AS building_name
             FROM goods_movements gm
             INNER JOIN households h ON h.id = gm.household_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE gm.household_id = :household_id
               AND gm.estate_id = :estate_id
             ORDER BY gm.created_at DESC, gm.id DESC
             LIMIT 50",
            ['household_id' => (int) $household['household_id'], 'estate_id' => (int) $household['estate_id']]
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Create a goods-in / goods-out request for the resident's household.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $household = $this->residentHousehold();

        $clean = Validator::validate($input, [
            'direction' => 'required|in:in,out',
            'description' => 'required|string|min:3|max:300',
            'quantity' => 'nullable|integer|min:1|max:999',
            'carrier_name' => 'nullable|string|max:120',
            'vehicle_plate' => 'nullable|string|max:20',
            'recipient_name' => 'nullable|string|max:180',
            'recipient_phone' => 'nullable|phone',
            'expected_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $direction = (string) $clean['direction'];
        $now = Clock::nowDb();
        $estateId = (int) $household['estate_id'];
        $residentUserId = (int) $this->context->auth->requireUser()['id'];

        $movementId = $this->db->insert('goods_movements', [
            'uuid' => Uuid::v4(),
            'estate_id' => $estateId,
            'household_id' => (int) $household['household_id'],
            'resident_user_id' => $residentUserId,
            'reference_code' => $this->uniqueReferenceCode($estateId),
            'direction' => $direction,
            'description' => trim((string) $clean['description']),
            'quantity' => max(1, (int) ($clean['quantity'] ?? 1)),
            'carrier_name' => $this->nullableString($clean['carrier_name'] ?? null),
            'vehicle_plate' => $this->nullableUpper($clean['vehicle_plate'] ?? null),
            'recipient_name' => $this->nullableString($clean['recipient_name'] ?? null),
            'recipient_phone' => $this->nullableString($clean['recipient_phone'] ?? null),
            'expected_at' => isset($clean['expected_at']) ? (string) $clean['expected_at'] : null,
            'notes' => $this->nullableString($clean['notes'] ?? null),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->context->audit->record('goods.requested', 'goods_movement', $movementId, [
            'estate_id' => $estateId,
            'household_id' => (int) $household['household_id'],
            'direction' => $direction,
        ]);

        return $this->findForResident($this->db->selectOne(
            'SELECT * FROM goods_movements WHERE id = :id',
            ['id' => $movementId]
        ) ?? []);
    }

    /**
     * Pending/decided requests for the Estate Admin, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminList(string $statusFilter = 'pending'): array
    {
        $estateId = $this->estateId();
        $filter = '';
        $params = ['estate_id' => $estateId];

        if (in_array($statusFilter, self::STATUSES, true)) {
            $filter = ' AND gm.status = :status';
            $params['status'] = $statusFilter;
        }

        $rows = $this->db->select(
            "SELECT gm.*, h.unit_number, b.name AS building_name,
                    resident.full_name AS resident_name, resident.phone AS resident_phone,
                    decider.full_name AS decided_by_name
             FROM goods_movements gm
             INNER JOIN households h ON h.id = gm.household_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             INNER JOIN users resident ON resident.id = gm.resident_user_id
             LEFT JOIN users decider ON decider.id = gm.decided_by
             WHERE gm.estate_id = :estate_id" . $filter . "
             ORDER BY FIELD(gm.status, 'pending', 'approved', 'completed', 'declined'), gm.created_at DESC
             LIMIT 100",
            $params
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Approve a pending request (Estate Admin).
     */
    public function approve(string $uuid, string $note = ''): array
    {
        return $this->decide($uuid, 'approved', $note);
    }

    /**
     * Decline a pending request (Estate Admin); the reason is required.
     */
    public function decline(string $uuid, string $note): array
    {
        $note = trim($note);

        if (mb_strlen($note) < 3) {
            throw new ValidationException(['decision_note' => ['Give the resident a short reason for declining.']]);
        }

        return $this->decide($uuid, 'declined', $note);
    }

    /**
     * Approved goods waiting for (or recently verified at) the gate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function guardList(): array
    {
        $estateId = $this->estateId();
        $rows = $this->db->select(
            "SELECT gm.*, h.unit_number, b.name AS building_name,
                    resident.full_name AS resident_name, resident.phone AS resident_phone,
                    gate.name AS gate_name, verifier.full_name AS verified_by_name
             FROM goods_movements gm
             INNER JOIN households h ON h.id = gm.household_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             INNER JOIN users resident ON resident.id = gm.resident_user_id
             LEFT JOIN gates gate ON gate.id = gm.gate_id
             LEFT JOIN users verifier ON verifier.id = gm.verified_by
             WHERE gm.estate_id = :estate_id
               AND gm.status IN ('approved', 'completed')
             ORDER BY FIELD(gm.status, 'approved', 'completed'), gm.created_at DESC
             LIMIT 50",
            ['estate_id' => $estateId]
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Verify an approved movement at the gate (Guard), completing it.
     *
     * @param array{id:int, name:string} $gate
     */
    public function guardVerify(string $uuid, array $gate, string $note = ''): array
    {
        $row = $this->findForEstate($uuid);

        if ($row === null) {
            throw HttpException::notFound('GOODS_NOT_FOUND', 'That goods movement is not available.');
        }

        if ((string) $row['status'] !== 'approved') {
            throw new HttpException(
                422,
                'GOODS_NOT_APPROVED',
                'Only approved goods movements can be verified at the gate.'
            );
        }

        $now = Clock::nowDb();
        $this->db->update('goods_movements', [
            'status' => 'completed',
            'verified_by' => (int) $this->context->auth->requireUser()['id'],
            'verified_at' => $now,
            'gate_id' => (int) $gate['id'],
            'verification_note' => $this->nullableString($note),
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        $this->context->audit->record('goods.verified_' . (string) $row['direction'], 'goods_movement', (int) $row['id'], [
            'estate_id' => (int) $row['estate_id'],
            'gate_id' => (int) $gate['id'],
        ]);

        $directionLabel = $row['direction'] === 'in' ? 'check-in' : 'check-out';
        $this->notifyResident(
            (int) $row['estate_id'],
            (int) $row['resident_user_id'],
            'goods_verified',
            'Goods ' . $directionLabel . ' verified',
            (string) $row['description'] . ' was verified at ' . (string) $gate['name'] . '.',
            'goods:' . (string) $row['uuid'] . ':verified'
        );

        return $this->findForEstate($uuid) ?? [];
    }

    /**
     * Resident-facing status label helpers for views.
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'approved' => 'ok',
            'completed' => 'ok',
            'declined' => 'danger',
            default => 'warn',
        };
    }

    public static function directionLabel(string $direction): string
    {
        return self::DIRECTION_LABELS[$direction] ?? ucfirst($direction);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decide(string $uuid, string $status, string $note): array
    {
        $row = $this->findForEstate($uuid);

        if ($row === null) {
            throw HttpException::notFound('GOODS_NOT_FOUND', 'That goods movement is not available.');
        }

        if ((string) $row['status'] !== 'pending') {
            throw new HttpException(422, 'GOODS_ALREADY_DECIDED', 'That goods movement was already decided.');
        }

        $now = Clock::nowDb();
        $actorId = (int) $this->context->auth->requireUser()['id'];

        $this->db->update('goods_movements', [
            'status' => $status,
            'decision_note' => $this->nullableString($note),
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        $this->context->audit->record(
            $status === 'approved' ? 'goods.approved' : 'goods.declined',
            'goods_movement',
            (int) $row['id'],
            ['estate_id' => (int) $row['estate_id'], 'direction' => (string) $row['direction']]
        );

        $directionText = $row['direction'] === 'in' ? 'check-in' : 'check-out';
        $this->notifyResident(
            (int) $row['estate_id'],
            (int) $row['resident_user_id'],
            'goods_' . $status,
            'Goods ' . $directionText . ' ' . $status,
            (string) $row['description']
                . ($status === 'approved'
                    ? ' was approved. Show the reference code to the guard at the gate.'
                    : ' was declined.' . ($note !== '' ? ' Reason: ' . trim($note) : '')),
            'goods:' . (string) $row['uuid'] . ':' . $status
        );

        return $this->findForEstate($uuid) ?? [];
    }

    private function residentHousehold(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();

        $row = $this->db->selectOne(
            "SELECT hm.household_id, hm.estate_id
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.status = 'active'
             ORDER BY hm.is_primary DESC, hm.id ASC
             LIMIT 1",
            ['user_id' => (int) $user['id'], 'estate_id' => $estateId]
        );

        if ($row === null) {
            throw new HttpException(
                422,
                'NO_ACTIVE_HOUSEHOLD',
                'Your account is not linked to an active household in this estate.'
            );
        }

        return $row;
    }

    private function findForEstate(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM goods_movements WHERE uuid = :uuid AND estate_id = :estate_id LIMIT 1',
            ['uuid' => $uuid, 'estate_id' => $this->estateId()]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function findForResident(array $row): array
    {
        return $this->present($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $status = (string) ($row['status'] ?? 'pending');
        $direction = (string) ($row['direction'] ?? 'in');
        $householdLabel = trim((string) ($row['unit_number'] ?? ''));
        $building = trim((string) ($row['building_name'] ?? ''));

        if ($building !== '') {
            $householdLabel = $householdLabel !== '' ? $householdLabel . ' - ' . $building : $building;
        }

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'reference_code' => (string) ($row['reference_code'] ?? ''),
            'direction' => $direction,
            'direction_label' => self::directionLabel($direction),
            'description' => (string) ($row['description'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 1),
            'carrier_name' => $row['carrier_name'] ?? null,
            'vehicle_plate' => $row['vehicle_plate'] ?? null,
            'recipient_name' => $row['recipient_name'] ?? null,
            'recipient_phone' => $row['recipient_phone'] ?? null,
            'expected_at' => $row['expected_at'] ?? null,
            'notes' => $row['notes'] ?? null,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'decision_note' => $row['decision_note'] ?? null,
            'household_label' => $householdLabel,
            'resident_name' => $row['resident_name'] ?? null,
            'resident_phone' => $row['resident_phone'] ?? null,
            'decided_by_name' => $row['decided_by_name'] ?? null,
            'decided_at' => $row['decided_at'] ?? null,
            'verified_by_name' => $row['verified_by_name'] ?? null,
            'verified_at' => $row['verified_at'] ?? null,
            'gate_name' => $row['gate_name'] ?? null,
            'verification_note' => $row['verification_note'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    private function uniqueReferenceCode(int $estateId): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'GDS-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->db->selectOne(
                'SELECT id FROM goods_movements WHERE estate_id = :estate_id AND reference_code = :code LIMIT 1',
                ['estate_id' => $estateId, 'code' => $code]
            );

            if ($exists === null) {
                return $code;
            }
        }

        throw new HttpException(500, 'GOODS_REFERENCE_FAILED', 'Could not allocate a goods reference code.');
    }

    private function notifyResident(int $estateId, int $userId, string $template, string $title, string $body, string $dedupe): void
    {
        try {
            (new NotificationLogger($this->db))->logInApp(
                $estateId,
                'u:' . $userId,
                $template,
                mb_substr($title, 0, 160),
                mb_substr($body, 0, 500),
                '/app/goods',
                $dedupe
            );
        } catch (\Throwable) {
            // Notification failure never blocks the goods workflow.
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $string = strtoupper(trim((string) ($value ?? '')));

        return $string !== '' ? $string : null;
    }
}
