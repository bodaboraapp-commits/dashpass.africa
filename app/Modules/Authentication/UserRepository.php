<?php

declare(strict_types=1);

namespace App\Modules\Authentication;

use App\Core\Database;

/**
 * Read model for the identity table.
 *
 * Only the columns needed by authentication are selected; password hashes are
 * never loaded on the OTP login path (MtaaPass v1.0 uses SMS OTP, not
 * passwords).
 */
final class UserRepository
{
    private const COLUMNS = 'id, uuid, full_name, phone, email, status, created_at, updated_at, last_login_at';

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByPhone(string $e164): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE phone = :phone AND status = :status LIMIT 1',
            ['phone' => $e164, 'status' => 'active']
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $e164): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => $e164]
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];

        return $row;
    }
}
