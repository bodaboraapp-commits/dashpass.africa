<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;

/**
 * New Inc-6 resident incident-detail surfaces (landed 2026-11-12):
 *  - web `GET /app/incidents/{uuid}` (auth, estate, visitor.incident.create);
 *  - API `GET /api/v1/resident/incidents/{uuid}`;
 *  - own-report ownership: a different resident/tenant gets 404, guards are
 *    rejected by the resident-role gate, unauthenticated users are redirected.
 */
final class ResidentIncidentDetailTest extends VisitorModuleTestCase
{
    /** @return array<string, mixed> */
    private function reportIncident(): array
    {
        $response = $this->post('/api/v1/resident/incidents', [
            'incident_type' => 'suspicious_activity',
            'severity' => 'medium',
            'summary' => 'QA detail incident ' . bin2hex(random_bytes(3)),
            'description' => 'QA incident detail description with enough characters.',
        ], true);

        $this->assertStatus(201, $response);
        $payload = $this->json($response);
        $this->assertNotEmpty($payload['data']['uuid'] ?? null, 'The created incident must expose its uuid.');

        return $payload['data'];
    }

    public function testResidentCanOpenTheirOwnIncidentDetail(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $incident = $this->reportIncident();

        $detail = $this->get('/app/incidents/' . $incident['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertCleanMarkup($detail, 'resident incident detail');
        $this->assertCount(1, $this->xpath('//h1', $this->dom($detail)));
        $this->assertBodyContains('Back to incidents', $detail);
    }

    public function testApiIncidentDetailIsOwnReportScoped(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $incident = $this->reportIncident();

        $own = $this->get('/api/v1/resident/incidents/' . $incident['uuid']);
        $this->assertStatus(200, $own);
        $this->assertSame((string) $incident['uuid'], (string) ($this->json($own)['data']['uuid'] ?? ''));

        // A different resident/tenant cannot read it, and unknown ids are generic 404.
        $this->ensureLoggedInAs(self::BRIAN);
        $this->assertStatus(404, $this->get('/api/v1/resident/incidents/' . $incident['uuid']));
        $this->assertStatus(404, $this->get('/api/v1/resident/incidents/' . Uuid::v4()));
    }

    public function testWebDetailIsOwnReportScoped(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $incident = $this->reportIncident();

        $this->ensureLoggedInAs(self::BRIAN);
        $this->assertStatus(404, $this->get('/app/incidents/' . $incident['uuid']), 'Another reporter must not read the incident.');

        $this->assertStatus(404, $this->get('/app/incidents/' . Uuid::v4()));
    }

    public function testGuardsAndAdminsUseTheirOwnSurfacesNotResidentDetail(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $incident = $this->reportIncident();

        $this->loginGuard();
        $guard = $this->get('/app/incidents/' . $incident['uuid']);
        $this->assertTrue(
            in_array($guard->status(), [403, 404], true),
            'A guard must not reach the resident incident detail, got ' . $guard->status() . '.'
        );
    }

    public function testUnauthenticatedDetailRedirectsToLogin(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $incident = $this->reportIncident();

        $this->resetSession();
        $response = $this->get('/app/incidents/' . $incident['uuid']);
        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/login', (string) $response->header('Location'));
    }
}
