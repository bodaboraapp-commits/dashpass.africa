<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Uuid;

/**
 * Persistence for public privacy / data-subject requests.
 *
 * A request is deliberately *not* joined to `users`: the person exercising a
 * right may be a visitor, a former resident or someone whose details an estate
 * administrator captured, and the request must still be recorded and actioned.
 *
 * Contact details are never rendered back on a public page; only the reference
 * code is shown to the person who submitted the request.
 */
final class PrivacyRequestRepository
{
    /** @var array<int, string> */
    public const TYPES = ['opt_in', 'opt_out', 'access', 'correction', 'deletion'];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        'opt_in' => 'Opt in to optional communications',
        'opt_out' => 'Opt out of optional communications',
        'access' => 'Request a copy of my data',
        'correction' => 'Correct my data',
        'deletion' => 'Delete my data',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Build a human-quotable reference, e.g. `DP-20261113-4F7A`.
     *
     * Date-prefixed so the team can sort by day at a glance; the random suffix
     * makes guessing another person's reference impractical.
     */
    public static function newReference(): string
    {
        return 'DP-' . Clock::now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * @param array{
     *     request_type: string,
     *     subject_name: string,
     *     subject_phone: string,
     *     subject_email: string|null,
     *     message: string|null,
     *     policy_version: string
     * } $request
     * @return array{id: int, uuid: string, reference: string}
     */
    public function create(array $request): array
    {
        $uuid = Uuid::v4();
        $reference = self::newReference();

        $id = $this->db->insert('privacy_requests', [
            'uuid' => $uuid,
            'reference' => $reference,
            'request_type' => $request['request_type'],
            'subject_name' => $request['subject_name'],
            'subject_phone' => $request['subject_phone'],
            'subject_email' => $request['subject_email'],
            'message' => $request['message'],
            'status' => 'pending',
            'policy_version' => $request['policy_version'],
            'requested_at' => Clock::nowDb(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM privacy_requests WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM privacy_requests WHERE reference = :reference LIMIT 1',
            ['reference' => $reference]
        );
    }

    /**
     * Most recent requests first, for operators actioning the inbox.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT id, uuid, reference, request_type, subject_name, subject_phone,
                    subject_email, message, status, requested_at, processed_at
             FROM privacy_requests
             ORDER BY requested_at DESC, id DESC
             LIMIT ' . $limit
        );
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM privacy_requests WHERE status IN ('pending', 'in_progress')"
        );
    }
}
