<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;
use Tests\TestCase;

/**
 * The route table must be registered exactly per the foundation contract §8 and
 * the visitor-access-module contract §5 (60 routes after Increment 2, plus the
 * configurable PWA manifest route = 61 total):
 * 25 foundation + 9 resident web + 1 public + 8 resident API + 9 guard web +
 * 8 guard API. `/app/visitors` is updated in place, not a new route.
 *
 * Route presence is probed with the opposite HTTP method: an existing path with
 * the wrong method returns 405, while an unregistered path returns 404.
 */
final class RouteRegistrationTest extends TestCase
{
    /** @var array<int, string> */
    private const GET_ROUTES = [
        '/',
        '/login',
        '/app',
        '/app/account',
        '/app/estates',
        '/app/no-estate',
        '/app/visitors',
        '/app/visitors/invite',
        '/app/bookings',
        '/app/passes/{uuid}',
        '/app/passes/{uuid}/qr.png',
        '/gate',
        '/gate/verify',
        '/gate/active',
        '/gate/walkin',
        '/v/{token}',
        '/health',
        '/manifest.json',
        '/api/v1/health',
        '/api/v1/auth/csrf',
        '/api/v1/auth/me',
        '/api/v1/estates',
        '/api/v1/estate',
        '/api/v1/estate/settings',
        '/api/v1/estate/audit-logs',
        '/api/v1/resident/dashboard',
        '/api/v1/resident/invitations',
        '/api/v1/resident/invitations/{uuid}',
        '/api/v1/gate/dashboard',
        '/api/v1/gate/active-visitors',
        '/api/v1/gate/pending-approvals',
    ];

    /** @var array<int, string> */
    private const POST_ROUTES = [
        '/login/otp',
        '/login/verify',
        '/logout',
        '/app/estates/{uuid}/switch',
        '/app/visitors/invite',
        '/app/passes/{uuid}/revoke',
        '/app/passes/{uuid}/extend',
        '/app/passes/{uuid}/rotate-pin',
        '/app/walkins/{uuid}/approve',
        '/app/walkins/{uuid}/reject',
        '/gate/select-gate',
        '/gate/verify',
        '/gate/checkin',
        '/gate/checkout',
        '/gate/walkin',
        '/api/v1/auth/request-otp',
        '/api/v1/auth/verify-otp',
        '/api/v1/auth/logout',
        '/api/v1/estates/{uuid}/switch',
        '/api/v1/resident/invitations',
        '/api/v1/resident/invitations/{uuid}/revoke',
        '/api/v1/resident/invitations/{uuid}/extend',
        '/api/v1/resident/invitations/{uuid}/rotate-pin',
        '/api/v1/resident/walkins/{uuid}/approve',
        '/api/v1/resident/walkins/{uuid}/reject',
        '/api/v1/gate/verify-pin',
        '/api/v1/gate/verify-code',
        '/api/v1/gate/visitors/checkin',
        '/api/v1/gate/visitors/checkout',
        '/api/v1/gate/walkins',
    ];

    public function testGetRoutesAreRegistered(): void
    {
        $this->resetSession();

        foreach (self::GET_ROUTES as $path) {
            $probe = str_replace(['{uuid}', '{token}'], [Uuid::v4(), bin2hex(random_bytes(32))], $path);

            // PUT is registered nowhere, so an existing path answers 405 while an
            // unknown path answers 404 — this works even where GET+POST share a path.
            $response = $this->request('PUT', $probe, [], [], true);

            $this->assertStatus(
                405,
                $response,
                sprintf('GET route %s is not registered (expected 405 for PUT).', $path)
            );
        }
    }

    public function testPostRoutesAreRegistered(): void
    {
        $this->resetSession();

        foreach (self::POST_ROUTES as $path) {
            $probe = str_replace('{uuid}', Uuid::v4(), $path);
            $response = $this->request('PUT', $probe, [], [], true);

            $this->assertStatus(
                405,
                $response,
                sprintf('POST route %s is not registered (expected 405 for PUT).', $path)
            );
        }
    }

    public function testRouteTableIsExactlyTheContractList(): void
    {
        $actual = [];

        foreach ($this->registeredRoutes() as $route) {
            $actual[] = $route['method'] . ' ' . $route['path'];
        }

        $expected = [];

        foreach (self::POST_ROUTES as $path) {
            $expected[] = 'POST ' . $path;
        }

        foreach (self::GET_ROUTES as $path) {
            $expected[] = 'GET ' . $path;
        }

        sort($actual);
        sort($expected);

        $this->assertCount(61, $this->registeredRoutes(), 'The route table must contain exactly 61 routes (60 contract routes + dynamic manifest).');
        $this->assertSame($expected, $actual, 'The registered route table must match the contract route list exactly.');
    }

