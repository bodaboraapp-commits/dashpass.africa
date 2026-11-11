<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Clock;
use App\Core\Database;

/**
 * Estate notices persistence (Increment 7 §1/§3).
 *
 * Reads are always estate-scoped. Visibility queries encode the contract:
 * published only, audience-matched, not expired, ordered urgent > important >
 * normal then newest published first. Admin queries see every status with
 * optional filters. Notices are never deleted (draft/published/archived only).
 */
final class NoticeRepository
{
    private const SELECT = 'SELECT n.id, n.uuid, n.estate_id, n.title, n.body, n.category, n.priority,
                n.status, n.audience, n.published_at, n.expires_at, n.image_path, n.image_alt,
                n.created_by_user_id, n.updated_by_user_id, n.created_at, n.updated_at,
                creator.full_name AS created_by_name
         FROM estate_notices n
         LEFT JOIN users creator ON creator.id = n.created_by_user_id';

    private const PRIORITY_ORDER = "FIELD(n.priority, 'urgent', 'important', 'normal')";

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Resident-visible notices: published + audience all|residents + unexpired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleForResident(int $estateId, ?int $limit = null): array
    {
        $sql = self::SELECT . "
                WHERE n.estate_id = :estate_id
                  AND n.status = 'published'
                  AND n.audience IN ('all', 'residents')
                  AND (n.expires_at IS NULL OR n.expires_at > :now)
                ORDER BY " . self::PRIORITY_ORDER . ' ASC, n.published_at DESC, n.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return $this->db->select($sql, ['estate_id' => $estateId, 'now' => $this->nowDb()]);
    }

    /**
     * Guard-visible notices: published + audience all|staff + unexpired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleForGuard(int $estateId): array
    {
        return $this->db->select(
            self::SELECT . "
             WHERE n.estate_id = :estate_id
               AND n.status = 'published'
               AND n.audience IN ('all', 'staff')
               AND (n.expires_at IS NULL OR n.expires_at > :now)
             ORDER BY " . self::PRIORITY_ORDER . ' ASC, n.published_at DESC, n.id DESC',
            ['estate_id' => $estateId, 'now' => $this->nowDb()]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVisibleForResident(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::SELECT . "
             WHERE n.estate_id = :estate_id
               AND n.uuid = :uuid
               AND n.status = 'published'
               AND n.audience IN ('all', 'residents')
               AND (n.expires_at IS NULL OR n.expires_at > :now)
             LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid, 'now' => $this->nowDb()]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVisibleForGuard(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::SELECT . "
             WHERE n.estate_id = :estate_id
               AND n.uuid = :uuid
               AND n.status = 'published'
               AND n.audience IN ('all', 'staff')
               AND (n.expires_at IS NULL OR n.expires_at > :now)
             LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid, 'now' => $this->nowDb()]
        );
    }

    /**
     * Admin queue: every status, optional status/category/priority filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forAdmin(int $estateId, ?string $status, ?string $category, ?string $priority): array
    {
        [$filter, $params] = $this->adminFilters($estateId, $status, $category, $priority);

        return $this->db->select(
            self::SELECT . ' WHERE n.estate_id = :estate_id' . $filter
            . ' ORDER BY n.created_at DESC, n.id DESC',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInEstate(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE n.estate_id = :estate_id AND n.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, int> Draft/published/archived/total counts for the queue.
     */
    public function counts(int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS total FROM estate_notices
             WHERE estate_id = :estate_id GROUP BY status',
            ['estate_id' => $estateId]
        );

        $counts = ['draft' => 0, 'published' => 0, 'archived' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $total = (int) $row['total'];
            $counts[$status] = $total;
            $counts['total'] += $total;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(array $fields): int
    {
        return $this->db->insert('estate_notices', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $this->db->update('estate_notices', $fields, ['id' => $id]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminFilters(int $estateId, ?string $status, ?string $category, ?string $priority): array
    {
        $filter = '';
        $params = ['estate_id' => $estateId];

        if ($status !== null) {
            $filter .= ' AND n.status = :status';
            $params['status'] = $status;
        }

        if ($category !== null) {
            $filter .= ' AND n.category = :category';
            $params['category'] = $category;
        }

        if ($priority !== null) {
            $filter .= ' AND n.priority = :priority';
            $params['priority'] = $priority;
        }

        return [$filter, $params];
    }

    private function nowDb(): string
    {
        return Clock::nowDb();
    }
}
