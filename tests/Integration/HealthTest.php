<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Health endpoint (contract §9): direct JSON (no envelope), 200 when healthy,
 * migration/storage checks and no leaked configuration.
 */
final class HealthTest extends IntegrationTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function healthPayload(string $path): array
    {
        $response = $this->get($path);
        $this->assertStatus(200, $response, 'The health endpoint must return 200 when the system is healthy.');
        $this->assertHeader('Content-Type', 'application/json; charset=utf-8', $response);

        $payload = $this->json($response);

        $this->assertFalse(
            array_key_exists('success', $payload),
            'The health endpoint returns its payload directly, without the API envelope.'
        );

        return $payload;
    }

    public function testHealthEndpointShape(): void
    {
        $payload = $this->healthPayload('/health');

        $this->assertSame('ok', $payload['status'] ?? null);
        $this->assertSame('MtaaPass', $payload['app'] ?? null);
        $this->assertSame(
            (string) config('app.version'),
            $payload['version'] ?? null,
            'The health endpoint must report the configured application version.'
        );
        $this->assertMatches('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', (string) ($payload['version'] ?? ''));
        $this->assertSame('testing', $payload['environment'] ?? null);
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', (string) ($payload['time_utc'] ?? ''));
    }

    public function testHealthChecksDatabaseMigrationsAndStorage(): void
    {
        $payload = $this->healthPayload('/health');

        $this->assertArrayHasKey('checks', $payload);
        $checks = $payload['checks'];

        $this->assertSame('ok', $checks['database']['status'] ?? null);
        $this->assertTrue(is_numeric($checks['database']['latency_ms'] ?? null), 'database.latency_ms must be numeric.');
        $this->assertTrue(((float) $checks['database']['latency_ms']) >= 0);

        $this->assertSame('ok', $checks['migrations']['status'] ?? null);
        $this->assertSame(11, (int) ($checks['migrations']['applied'] ?? -1));
        $this->assertSame(0, (int) ($checks['migrations']['pending'] ?? -1));

        $this->assertSame('ok', $checks['storage']['status'] ?? null);
    }

    public function testApiHealthEndpointMatchesWebHealth(): void
    {
        $api = $this->healthPayload('/api/v1/health');
        $web = $this->healthPayload('/health');

        $this->assertSame($web['app'], $api['app']);
        $this->assertSame($web['environment'], $api['environment']);
        $this->assertSame($web['checks']['migrations'], $api['checks']['migrations']);
    }

    public function testHealthDoesNotExposeSecretsOrPaths(): void
    {
        $response = $this->get('/health');
        $body = $response->body();

        foreach (['password', 'APP_KEY', 'DB_USERNAME', 'base64:', 'storage\\\\', 'C:\\\\', 'root'] as $forbidden) {
            $this->assertFalse(
                str_contains($body, $forbidden),
                sprintf('The health response must not expose "%s".', $forbidden)
            );
        }
    }

    public function testHealthIsReadOnlyAndRejectsOtherMethods(): void
    {
        // CSRF is exempt for the health path, so a POST reaches the router and
        // must be rejected as a method error — proving only GET is exposed.
        $response = $this->post('/health', [], false);

        $this->assertStatus(405, $response, 'Only GET is allowed on /health.');
        $this->assertBodyContains('METHOD_NOT_ALLOWED', $response);
        $this->assertBodyContains('405', $response);
    }

    public function testPublicHealthIsTrimmedAndTokenGatedDetailsWork(): void
    {
        $originalEnv = getenv('HEALTH_DETAIL_TOKEN');
        putenv('HEALTH_DETAIL_TOKEN=qa-health-token');

        try {
            \App\Core\Config::set('app.env', 'production');
            $publicResponse = $this->get('/health');
            $this->assertStatus(200, $publicResponse);
            $public = $this->json($publicResponse);
            $this->assertSame(['status', 'app', 'version'], array_keys($public), 'Public health exposes only status/app/version.');
            $this->assertFalse(array_key_exists('checks', $public), 'Public health must not leak checks.');
            $this->assertSame('', (string) $publicResponse->header('X-Powered-By'), 'No X-Powered-By header is emitted.');

            $gated = $this->get('/health', false, ['X-Health-Token' => 'qa-health-token']);
            $this->assertStatus(200, $gated);
            $this->assertArrayHasKey('checks', $this->json($gated), 'A valid token unlocks the full checks payload.');

            $wrong = $this->get('/health', false, ['X-Health-Token' => 'nope']);
            $this->assertFalse(array_key_exists('checks', $this->json($wrong)), 'An invalid token keeps the trimmed payload.');
        } finally {
            \App\Core\Config::set('app.env', 'testing');
            if ($originalEnv === false) { putenv('HEALTH_DETAIL_TOKEN'); } else { putenv('HEALTH_DETAIL_TOKEN=' . $originalEnv); }
        }
    }
}
