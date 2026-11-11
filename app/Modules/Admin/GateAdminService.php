<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;

/**
 * Gate management (Increment 4 contract §2).
 *
 * Gates are unique per estate (422 GATE_EXISTS), editable, and soft-archived via
 * status='inactive' (never hard-deleted). Archived gates are excluded from all
 * selectors and from new guard assignments, and the guard gate-context resolver
 * already requires an active gate.
 */
final class GateAdminService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly AdminAccess $access
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, AdminAccess::make($context));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->db->select(
            'SELECT g.id, g.uuid, g.name, g.location, g.status,
                    (SELECT COUNT(*) FROM guard_gate_assignments a
                     WHERE a.gate_id = g.id AND a.estate_id = g.estate_id AND a.status = \'active\') AS guard_count
             FROM gates g
             WHERE g.estate_id = :estate_id
             ORDER BY g.name ASC',
            ['estate_id' => $this->access->estateId()]
        );

        return array_map(
            function (array $row): array {
                $status = (string) $row['status'];

                return [
                    'uuid' => (string) $row['uuid'],
                    'name' => (string) $row['name'],
                    'location' => $row['location'] === null ? null : (string) $row['location'],
                    'status' => $status,
                    'status_label' => $this->access->gateStatusLabel($status),
                    'status_tone' => $this->access->gateStatusTone($status),
                    'guard_count' => (int) $row['guard_count'],
                ];
            },
            $rows
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = $this->access->validate(
            $input,
            [
                'name' => 'required|string|min:2|max:100',
                'location' => 'nullable|string|max:180',
            ],
            ['name' => 'gate name', 'location' => 'location']
        );

        $name = trim((string) $clean['name']);
        $this->ensureUniqueName($name);

        $uuid = Uuid::v4();
        $id = $this->db->insert('gates', [
            'uuid' => $uuid,
            'estate_id' => $this->access->estateId(),
            'name' => $name,
            'location' => isset($clean['location']) && $clean['location'] !== null && trim((string) $clean['location']) !== ''
                ? trim((string) $clean['location'])
                : null,
            'status' => 'active',
            'created_at' => Clock::nowDb(),
        ]);

        $this->access->audit('admin.gate_created', 'gate', $id, ['gate_uuid' => $uuid]);

        return ['uuid' => $uuid, 'name' => $name];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $gateUuid, array $input): array
    {
        $gate = $this->access->findGate($gateUuid);

        $clean = $this->access->validate(
            $input,
            [
                'name' => 'required|string|min:2|max:100',
                'location' => 'nullable|string|max:180',
            ],
            ['name' => 'gate name', 'location' => 'location']
        );

        $name = trim((string) $clean['name']);
        $this->ensureUniqueName($name, (int) $gate['id']);

        $this->db->update('gates', [
            'name' => $name,
            'location' => isset($clean['location']) && $clean['location'] !== null && trim((string) $clean['location']) !== ''
                ? trim((string) $clean['location'])
                : null,
        ], ['id' => (int) $gate['id']]);

        $this->access->audit('admin.gate_updated', 'gate', (int) $gate['id'], [
            'gate_uuid' => (string) $gate['uuid'],
            'changed' => ['name', 'location'],
        ]);

        return ['uuid' => (string) $gate['uuid'], 'name' => $name];
    }

    /**
     * @return array{status: string}
     */
    public function archive(string $gateUuid): array
    {
        $gate = $this->access->findGate($gateUuid);
        $this->db->update('gates', ['status' => 'inactive'], ['id' => (int) $gate['id']]);

        $this->access->audit('admin.gate_archived', 'gate', (int) $gate['id'], [
            'gate_uuid' => (string) $gate['uuid'],
            'previous_status' => (string) $gate['status'],
        ]);

        return ['status' => 'inactive'];
    }

    private function ensureUniqueName(string $name, ?int $excludeId = null): void
    {
        $params = ['estate_id' => $this->access->estateId(), 'name' => $name];
        $sql = 'SELECT id FROM gates WHERE estate_id = :estate_id AND name = :name';

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        if ($this->db->selectOne($sql . ' LIMIT 1', $params) !== null) {
            throw new HttpException(
                422,
                'GATE_EXISTS',
                'That gate name is already used in this estate.',
                ['errors' => ['name' => ['That gate name is already used in this estate.']]]
            );
        }
    }
}
