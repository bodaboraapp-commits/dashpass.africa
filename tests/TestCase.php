<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use RuntimeException;

/**
 * Raised by the TestCase assertions. The runner reports the message verbatim.
 */
class AssertionFailed extends RuntimeException
{
}

/**
 * Base class for every MtaaPass test: assertions, in-process HTTP helpers,
 * session helpers and seeded-row lookups.
 *
 * Requests are dispatched through the real application kernel
 * (`$app->handle()`), including global CSRF, middleware and the central error
 * handler, so tests exercise production behaviour without a web server.
 */
abstract class TestCase
{
    protected App $app;

    protected Database $db;

    public function __construct(?App $app = null)
    {
        $app ??= $GLOBALS['MTAAPASS_TEST_APP'] ?? null;

        if (!$app instanceof App) {
            throw new RuntimeException('TestCase requires the booted App instance.');
        }

        $this->app = $app;
        $this->db = Database::instance();
    }

    /**
     * Runs before every test method.
     */
    public function setUp(): void
    {
        $this->resetSession();
        Logger::setCorrelationId(null);
    }

    /**
     * Runs after every test method, even when it failed.
     */
    public function tearDown(): void
    {
        $this->resetSession();
    }

    // -------------------------------------------------------------------------
    // Database helpers
    // -------------------------------------------------------------------------

    /**
     * Drop and recreate the test database schema, then run the idempotent
     * seeder. Integration and security tests call this in setUp() so every test
     * starts from the exact contract seed state.
     */
    protected function freshDatabase(): void
    {
        TestDatabase::freshAndSeed();
        $this->resetSession();
        Logger::setCorrelationId(null);
    }

