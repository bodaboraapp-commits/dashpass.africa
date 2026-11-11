<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Database;
use App\Core\Uuid;

/**
 * Estate-scoped visitor records.
 *
 * Visitors are matched by (estate_id, phone) only: the same phone number in two
 * estates is two unrelated people, which prevents cross-tenant identity
 * correlation (contract §2).
 */
final class VisitorRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Find or create the visitor row for (estate, phone), keeping the latest
     * supplied name.
     *
     * @return array{id: int, uuid: string, name: string, phone: string}
     */
    public function findOrCreate(int $estateId, string $phone, string $name): array
    {
        $existing = $this->db->selectOne(
            'SELECT id, uuid, name, phone FROM visitors
             WHERE estate_id = :estate_id AND phone = :phone
             LIMIT 1',
            ['estate_id' => $estateId, 'phone' => $phone]
        );

        if ($existing !== null) {
            if ((string) $existing['name'] !== $name) {
                $this->db->execute(
                    'UPDATE visitors SET name = :name WHERE id = :id',
                    ['name' => $name, 'id' => (int) $existing['id']]
                );
            }

            return [
                'id' => (int) $existing['id'],
                'uuid' => (string) $existing['uuid'],
                'name' => $name,
                'phone' => $phone,
            ];
        }

        $uuid = Uuid::v4();
        $id = $this->db->insert('visitors', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'name' => $name,
            'phone' => $phone,
            'created_at' => \App\Core\Clock::nowDb(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'name' => $name, 'phone' => $phone];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInEstate(int $estateId, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, name, phone FROM visitors WHERE estate_id = :estate_id AND id = :id LIMIT 1',
            ['estate_id' => $estateId, 'id' => $id]
        );
    }
}
