<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

/**
 * Immutable-ish HTTP request value object.
 *
 * Requests are constructed from PHP globals in production and built directly
 * in tests, so the whole HTTP pipeline can be exercised without a web server.
 */
final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    private ?string $rawBody;

    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $query = [],
        array $post = [],
        array $headers = [],
        ?string $rawBody = null,
        private readonly string $ip = '127.0.0.1',
        private readonly string $userAgent = '',
        private array $files = []
    ) {
        $this->query = $query;
        $this->post = $post;
        $this->rawBody = $rawBody;
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $rawBody = file_get_contents('php://input');

        return new self(
            $method,
            self::normalizePath($path),
            $_GET,
            $_POST,
            $headers,
            $rawBody === false ? null : $rawBody,
            self::clientIp($headers),
            trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            $_FILES
        );
    }

    /**
     * Build a request directly (tests, internal dispatch).
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public static function create(
        string $method,
        string $path,
        array $post = [],
        array $headers = [],
        string $rawBody = '',
        string $ip = '127.0.0.1',
        string $userAgent = 'MtaaPass-Test/1.0'
    ): self {
        return new self(
            strtoupper($method),
            self::normalizePath($path),
            [],
            $post,
            $headers,
            $rawBody,
            $ip,
            $userAgent
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(rawurldecode(explode('?', $path, 2)[0]), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        return isset($this->files[$key]) && is_array($this->files[$key]) ? $this->files[$key] : null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return mb_substr($this->userAgent, 0, 255);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        return str_contains((string) $this->header('content-type', ''), 'application/json');
    }

    public function isForm(): bool
    {
        return str_contains((string) $this->header('content-type', ''), 'application/x-www-form-urlencoded')
            || str_contains((string) $this->header('content-type', ''), 'multipart/form-data');
    }

    public function expectsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }

        if ($this->isJson()) {
            return true;
        }

        return str_contains((string) $this->header('accept', ''), 'application/json');
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException When the body claims to be JSON but is malformed.
     */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        if ($this->rawBody === null || trim($this->rawBody) === '') {
            return $this->jsonCache = [];
        }

        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('INVALID_JSON', 'The request body is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw HttpException::badRequest('INVALID_JSON', 'The request body must be a JSON object.');
        }

        return $this->jsonCache = $decoded;
    }

    /**
     * All request input, with JSON bodies taking precedence for API calls.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->isJson()) {
            return $this->json();
        }

        if ($this->rawBody !== null && trim($this->rawBody) !== '' && $this->expectsJson()) {
            return $this->json();
        }

        return $this->post;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key, $default);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryString(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function fullUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . $this->path
            . ($this->query === [] ? '' : '?' . http_build_query($this->query));
    }

    /**
     * Only trust a proxy header when the deployment documents a trusted proxy;
     * for the MVP the direct connection address is used.
     *
     * @param array<string, string> $headers
     */
    private static function clientIp(array $headers): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '127.0.0.1';
    }
}
