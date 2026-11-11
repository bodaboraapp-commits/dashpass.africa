<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Generic route throttle. The spec is `name,maxAttempts,windowSeconds`, for
 * example `throttle:login,10,60`.
 *
 * This complements the domain-specific OTP limits in OtpService.
 */
final class Throttle
{
    public function __construct(
        private readonly string $name,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds
    ) {
    }

    public static function fromSpec(string $spec): self
    {
        $parts = array_map('trim', explode(',', $spec));
        $name = $parts[0] !== '' ? $parts[0] : 'default';
        $max = isset($parts[1]) ? (int) $parts[1] : 60;
        $window = isset($parts[2]) ? (int) $parts[2] : 60;

        return new self($name, max(1, $max), max(1, $window));
    }

    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        $identity = $context->auth->id();

        if ($identity === null) {
            $identity = $request->ip();
        }

        $result = $context->rateLimiter->attempt(
            'throttle:' . $this->name . ':' . $identity,
            $this->maxAttempts,
            $this->windowSeconds
        );

        if (!$result['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many attempts. Please wait a moment and try again.',
                $result['retry_after']
            );
        }

        return $next($request, $params, $context);
    }
}
