<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;
use Tests\TestCase;

/**
 * The route table must be registered exactly per the foundation contract §8, the
 * visitor-access-module contract §5 and the vehicles-parking contract §3:
 * **165 routes** after Increment 10 (25 foundation + 35 Increment 2 + 12
 * vehicles/parking + 1 configurable PWA manifest route). `/app/visitors` and
 * `/app/bookings` are updated in place, not new routes.
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
        '/app/activity',
        '/app/access',
        '/app/access/qr.png',
        '/app/access/member',
        '/app/estates',
        '/app/no-estate',
        '/app/visitors',
        '/app/visitors/invite',
        '/app/bookings',
        '/app/vehicles',
        '/app/parking',
        '/manifest.json',
        '/app/passes/{uuid}',
        '/app/passes/{uuid}/qr.png',
        '/gate',
        '/gate/verify',
        '/gate/active',
        '/gate/walkin',
        '/v/{token}',
        '/health',
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
        '/api/v1/resident/vehicles',
        '/api/v1/resident/parking',
        '/api/v1/parking/availability',
        '/api/v1/gate/parking/active',
        '/admin',
        '/admin/buildings',
        '/admin/buildings/{uuid}',
        '/admin/households',
        '/admin/households/{uuid}',
        '/admin/residents',
        '/admin/guards',
        '/admin/gates',
        '/admin/settings',
        '/app/staff',
        '/app/incidents',
        '/app/incidents/new',
        '/gate/incidents',
        '/gate/incidents/new',
        '/admin/staff',
        '/admin/incidents',
        '/admin/incidents/{uuid}',
        '/api/v1/resident/staff',
        '/api/v1/resident/incidents',
        '/api/v1/resident/incidents/{uuid}',
        '/api/v1/admin/incidents',
        '/api/v1/admin/incidents/{uuid}',
        '/app/notices',
        '/app/notices/{uuid}',
        '/gate/notices',
        '/admin/notices',
        '/admin/notices/new',
        '/admin/notices/{uuid}',
        '/admin/parking',
        '/admin/parking/zones',
        '/admin/parking/bays',
        '/admin/parking/allocations',
        '/admin/parking/violations',
        '/gate/parking/violations',
        '/platform',
        '/platform/estates',
        '/platform/estates/new',
        '/platform/estates/{uuid}',
        '/platform/plans',
        '/platform/subscriptions',
        '/platform/billing',
        '/platform/billing/invoices/new',
        '/platform/billing/invoices/{uuid}',
        '/notifications',
        '/platform/settings/notifications',
        '/platform/settings/payments',
        '/platform/billing/automation',
        '/pay/{token}',
        '/app/bills',
        '/app/bills/{uuid}',
        '/admin/utility-bills',
        '/app/navigation',
        '/notices/{uuid}/image',
        '/app/incidents/{uuid}',
        '/platform/billing/schedules',
        '/platform/billing/schedules/new',
        '/platform/billing/schedules/{uuid}',
        '/admin/billing',
        '/admin/billing/invoices/{uuid}',
        '/admin/billing/reports',
        '/admin/billing/export.csv',
        '/about',
        '/blog',
        '/blog/{slug}',
        '/help',
        '/privacy',
        '/terms',
        '/data-policy',
        '/privacy/choices',
        '/data-deletion',
        '/sitemap.xml',
        '/robots.txt',
        '/llms.txt',
        '/llms-full.txt',
        '/feed.xml',
    ];

    /** @var array<int, string> */
    private const POST_ROUTES = [
        '/login/check-phone',
        '/login/otp',
        '/login/verify',
        '/logout',
        '/app/access/member',
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
        '/app/vehicles',
        '/app/vehicles/{uuid}/deactivate',
        '/api/v1/resident/vehicles',
        '/api/v1/resident/vehicles/{uuid}/deactivate',
        '/api/v1/gate/parking/sessions/checkin',
        '/api/v1/gate/parking/sessions/checkout',
        '/admin/buildings',
        '/admin/buildings/{uuid}',
        '/admin/households',
        '/admin/households/{uuid}',
        '/admin/households/{uuid}/archive',
        '/admin/households/{uuid}/members',
        '/admin/households/{uuid}/members/{memberUuid}/remove',
        '/admin/residents',
        '/admin/residents/{uuid}/suspend',
        '/admin/residents/{uuid}/reactivate',
        '/admin/guards',
        '/admin/guards/{uuid}/gates',
        '/admin/guards/{uuid}/archive',
        '/admin/gates',
        '/admin/gates/{uuid}',
        '/admin/gates/{uuid}/archive',
        '/admin/settings',
        '/app/staff',
        '/app/staff/{uuid}/deactivate',
        '/app/staff/{uuid}/reactivate',
        '/app/incidents',
        '/gate/incidents',
        '/admin/incidents/{uuid}/status',
        '/api/v1/resident/staff',
        '/api/v1/resident/incidents',
        '/admin/notices',
        '/admin/notices/{uuid}',
        '/admin/notices/{uuid}/publish',
        '/admin/notices/{uuid}/archive',
        '/admin/parking/zones',
        '/admin/parking/zones/{uuid}',
        '/admin/parking/bays',
        '/admin/parking/bays/{uuid}',
        '/admin/parking/bays/{uuid}/archive',
        '/admin/parking/allocations',
        '/admin/parking/allocations/{uuid}/end',
        '/admin/parking/violations',
        '/admin/parking/violations/{uuid}/resolve',
        '/gate/parking/violations',
        '/platform/estates',
        '/platform/estates/{uuid}/status',
        '/platform/plans',
        '/platform/plans/{uuid}/retire',
        '/platform/subscriptions/{uuid}',
        '/platform/billing/invoices',
        '/platform/billing/invoices/{uuid}/status',
        '/platform/billing/invoices/{uuid}/payments',
        '/platform/billing/payments/{uuid}/status',
        '/notifications/{uuid}/read',
        '/platform/settings/notifications',
        '/platform/settings/payments',
        '/platform/billing/pay-links',
        '/webhooks/payments/mpesa',
        '/webhooks/payments/card',
        '/app/bills/{uuid}/pay-link',
        '/platform/billing/schedules',
        '/platform/billing/schedules/{uuid}/edit',
        '/platform/billing/schedules/{uuid}/toggle',
        '/platform/billing/schedules/{uuid}/run-now',
        '/help/contact',
        '/privacy/choices',
    ];

    /** @var array<int, string> */
    private const PATCH_ROUTES = [
        '/api/v1/admin/incidents/{uuid}',
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

    public function testPatchRoutesAreRegistered(): void
    {
        $this->resetSession();

        foreach (self::PATCH_ROUTES as $path) {
            $probe = str_replace('{uuid}', Uuid::v4(), $path);

            // PUT is registered nowhere, so a PATCH-only path answers 405.
            $response = $this->request('PUT', $probe, [], [], true);
            $this->assertStatus(405, $response, sprintf('PATCH route %s is not registered (expected 405 for PUT).', $path));
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

        foreach (self::PATCH_ROUTES as $path) {
            $expected[] = 'PATCH ' . $path;
        }

        foreach (self::GET_ROUTES as $path) {
            $expected[] = 'GET ' . $path;
        }

        sort($actual);
        sort($expected);

        $this->assertCount(216, $this->registeredRoutes(), 'Public legal/privacy pages plus the personal access pass page, its QR image route and the household-member pass GET/POST (214 + 2) = 216.');
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
            // Public marketing pages: guest-accessible, the contact POST is throttled.
            'GET /about' => [],
            'GET /blog' => [],
            'GET /blog/{slug}' => [],
            'GET /help' => [],
            'GET /privacy' => [],
            'GET /terms' => [],
            'GET /data-policy' => [],
            'GET /privacy/choices' => [],
            'GET /data-deletion' => [],
            'POST /privacy/choices' => ['throttle:privacy,5,600'],
            'GET /sitemap.xml' => [],
            'GET /robots.txt' => [],
            'GET /llms.txt' => [],
            'GET /llms-full.txt' => [],
            'GET /feed.xml' => [],
            'POST /help/contact' => ['throttle:contact,5,600'],
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
            'GET /manifest.json' => [],
            'GET /app/vehicles' => ['auth', 'estate', 'permission:vehicle.view_own'],
            'POST /app/vehicles' => ['auth', 'estate', 'permission:vehicle.manage_own', 'throttle:vehicle,20,600'],
            'POST /app/vehicles/{uuid}/deactivate' => ['auth', 'estate', 'permission:vehicle.manage_own'],
            'GET /app/parking' => ['auth', 'estate', 'permission:parking.view'],
            'GET /api/v1/resident/vehicles' => ['auth', 'estate', 'permission:vehicle.view_own'],
            'POST /api/v1/resident/vehicles' => ['auth', 'estate', 'permission:vehicle.manage_own', 'throttle:vehicle,20,600'],
            'POST /api/v1/resident/vehicles/{uuid}/deactivate' => ['auth', 'estate', 'permission:vehicle.manage_own'],
            'GET /api/v1/resident/parking' => ['auth', 'estate', 'permission:parking.view'],
            'GET /api/v1/parking/availability' => ['auth', 'estate', 'permission:parking.view'],
            'POST /api/v1/gate/parking/sessions/checkin' => ['auth', 'estate', 'permission:visitor.checkin'],
            'POST /api/v1/gate/parking/sessions/checkout' => ['auth', 'estate', 'permission:visitor.checkout'],
            'GET /api/v1/gate/parking/active' => ['auth', 'estate', 'permission:visitor.checkin'],
            'GET /platform/billing/schedules' => ['auth'],
            'GET /platform/billing/schedules/new' => ['auth'],
            'GET /platform/billing/schedules/{uuid}' => ['auth'],
            'GET /admin/billing' => ['auth', 'estate'],
            'GET /admin/billing/invoices/{uuid}' => ['auth', 'estate'],
            'GET /admin/billing/reports' => ['auth', 'estate'],
            'GET /admin/billing/export.csv' => ['auth', 'estate'],
            'POST /platform/billing/schedules' => ['auth'],
            'POST /platform/billing/schedules/{uuid}/edit' => ['auth'],
            'POST /platform/billing/schedules/{uuid}/toggle' => ['auth'],
            'POST /platform/billing/schedules/{uuid}/run-now' => ['auth'],
            'GET /app/incidents/{uuid}' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /notices/{uuid}/image' => ['auth', 'estate', 'permission:notice.view'],
            'GET /app/navigation' => ['auth', 'estate'],
            'GET /platform/settings/notifications' => ['auth'],
            'POST /platform/settings/notifications' => ['auth'],
            'GET /platform/settings/payments' => ['auth'],
            'POST /platform/settings/payments' => ['auth'],
            'GET /platform/billing/automation' => ['auth'],
            'POST /platform/billing/pay-links' => ['auth'],
            'GET /pay/{token}' => [],
            'POST /webhooks/payments/mpesa' => [],
            'POST /webhooks/payments/card' => [],
            'GET /app/bills' => ['auth'],
            'GET /app/bills/{uuid}' => ['auth'],
            'POST /app/bills/{uuid}/pay-link' => ['auth'],
            'GET /admin/utility-bills' => ['auth', 'estate', 'permission:billing.subscription.view'],
            'GET /notifications' => ['auth'],
            'POST /notifications/{uuid}/read' => ['auth'],
            'GET /platform/billing' => ['auth'],
            'GET /platform/billing/invoices/new' => ['auth'],
            'GET /platform/billing/invoices/{uuid}' => ['auth'],
            'POST /platform/billing/invoices' => ['auth'],
            'POST /platform/billing/invoices/{uuid}/status' => ['auth'],
            'POST /platform/billing/invoices/{uuid}/payments' => ['auth'],
            'POST /platform/billing/payments/{uuid}/status' => ['auth'],
            'GET /platform' => ['auth'],
            'GET /platform/estates' => ['auth'],
            'GET /platform/estates/new' => ['auth'],
            'GET /platform/estates/{uuid}' => ['auth'],
            'GET /platform/plans' => ['auth'],
            'GET /platform/subscriptions' => ['auth'],
            'POST /platform/estates' => ['auth'],
            'POST /platform/estates/{uuid}/status' => ['auth'],
            'POST /platform/plans' => ['auth'],
            'POST /platform/plans/{uuid}/retire' => ['auth'],
            'POST /platform/subscriptions/{uuid}' => ['auth'],
            'GET /admin/parking' => ['auth', 'estate', 'permission:parking.view'],
            'GET /admin/parking/zones' => ['auth', 'estate', 'permission:parking.view'],
            'POST /admin/parking/zones' => ['auth', 'estate', 'permission:parking.bay.manage'],
            'POST /admin/parking/zones/{uuid}' => ['auth', 'estate', 'permission:parking.bay.manage'],
            'GET /admin/parking/bays' => ['auth', 'estate', 'permission:parking.view'],
            'POST /admin/parking/bays' => ['auth', 'estate', 'permission:parking.bay.manage'],
            'POST /admin/parking/bays/{uuid}' => ['auth', 'estate', 'permission:parking.bay.manage'],
            'POST /admin/parking/bays/{uuid}/archive' => ['auth', 'estate', 'permission:parking.bay.manage'],
            'GET /admin/parking/allocations' => ['auth', 'estate', 'permission:parking.view'],
            'POST /admin/parking/allocations' => ['auth', 'estate', 'permission:parking.allocation.manage'],
            'POST /admin/parking/allocations/{uuid}/end' => ['auth', 'estate', 'permission:parking.allocation.manage'],
            'GET /admin/parking/violations' => ['auth', 'estate', 'permission:parking.violation.manage'],
            'POST /admin/parking/violations' => ['auth', 'estate', 'permission:parking.violation.manage'],
            'POST /admin/parking/violations/{uuid}/resolve' => ['auth', 'estate', 'permission:parking.violation.manage'],
            'GET /gate/parking/violations' => ['auth', 'estate', 'permission:parking.session.checkin'],
            'POST /gate/parking/violations' => ['auth', 'estate', 'permission:parking.session.checkin'],
            'GET /app/notices' => ['auth', 'estate', 'permission:notice.view'],
            'GET /app/notices/{uuid}' => ['auth', 'estate', 'permission:notice.view'],
            'GET /gate/notices' => ['auth', 'estate', 'permission:notice.view'],
            'GET /admin/notices' => ['auth', 'estate', 'permission:notice.manage'],
            'GET /admin/notices/new' => ['auth', 'estate', 'permission:notice.manage'],
            'GET /admin/notices/{uuid}' => ['auth', 'estate', 'permission:notice.manage'],
            'POST /admin/notices' => ['auth', 'estate', 'permission:notice.manage'],
            'POST /admin/notices/{uuid}' => ['auth', 'estate', 'permission:notice.manage'],
            'POST /admin/notices/{uuid}/publish' => ['auth', 'estate', 'permission:notice.manage'],
            'POST /admin/notices/{uuid}/archive' => ['auth', 'estate', 'permission:notice.manage'],
            'GET /app/staff' => ['auth', 'estate', 'permission:household.staff.manage'],
            'POST /app/staff' => ['auth', 'estate', 'permission:household.staff.manage'],
            'POST /app/staff/{uuid}/deactivate' => ['auth', 'estate', 'permission:household.staff.manage'],
            'POST /app/staff/{uuid}/reactivate' => ['auth', 'estate', 'permission:household.staff.manage'],
            'GET /app/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /app/incidents/new' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'POST /app/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /gate/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /gate/incidents/new' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'POST /gate/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /admin/staff' => ['auth', 'estate', 'permission:household.staff.manage'],
            'GET /admin/incidents' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'GET /admin/incidents/{uuid}' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'POST /admin/incidents/{uuid}/status' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'GET /api/v1/resident/staff' => ['auth', 'estate', 'permission:household.staff.manage'],
            'POST /api/v1/resident/staff' => ['auth', 'estate', 'permission:household.staff.manage'],
            'GET /api/v1/resident/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'POST /api/v1/resident/incidents' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /api/v1/resident/incidents/{uuid}' => ['auth', 'estate', 'permission:visitor.incident.create'],
            'GET /api/v1/admin/incidents' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'GET /api/v1/admin/incidents/{uuid}' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'PATCH /api/v1/admin/incidents/{uuid}' => ['auth', 'estate', 'permission:visitor.incident.manage'],
            'GET /admin' => ['auth', 'estate', 'permission:estate.view'],
            'GET /admin/buildings' => ['auth', 'estate', 'permission:household.view'],
            'GET /admin/buildings/{uuid}' => ['auth', 'estate', 'permission:household.view'],
            'GET /admin/households' => ['auth', 'estate', 'permission:household.view'],
            'GET /admin/households/{uuid}' => ['auth', 'estate', 'permission:household.view'],
            'GET /admin/residents' => ['auth', 'estate', 'permission:estate.user.manage'],
            'GET /admin/guards' => ['auth', 'estate', 'permission:guard.manage'],
            'GET /admin/gates' => ['auth', 'estate', 'permission:gate.view'],
            'GET /admin/settings' => ['auth', 'estate', 'permission:estate.settings.manage'],
            'POST /admin/buildings' => ['auth', 'estate', 'permission:household.manage'],
            'POST /admin/buildings/{uuid}' => ['auth', 'estate', 'permission:household.manage'],
            'POST /admin/households' => ['auth', 'estate', 'permission:household.manage'],
            'POST /admin/households/{uuid}' => ['auth', 'estate', 'permission:household.manage'],
            'POST /admin/households/{uuid}/archive' => ['auth', 'estate', 'permission:household.manage'],
            'POST /admin/households/{uuid}/members' => ['auth', 'estate', 'permission:household.member.manage'],
            'POST /admin/households/{uuid}/members/{memberUuid}/remove' => ['auth', 'estate', 'permission:household.member.manage'],
            'POST /admin/residents' => ['auth', 'estate', 'permission:estate.user.manage'],
            'POST /admin/residents/{uuid}/suspend' => ['auth', 'estate', 'permission:estate.user.manage'],
            'POST /admin/residents/{uuid}/reactivate' => ['auth', 'estate', 'permission:estate.user.manage'],
            'POST /admin/guards' => ['auth', 'estate', 'permission:guard.manage'],
            'POST /admin/guards/{uuid}/gates' => ['auth', 'estate', 'permission:guard.manage'],
            'POST /admin/guards/{uuid}/archive' => ['auth', 'estate', 'permission:guard.manage'],
            'POST /admin/gates' => ['auth', 'estate', 'permission:gate.manage'],
            'POST /admin/gates/{uuid}' => ['auth', 'estate', 'permission:gate.manage'],
            'POST /admin/gates/{uuid}/archive' => ['auth', 'estate', 'permission:gate.manage'],
            'POST /admin/settings' => ['auth', 'estate', 'permission:estate.settings.manage'],
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
