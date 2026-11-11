<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use Throwable;

/**
 * Fixed-window rate limiter backed by MySQL.
 *
 * Used to protect OTP request/verification endpoints and other sensitive
 * actions from brute force and abuse (Blueprint §41, §42). Row locks make the
 * counter atomic across concurrent gate devices.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Record a hit and report whether the caller is still within the limit.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int, hits: int}
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);

        if (!(bool) Config::get('security.rate_limits.enabled', true)) {
            return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0, 'hits' => 0];
        }

        $key = mb_substr($key, 0, 160);
        $now = Clock::now();
        $expiresAt = $now->modify('+' . $windowSeconds . ' seconds');

        [$hits, $effectiveExpiry] = $this->db->transaction(
            function (Database $db) use ($key, $now, $expiresAt): array {
                $row = $db->selectOne(
                    'SELECT hits, expires_at FROM rate_limits WHERE rl_key = :rl_key FOR UPDATE',
                    ['rl_key' => $key]
                );

                if ($row === null) {
                    $db->insert('rate_limits', [
                        'rl_key' => $key,
                        'window_started_at' => Clock::toDb($now),
                        'hits' => 1,
                        'expires_at' => Clock::toDb($expiresAt),
                    ]);

                    return [1, $expiresAt];
                }

                $currentExpiry = Clock::fromDb((string) $row['expires_at']);

                if ($currentExpiry === null || $currentExpiry <= $now) {
                    $db->update(
                        'rate_limits',
                        [
                            'window_started_at' => Clock::toDb($now),
                            'hits' => 1,
                            'expires_at' => Clock::toDb($expiresAt),
                        ],
                        ['rl_key' => $key]
                    );

                    return [1, $expiresAt];
                }

                $hits = (int) $row['hits'] + 1;
                $db->execute('UPDATE rate_limits SET hits = :hits WHERE rl_key = :rl_key', [
                    'hits' => $hits,
                    'rl_key' => $key,
                ]);

                return [$hits, $currentExpiry];
            }
        );

        $allowed = $hits <= $maxAttempts;
        $remaining = max(0, $maxAttempts - $hits);
        $retryAfter = $allowed ? 0 : max(1, self::secondsUntil($effectiveExpiry));

        if (!$allowed) {
            Logger::security('Rate limit exceeded', [
                'limit_key' => $key,
                'hits' => $hits,
                'max' => $maxAttempts,
            ]);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'hits' => $hits,
        ];
    }

    /**
     * Time in seconds until the current window expires (for Retry-After hints).
     */
    public function secondsRemaining(string $key): int
    {
        $row = $this->db->selectOne('SELECT expires_at FROM rate_limits WHERE rl_key = :rl_key', ['rl_key' => $key]);

        if ($row === null) {
            return 0;
        }

        return self::secondsUntil(Clock::fromDb((string) $row['expires_at']));
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE rl_key = :rl_key', ['rl_key' => $key]);
    }

    public function clearPrefix(string $prefix): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE rl_key LIKE :prefix', ['prefix' => $prefix . '%']);
    }

    public function pruneExpired(): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE expires_at < :now', ['now' => Clock::nowDb()]);
    }

    private static function secondsUntil(?DateTimeImmutable $moment): int
    {
        if ($moment === null) {
            return 0;
        }

        try {
            return max(0, $moment->getTimestamp() - Clock::now()->getTimestamp());
        } catch (Throwable) {
            return 0;
        }
    }
}
