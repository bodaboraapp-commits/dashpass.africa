<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application error type that maps cleanly onto an HTTP response.
 *
 * Internal exception messages are never shown to users; only the explicit
 * public message and error code are.
 */
class HttpException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $extra;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $publicMessage,
        array $extra = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($publicMessage, 0, $previous);
        $this->extra = $extra;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Authentication is required.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $code = 'NOT_FOUND', string $message = 'The requested resource was not found.'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function tooManyRequests(string $message = 'Too many requests. Please try again shortly.', int $retryAfter = 60): self
    {
        return new self(429, 'RATE_LIMITED', $message, ['retry_after' => $retryAfter]);
    }
}
