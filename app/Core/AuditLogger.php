<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Append-only business and security audit trail (Blueprint §45).
 *
 * Record actor, estate, action, entity, time, request context and safe
 * metadata. Credential-shaped values are redacted before they reach the
 * database; raw PINs, OTPs, passwords and tokens never appear here.
 */
final class AuditLogger
{
    private const REDACT_PATTERN = '/pin|otp|password|secret|token|authorization|api[-_]?key|csrf/i';

    public function __construct(
        private readonly Database $db,
        private readonly ?Request $request = null,
        private readonly ?Auth $auth = null,
        private readonly ?TenantContext $tenant = null
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $metadata = [],
        ?int $estateId = null,
        ?int $actorId = null
    ): void {
        $metadata = self::sanitize($metadata);

        try {
            $this->db->insert('audit_logs', [
                'uuid' => Uuid::v4(),
                'estate_id' => $estateId ?? $this->tenant?->estateId(),
                'actor_id' => $actorId ?? $this->auth?->id(),
                'action' => mb_substr($action, 0, 100),
                'entity_type' => mb_substr($entityType, 0, 80),
                'entity_id' => $entityId,
                'metadata' => $metadata === []
                    ? null
                    : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'correlation_id' => Logger::correlationId(),
                'ip_address' => $this->request?->ip(),
                'user_agent' => $this->request?->userAgent(),
                'created_at' => Clock::nowDb(),
            ]);
        } catch (Throwable $exception) {
            // An audit failure must never be silent, but it also must not be
            // swallowed into a generic 500 without a trace.
            Logger::error('Audit log write failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Recent audit entries for one estate. The estate id comes from the
     * resolved tenant context, never from request input.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentForEstate(int $estateId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT a.id, a.uuid, a.action, a.entity_type, a.entity_id, a.metadata,
                    a.correlation_id, a.ip_address, a.created_at,
                    u.full_name AS actor_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.estate_id = :estate_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ' . $limit,
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForActor(int $actorId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT a.id, a.uuid, a.action, a.entity_type, a.entity_id, a.metadata,
                    a.correlation_id, a.created_at
             FROM audit_logs a
             WHERE a.actor_id = :actor_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ' . $limit,
            ['actor_id' => $actorId]
        );
    }

    /**
     * Recursively redact credential-shaped metadata.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT_PATTERN, $key) === 1) {
                $metadata[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = self::sanitize($value);
            } elseif (is_string($value)) {
                $metadata[$key] = mb_substr($value, 0, 500);
            } elseif (!is_scalar($value) && $value !== null) {
                $metadata[$key] = '[object]';
            }
        }

        return $metadata;
    }
}
