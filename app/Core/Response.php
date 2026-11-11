<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object. Handlers return one of these and the front
 * controller sends it, which keeps the pipeline testable in-process.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        $response = new self($body, $status, $headers);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response = new self($body, $status, $headers);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Standard success envelope (Blueprint §32).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(string $message, array $data = [], int $status = 200, array $meta = []): self
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => (object) $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    /**
     * Standard error envelope (Blueprint §32).
     *
     * @param array<string, mixed> $extra
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $extra = [],
        ?string $correlationId = null
    ): self {
        $error = ['code' => $code, 'message' => $message];

        if ($extra !== []) {
            $error = array_merge($error, $extra);
        }

        $payload = ['success' => false, 'error' => $error];

        if ($correlationId !== null) {
            $payload['meta'] = ['correlation_id' => $correlationId];
        }

        return self::json($payload, $status);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status))->withHeader('Location', $location);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Emit the response to the client (front controller only).
     */
    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