    public function testMiddlewareAliasesAreRegistered(): void
    {
        $aliases = $this->app->middlewareResolver()->aliases();
        sort($aliases);

        $this->assertSame(['auth', 'estate', 'guest', 'permission', 'throttle'], $aliases);
    }

    public function testIncrement2RoutesCarryTheRequiredMiddleware(): void
    {
        $expected = [
            'GET /app/visitors' => ['auth', 'estate', 'permission:visitor.invitation.create'],
            'GET /app/visitors/invite' => ['auth', 'estate', 'permission:visitor.invitation.create'],
            'POST /app/visitors/invite' => ['auth', 'estate', 'permission:visitor.invitation.create', 'throttle:invite,20,600'],
            'GET /app/passes/{uuid}' => ['auth', 'estate', 'permission:visitor.invitation.view_own'],
            'GET /app/passes/{uuid}/qr.png' => ['auth', 'estate', 'permission:visitor.invitation.view_own'],
            'POST /app/passes/{uuid}/revoke' => ['auth', 'estate', 'permission:visitor.invitation.revoke'],
            'POST /app/passes/{uuid}/extend' => ['auth', 'estate', 'permission:visitor.invitation.extend'],
            'POST /app/passes/{uuid}/rotate-pin' => ['auth', 'estate', 'permission:visitor.invitation.create'],
            'GET /v/{token}' => [],
            'GET /api/v1/resident/invitations' => ['auth', 'estate', 'permission:visitor.invitation.view_own'],
            'POST /api/v1/resident/invitations' => ['auth', 'estate', 'permission:visitor.invitation.create', 'throttle:invite,20,600'],
            'POST /api/v1/resident/invitations/{uuid}/revoke' => ['auth', 'estate', 'permission:visitor.invitation.revoke'],
            'POST /api/v1/resident/walkins/{uuid}/approve' => ['auth', 'estate', 'permission:visitor.walkin.approve'],
            'POST /api/v1/resident/walkins/{uuid}/reject' => ['auth', 'estate', 'permission:visitor.walkin.reject'],
            'GET /gate' => ['auth', 'estate', 'permission:gate.view'],
            'POST /gate/select-gate' => ['auth', 'estate', 'permission:gate.view'],
            'GET /gate/verify' => ['auth', 'estate', 'permission:visitor.checkin'],
            'POST /gate/checkin' => ['auth', 'estate', 'permission:visitor.checkin'],
            'POST /gate/checkout' => ['auth', 'estate', 'permission:visitor.checkout'],
            'POST /gate/walkin' => ['auth', 'estate', 'permission:visitor.walkin.create'],
            'POST /api/v1/gate/verify-pin' => ['auth', 'estate', 'permission:visitor.checkin'],
            'POST /api/v1/gate/visitors/checkin' => ['auth', 'estate', 'permission:visitor.checkin'],
            'POST /api/v1/gate/visitors/checkout' => ['auth', 'estate', 'permission:visitor.checkout'],
            'POST /api/v1/gate/walkins' => ['auth', 'estate', 'permission:visitor.walkin.create'],
        ];

        $routes = [];

        foreach ($this->registeredRoutes() as $route) {
            $routes[$route['method'] . ' ' . $route['path']] = $route['middleware'];
        }

        foreach ($expected as $key => $middleware) {
            $this->assertArrayHasKey($key, $routes, 'Route ' . $key . ' is not registered.');
            $this->assertSame($middleware, $routes[$key], 'Wrong middleware for ' . $key . '.');
        }
    }

    public function testPlatformRoutesFileExistsAndIsEmpty(): void
    {
        $this->assertTrue(
            is_file(BASE_PATH . '/routes/platform.php'),
            'routes/platform.php must exist (contract §8) even though it registers no routes yet.'
        );

        $response = $this->get('/api/v1/platform/estates');
        $this->assertErrorCode('ROUTE_NOT_FOUND', $response, 404);
    }

    public function testUnknownRoutesReturnRouteNotFound(): void
    {
        $api = $this->get('/api/v1/definitely-not-a-route');
        $this->assertErrorCode('ROUTE_NOT_FOUND', $api, 404);

        $web = $this->get('/definitely-not-a-page');
        $this->assertStatus(404, $web);
        $this->assertBodyContains('ROUTE_NOT_FOUND', $web);
    }

    /**
     * @return array<int, array{method: string, path: string, middleware: array<int, string>}>
     */
    private function registeredRoutes(): array
    {
        $router = $this->app->router();
        $property = new \ReflectionProperty($router, 'routes');
        $routes = $property->getValue($router);

        return array_map(
            static fn (array $route): array => [
                'method' => (string) $route['method'],
                'path' => (string) $route['path'],
                'middleware' => array_values((array) ($route['middleware'] ?? [])),
            ],
            $routes
        );
    }
}