    /**
     * Clear every OTP/throttle fixed window so a test starts with no exhausted
     * limits, regardless of what earlier tests did.
     */
    protected function clearRateLimits(): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->clearPrefix('otp:');
        $limiter->clearPrefix('throttle:');
    }

    /**
     * @return array<string, mixed>
     */
    protected function seededUser(string $phone): array
    {
        $normalized = \App\Core\Phone::normalize($phone);
        $row = $this->db->selectOne(
            'SELECT id, uuid, full_name, phone, email, status FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => $normalized]
        );

        if ($row === null) {
            $this->fail(sprintf('Seeded user with phone %s was not found in the database.', $normalized));
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function seededEstate(string $slug): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, slug, estate_type, timezone, status FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => $slug]
        );

        if ($row === null) {
            $this->fail(sprintf('Seeded estate with slug "%s" was not found in the database.', $slug));
        }

        return $row;
    }

    // -------------------------------------------------------------------------
    // Session + CSRF helpers
    // -------------------------------------------------------------------------

    public function resetSession(): void
    {
        $_SESSION = [];
    }

    protected function csrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * Switch the active estate for the already-authenticated test session.
     */
    protected function switchTo(string $phone, string $estateSlug): void
    {
        $estate = $this->seededEstate($estateSlug);

        $response = $this->post('/api/v1/estates/' . $estate['uuid'] . '/switch', [], true);

        $this->assertSuccess($response, sprintf('Could not switch %s to estate "%s".', $phone, $estateSlug));
    }

    /**
     * Perform the full development OTP login flow for a seeded phone number and
     * leave the test session authenticated.
     */
    protected function loginAs(string $phone): void
    {
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => $phone], true);
        $this->assertSuccess($request, 'OTP request failed for ' . $phone);

        $payload = $this->json($request);
        $code = $payload['data']['dev_otp'] ?? null;

        if (!is_string($code) || $code === '') {
            $this->fail(
                'The development OTP was not exposed for ' . $phone
                . ' (SMS_DEV_EXPOSE_OTP must be true in tests). Body: ' . $this->body($request)
            );
        }

        $verify = $this->post('/api/v1/auth/verify-otp', ['phone' => $phone, 'code' => $code], true);
        $this->assertSuccess($verify, 'OTP verification failed for ' . $phone);
    }

    /**
     * Log the current test session out through the API.
     */
    protected function logout(): void
    {
        $response = $this->post('/api/v1/auth/logout', [], true);
        $this->assertStatus(204, $response, 'API logout should return 204 No Content.');
    }

    // -------------------------------------------------------------------------
    // HTTP helpers (dispatched in-process through the real kernel)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, bool $withCsrf = false, array $headers = []): Response
    {
        return $this->request('GET', $path, [], $headers, $withCsrf);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $body = [], bool $withCsrf = true, array $headers = []): Response
    {
        return $this->request('POST', $path, $body, $headers, $withCsrf);
    }

    /**
     * Dispatch a request through the application kernel.
     *
     * JSON is sent for `/api/` paths (as the PWA client does) and ordinary form
     * fields for browser routes, so both content paths are covered. A query
     * string embedded in `$path` is parsed into the Request query bag (the
     * kernel's Request value object keeps query/post/json separate).
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        bool $withCsrf = false,
        ?string $userAgent = null,
        array $files = [],
        ?string $rawBodyPayload = null
    ): Response {
        if ($withCsrf) {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        $parts = parse_url($path);
        $requestPath = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $query = [];

        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $post = [];
        $rawBody = $rawBodyPayload ?? '';

        if ($body !== []) {
            if (str_starts_with($requestPath, '/api/')) {
                $rawBody = (string) json_encode(
                    $body,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                $headers['Content-Type'] = 'application/json';
            } else {
                $post = $body;
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        $request = new Request(
            strtoupper($method),
            Request::normalizePath($requestPath),
            $query,
            $post,
            $headers,
            $rawBody,
            '127.0.0.1',
            $userAgent ?? 'MtaaPass-Test/1.0',
            $files
        );

        return $this->app->handle($request);
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $body = $response->body();

        if (trim($body) === '') {
            throw new AssertionFailed(sprintf(
                'Expected a JSON body but the response was empty (status %d, headers: %s).',
                $response->status(),
                (string) json_encode($response->headers())
            ));
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AssertionFailed(sprintf(
                'Expected valid JSON (status %d) but got: %s',
                $response->status(),
                $this->excerpt($body)
            ));
        }

        if (!is_array($decoded)) {
            throw new AssertionFailed('Expected a JSON object/array body, got: ' . $this->excerpt($body));
        }

        return $decoded;
    }

    protected function body(Response $response): string
    {
        return $response->body();
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertSuccess(Response $response, string $message = ''): array
    {
        if ($response->status() < 200 || $response->status() >= 300) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : 'Expected a successful response.',
                sprintf('Got HTTP %d', $response->status()),
                $response
            ));
        }

        $payload = $this->json($response);

        if (($payload['success'] ?? null) !== true) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : 'Expected success=true in the response envelope.',
                'Payload: ' . $this->excerpt((string) json_encode($payload)),
                $response
            ));
        }

        if (!array_key_exists('data', $payload) || !is_array($payload['data'])) {
            throw new AssertionFailed($this->withContext(
                'Expected a `data` object in the success envelope.',
                'Payload: ' . $this->excerpt((string) json_encode($payload)),
                $response
            ));
        }

        return $payload;
    }

    /**
     * Assert the API error envelope carries the given error code.
     *
     * @return array<string, mixed> Decoded payload for further assertions.
     */
    protected function assertErrorCode(string $expectedCode, Response $response, ?int $expectedStatus = null): array
    {
        if ($expectedStatus !== null && $response->status() !== $expectedStatus) {
            throw new AssertionFailed($this->withContext(
                sprintf('Expected HTTP %d for error code %s.', $expectedStatus, $expectedCode),
                sprintf('Got HTTP %d', $response->status()),
                $response
            ));
        }

        $payload = $this->json($response);

        if (($payload['success'] ?? null) !== false) {
            throw new AssertionFailed($this->withContext(
                'Expected success=false in the error envelope.',
                'Payload: ' . $this->excerpt((string) json_encode($payload)),
                $response
            ));
        }

        $actual = $payload['error']['code'] ?? null;

        if ($actual !== $expectedCode) {
            throw new AssertionFailed($this->withContext(
                sprintf('Expected error code "%s" but got "%s".', $expectedCode, is_scalar($actual) ? (string) $actual : 'null'),
                '',
                $response
            ));
        }

        if (!isset($payload['error']['message']) || !is_string($payload['error']['message'])) {
            throw new AssertionFailed('The error envelope must contain a string `error.message`.');
        }

        return $payload;
    }

    protected function assertStatus(int $expected, Response $response, string $message = ''): void
    {
        if ($response->status() !== $expected) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : sprintf('Expected HTTP %d.', $expected),
                sprintf('Got HTTP %d', $response->status()),
                $response
            ));
        }
    }

    protected function assertRedirect(Response $response, string $expectedLocation, ?int $expectedStatus = null): void
    {
        if ($expectedStatus !== null) {
            $this->assertStatus($expectedStatus, $response);
        } elseif (!in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            throw new AssertionFailed($this->withContext(
                'Expected a redirect response.',
                sprintf('Got HTTP %d', $response->status()),
                $response
            ));
        }

        $location = $response->header('Location');

        if ($location !== $expectedLocation) {
            throw new AssertionFailed(sprintf(
                'Expected redirect to "%s" but got "%s".',
                $expectedLocation,
                $location ?? 'no Location header'
            ));
        }
    }

    protected function assertHeader(string $name, string $expected, Response $response): void
    {
        $actual = $response->header($name);

        if ($actual !== $expected) {
            throw new AssertionFailed(sprintf(
                'Expected header %s "%s" but got "%s".',
                $name,
                $expected,
                $actual ?? 'missing'
            ));
        }
    }

    protected function assertBodyContains(string $needle, Response $response, string $message = ''): void
    {
        if (!str_contains($response->body(), $needle)) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : sprintf('Expected the response body to contain "%s".', $needle),
                '',
                $response
            ));
        }
    }

    protected function assertBodyNotContains(string $needle, Response $response, string $message = ''): void
    {
        if (str_contains($response->body(), $needle)) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : sprintf('Expected the response body NOT to contain "%s".', $needle),
                '',
                $response
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Markup helpers
    //
    // Layout-refresh tests assert structure, accessible names and exact labels.
    // Parsing the rendered HTML with DOMDocument keeps those assertions robust
    // against whitespace, attribute order and CSS class-name churn.
    // -------------------------------------------------------------------------

    protected function dom(Response $response): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $response->body(),
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new AssertionFailed($this->withLocation('The rendered response is not parseable HTML.'));
        }

        return $dom;
    }

    /**
     * @return \DOMNodeList<\DOMNode>
     */
    protected function xpath(string $expression, \DOMDocument|\DOMNode $context): \DOMNodeList
    {
        $document = $context instanceof \DOMDocument ? $context : $context->ownerDocument;

        if (!$document instanceof \DOMDocument) {
            throw new AssertionFailed($this->withLocation('Cannot run XPath without a document.'));
        }

        $nodes = (new \DOMXPath($document))->query(
            $expression,
            $context instanceof \DOMDocument ? null : $context
        );

        if ($nodes === false) {
            throw new AssertionFailed($this->withLocation('Invalid XPath expression: ' . $expression));
        }

        return $nodes;
    }

    /**
     * Normalised text content of a node (collapses whitespace, decodes entities).
     */
    protected function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) ($node->textContent ?? '')));
    }

    /**
     * Normalised visible text of the whole rendered page.
     */
    protected function pageText(Response $response): string
    {
        return $this->text($this->dom($response)->documentElement);
    }

    protected function assertTextContains(string $needle, Response $response, string $message = ''): void
    {
        if (!str_contains($this->pageText($response), $needle)) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : sprintf('Expected the page text to contain "%s".', $needle),
                '',
                $response
            ));
        }
    }

    protected function assertTextNotContains(string $needle, Response $response, string $message = ''): void
    {
        if (str_contains($this->pageText($response), $needle)) {
            throw new AssertionFailed($this->withContext(
                $message !== '' ? $message : sprintf('Expected the page text NOT to contain "%s".', $needle),
                '',
                $response
            ));
        }
    }

    /**
     * CSP / addendum §6 markup rules: no inline script, no inline styles, no
     * inline event handlers and no external http(s) assets.
     *
     * @return array<int, string> Human-readable violations (empty when clean).
     */
    protected function markupViolations(Response $response): array
    {
        $dom = $this->dom($response);
        $violations = [];

        foreach ($this->xpath('//script', $dom) as $script) {
            if (!$script instanceof \DOMElement) {
                continue;
            }

            $src = trim($script->getAttribute('src'));

            if ($src === '') {
                $violations[] = 'inline <script> without src';
            } elseif (preg_match('#^https?://#i', $src) === 1) {
                $violations[] = 'external script src: ' . $src;
            }
        }

        foreach ($this->xpath('//@*', $dom) as $attribute) {
            if (!$attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->name);

            if ($name === 'style') {
                $violations[] = sprintf('inline style attribute on <%s>', $attribute->ownerElement?->tagName ?? '?');
            } elseif (str_starts_with($name, 'on')) {
                $violations[] = sprintf('inline event handler %s on <%s>', $name, $attribute->ownerElement?->tagName ?? '?');
            }
        }

        foreach ($this->xpath('//*[@src or @href or @action]', $dom) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            foreach (['src', 'href', 'action'] as $attribute) {
                $value = trim($node->getAttribute($attribute));

                if ($value !== '' && preg_match('#^https?://#i', $value) === 1) {
                    $violations[] = sprintf('external %s on <%s>: %s', $attribute, $node->tagName, $value);
                }
            }
        }

        return array_values(array_unique($violations));
    }

    protected function assertCleanMarkup(Response $response, string $context = ''): void
    {
        $violations = $this->markupViolations($response);

        if ($violations !== []) {
            throw new AssertionFailed($this->withLocation(sprintf(
                '%s markup violates the CSP/no-inline rule: %s',
                $context !== '' ? $context : 'Page',
                implode('; ', $violations)
            )));
        }
    }

    // -------------------------------------------------------------------------
    // Generic assertions
    // -------------------------------------------------------------------------

    protected function assertTrue(mixed $condition, string $message = ''): void
    {
        if ($condition !== true) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected true but got ' . $this->export($condition) . '.'
            ));
        }
    }

    protected function assertFalse(mixed $condition, string $message = ''): void
    {
        if ($condition !== false) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected false but got ' . $this->export($condition) . '.'
            ));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected null but got ' . $this->export($value) . '.'
            ));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new AssertionFailed($this->withLocation($message !== '' ? $message : 'Expected a non-null value.'));
        }
    }

    protected function assertNotEmpty(mixed $value, string $message = ''): void
    {
        if ($value === null || $value === '' || $value === []) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected a non-empty value.'
            ));
        }
    }

    protected function assertNotFalse(mixed $value, string $message = ''): void
    {
        if ($value === false) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected a value that is not false.'
            ));
        }
    }

    protected function assertEmpty(mixed $value, string $message = ''): void
    {
        if (!($value === null || $value === '' || $value === [])) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected an empty value but got ' . $this->export($value) . '.'
            ));
        }
    }

    protected function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (array_key_exists($key, $array)) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Did not expect array key ' . $key . ' to exist.'
            ));
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected the string to contain "' . $needle . '".'
            ));
        }
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected the string NOT to contain "' . $needle . '".'
            ));
        }
    }

    protected function assertStringStartsWith(string $prefix, string $haystack, string $message = ''): void
    {
        if (!str_starts_with($haystack, $prefix)) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected the string to start with "' . $prefix . '".'
            ));
        }
    }

    protected function assertStringEndsWith(string $suffix, string $haystack, string $message = ''): void
    {
        if (!str_ends_with($haystack, $suffix)) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected the string to end with "' . $suffix . '".'
            ));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($expected) . ' but got ' . $this->export($actual) . '.'
            ));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '') . 'Expected a value different from ' . $this->export($actual) . '.'
            ));
        }
    }

    protected function assertContains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        $found = is_array($haystack)
            ? in_array($needle, $haystack, true)
            : (is_string($haystack) && is_scalar($needle) && str_contains($haystack, (string) $needle));

        if (!$found) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($needle) . ' to be in ' . $this->export($haystack) . '.'
            ));
        }
    }

    protected function assertNotContains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        $found = is_array($haystack)
            ? in_array($needle, $haystack, true)
            : (is_string($haystack) && is_scalar($needle) && str_contains($haystack, (string) $needle));

        if ($found) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($needle) . ' NOT to be in ' . $this->export($haystack) . '.'
            ));
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected array key ' . $this->export($key) . ' in ' . $this->export(array_keys($array)) . '.'
            ));
        }
    }

    protected function assertCount(int $expected, array|\Countable $haystack, string $message = ''): void
    {
        $actual = is_array($haystack) ? count($haystack) : $haystack->count();

        if ($actual !== $expected) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected count ' . $expected . ' but got ' . $actual . ' (' . $this->export($haystack) . ').'
            ));
        }
    }

    protected function assertLessThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (!($actual < $expected)) {
            throw new AssertionFailed($this->withLocation(($message !== '' ? $message . ' ? ' : '') . 'Expected ' . $actual . ' to be less than ' . $expected . '.'));
        }
    }

    protected function assertLessThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (!($actual <= $expected)) {
            throw new AssertionFailed($this->withLocation(($message !== '' ? $message . ' ? ' : '') . 'Expected ' . $actual . ' to be at most ' . $expected . '.'));
        }
    }

    protected function assertMatches(string $pattern, string $actual, string $message = ''): void
    {
        if (preg_match($pattern, $actual) !== 1) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($actual) . ' to match ' . $pattern . '.'
            ));
        }
    }

    protected function assertMatchesRegularExpression(string $pattern, string $actual, string $message = ''): void
    {
        $this->assertMatches($pattern, $actual, $message);
    }

    protected function assertDoesNotMatch(string $pattern, string $actual, string $message = ''): void
    {
        if (preg_match($pattern, $actual) === 1) {
            throw new AssertionFailed($this->withLocation(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($actual) . ' NOT to match ' . $pattern . '.'
            ));
        }
    }

    protected function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $class) {
            throw new AssertionFailed($this->withLocation(
                $message !== '' ? $message : 'Expected an instance of ' . $class . ' but got ' . $this->export($value) . '.'
            ));
        }
    }

    protected function fail(string $message = 'Test failed.'): never
    {
        throw new AssertionFailed($this->withLocation($message));
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    private function withLocation(string $message): string
    {
        return $message . ' [' . $this->callerLocation() . ']';
    }

    private function withContext(string $message, string $context, Response $response): string
    {
        $parts = [$message];

        if ($context !== '') {
            $parts[] = $context;
        }

        $parts[] = 'body: ' . $this->excerpt($response->body());

        return $this->withLocation(implode(' | ', $parts));
    }

    private function callerLocation(): string
    {
        $self = realpath(__FILE__) ?: __FILE__;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            $file = $frame['file'] ?? '';

            if ($file === '') {
                continue;
            }

            $real = realpath($file) ?: $file;

            if ($real === $self) {
                continue;
            }

            $relative = str_starts_with($real, BASE_PATH . DIRECTORY_SEPARATOR)
                ? substr($real, strlen(BASE_PATH) + 1)
                : $real;

            return str_replace('\\', '/', $relative) . ':' . ($frame['line'] ?? '?');
        }

        return 'unknown location';
    }

    private function excerpt(string $value, int $limit = 500): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strlen($value) > $limit ? substr($value, 0, $limit) . '…' : $value;
    }

    private function export(mixed $value): string
    {
        if (is_string($value)) {
            $value = strlen($value) > 120 ? substr($value, 0, 120) . '…' : $value;

            return '"' . $value . '"';
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                return 'array(' . count($value) . ')';
            }

            return strlen($json) > 300 ? substr($json, 0, 300) . '…' : $json;
        }

        if (is_object($value)) {
            return $value::class;
        }

        return var_export($value, true);
    }
}
