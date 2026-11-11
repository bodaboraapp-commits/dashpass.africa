<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Phone;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Uuid;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Increment 6 — visitor/security incident reporting (docs/staff-incidents-contract.md
 * §0–§3, §5).
 *
 * Covers resident reporting + own-only visibility, guard reporting at the
 * session gate (409 GATE_CONTEXT_REQUIRED without one), admin queue filters and
 * detail, the open → investigating → resolved/dismissed workflow (resolution
 * notes required, terminal 409 INCIDENT_CLOSED), tenant isolation/foreign 404,
 * no hard deletes, PII-free audit actions, CSRF and XSS escaping.
 *
 * Dedicated incidents are created through the real resident/guard flows; the
 * seeded demo incidents are only ever read as a baseline, never mutated.
 */
final class IncidentModuleTest extends AdminModuleTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);

        foreach (['incident:', 'staff:', 'otp:', 'throttle:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures + helpers                                                 */
    /* ------------------------------------------------------------------ */

    private function newSummary(string $tag): string
    {
        return $tag . ' ' . bin2hex(random_bytes(3));
    }

    private function countIncidents(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM access_incidents');
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentRow(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM access_incidents WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($row === null) {
            $this->fail('Incident ' . $uuid . ' was not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentBySummary(string $summary): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM access_incidents WHERE summary = :summary ORDER BY id DESC LIMIT 1',
            ['summary' => $summary]
        );

        if ($row === null) {
            $this->fail('No incident with summary "' . $summary . '" was persisted.');
        }

        return $row;
    }

    private function userId(string $phone): int
    {
        return (int) $this->db->scalar(
            'SELECT id FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => Phone::normalize($phone)]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFrom(array $data, string $key): array
    {
        $candidate = $data[$key] ?? $data;

        if (is_array($candidate) && array_is_list($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
            $candidate = $candidate[0];
        }

        if (!is_array($candidate) || !isset($candidate['uuid'])) {
            $this->fail(
                'Expected the response to expose a ' . $key . ' object with a uuid. '
                . 'Payload: ' . (string) json_encode($data)
            );
        }

        return $candidate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsFrom(array $data, string $key): array
    {
        $candidate = $data[$key] ?? null;

        if (is_array($candidate)) {
            return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row)));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, static fn (mixed $row): bool => is_array($row)));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function incidentCreateApi(array $overrides = [], string $resident = self::JANE): Response
    {
        $this->ensureLoggedInAs($resident);

        return $this->post('/api/v1/resident/incidents', array_merge([
            'incident_type' => 'suspicious_activity',
            'severity' => 'medium',
            'summary' => $this->newSummary('QA incident'),
            'description' => 'QA incident description fixture for integration tests.',
            'occurred_at' => Clock::toDb(Clock::now()->modify('-15 minutes')),
        ], $overrides), true);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createIncident(array $overrides = [], string $resident = self::JANE): array
    {
        $payload = $this->assertSuccess(
            $this->incidentCreateApi($overrides, $resident),
            'Incident creation failed for ' . $resident . '.'
        );

        return $this->itemFrom($payload['data'], 'incident');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listIncidents(string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        return $this->itemsFrom($this->assertSuccess($this->get('/api/v1/resident/incidents'))['data'], 'incidents');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function guardCreateIncident(array $overrides = []): Response
    {
        $this->loginGuard();

        return $this->post('/gate/incidents', array_merge([
            'incident_type' => 'tailgating',
            'severity' => 'medium',
            'summary' => $this->newSummary('QA guard incident'),
            'description' => 'QA guard incident description at the session gate.',
            'occurred_at' => Clock::toDb(Clock::now()->modify('-5 minutes')),
        ], $overrides), true);
    }

    private function adminStatusWeb(string $uuid, string $status, ?string $notes = null): Response
    {
        $this->ensureLoggedInAs(self::GRACE);

        $body = ['status' => $status];

        if ($notes !== null) {
            $body['resolution_notes'] = $notes;
        }

        return $this->post('/admin/incidents/' . $uuid . '/status', $body, true);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function adminStatusApi(string $uuid, array $body, string $phone = self::GRACE): Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->request('PATCH', '/api/v1/admin/incidents/' . $uuid, $body, [], true);
    }

    private function adminIncidentsApi(string $query = '', string $phone = self::GRACE): Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->get('/api/v1/admin/incidents' . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiFailure(int $expectedStatus, Response $response, string $message = ''): array
    {
        $this->assertStatus($expectedStatus, $response, $message !== '' ? $message : 'Expected HTTP ' . $expectedStatus . '.');
        $payload = $this->json($response);

        $this->assertSame(false, $payload['success'] ?? null, 'An error response must carry success=false.');
        $this->assertTrue(
            isset($payload['error']['code']) && is_string($payload['error']['code']),
            'An error response must carry a string error.code.'
        );

        return $payload;
    }

    /**
     * Admin incident mutations are usually PRG, but a validation/state failure
     * may be an inline 422 or a 409 page. Accept all three and require a marker.
     *
     * @param array<int, string> $markers
     */
    private function assertStatusRejected(Response $response, string $fallbackPath, array $markers): void
    {
        if (in_array($response->status(), [422, 409], true)) {
            foreach ($markers as $marker) {
                if (str_contains($response->body(), $marker)) {
                    return;
                }
            }

            $this->fail(
                'The ' . $response->status() . ' response must contain one of: '
                . implode(', ', $markers) . '.'
            );
        }

        if (in_array($response->status(), [302, 303], true)) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);

            foreach ($markers as $marker) {
                if (str_contains($follow->body(), $marker)) {
                    return;
                }
            }

            $this->fail(
                'The redirected page must render one of: ' . implode(', ', $markers)
                . ' (got ' . $location . ').'
            );
        }

        $this->fail('Expected an incident rejection (422/409/PRG 302), got HTTP ' . $response->status() . '.');
    }

    /* ------------------------------------------------------------------ */
    /* Resident reporting + own-only visibility                          */
    /* ------------------------------------------------------------------ */

    public function testResidentCreatesIncidentAndSeesOnlyOwnReports(): void
    {
        $janeSummary = $this->newSummary('QA Jane report');
        $jane = $this->createIncident(['summary' => $janeSummary, 'severity' => 'high']);

        $this->assertSame('open', (string) ($jane['status'] ?? ''), 'A newly reported incident must start open.');

        $brianSummary = $this->newSummary('QA Brian report');
        $brian = $this->createIncident(
            ['summary' => $brianSummary, 'household_uuid' => $this->householdUuid('A-002')],
            self::BRIAN
        );

        $guardSummary = $this->newSummary('QA guard report');
        $this->assertStatus(302, $this->guardCreateIncident(['summary' => $guardSummary]));
        $guardRow = $this->incidentBySummary($guardSummary);

        $items = $this->listIncidents(self::JANE);
        $uuids = array_map(static fn (array $item): string => (string) ($item['uuid'] ?? ''), $items);

        $this->assertContains((string) $jane['uuid'], $uuids, 'Jane must see the incident she reported.');
        $this->assertNotContains((string) $brian['uuid'], $uuids, 'Residents must only see incidents they reported.');
        $this->assertNotContains((string) $guardRow['uuid'], $uuids, 'A guard report must not appear in a resident list.');

        $page = $this->get('/app/incidents');
        $this->assertStatus(200, $page);
        $this->assertTextContains($janeSummary, $page);
        $this->assertBodyNotContains($brianSummary, $page, 'Residents must never see another reporter\'s incident.');
        $this->assertBodyNotContains($guardSummary, $page);
        $this->assertCleanMarkup($page, 'Resident incidents list');
    }

    public function testIncidentCreateValidationRules(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $valid = [
            'incident_type' => 'suspicious_activity',
            'severity' => 'medium',
            'summary' => 'QA valid incident summary',
            'description' => 'QA valid incident description text.',
        ];

        $cases = [
            [['incident_type' => 'not_a_type'], 'incident_type'],
            [['severity' => 'extreme'], 'severity'],
            [['summary' => 'ab'], 'summary'],
            [['summary' => str_repeat('s', 181)], 'summary'],
            [['description' => 'short'], 'description'],
            [['description' => str_repeat('d', 2001)], 'description'],
            [['occurred_at' => Clock::toDb(Clock::now()->modify('+2 hours'))], 'occurred_at'],
        ];

        $before = $this->countIncidents();

        foreach ($cases as [$overrides, $field]) {
            $payload = $this->assertErrorCode(
                'VALIDATION_FAILED',
                $this->post('/api/v1/resident/incidents', array_merge($valid, $overrides), true),
                422
            );
            $this->assertArrayHasKey(
                $field,
                (array) ($payload['error']['errors'] ?? []),
                'The validation error must identify the ' . $field . ' field.'
            );
        }

        $this->assertSame($before, $this->countIncidents(), 'Rejected incident reports must never persist a row.');

        // A past occurred_at is accepted verbatim.
        $past = $this->createIncident(['occurred_at' => Clock::toDb(Clock::now()->modify('-3 hours'))]);
        $pastRow = $this->incidentRow((string) $past['uuid']);
        $this->assertNotNull($pastRow['occurred_at']);
        $pastMoment = Clock::fromDb((string) $pastRow['occurred_at']);
        $this->assertNotNull($pastMoment);
        $this->assertTrue($pastMoment->getTimestamp() < Clock::now()->getTimestamp(), 'A past occurred_at must be persisted.');

        // Omitting occurred_at defaults to (approximately) now.
        $default = $this->itemFrom($this->assertSuccess($this->post('/api/v1/resident/incidents', [
            'incident_type' => 'other',
            'severity' => 'low',
            'summary' => $this->newSummary('QA default time'),
            'description' => 'QA default occurred_at description.',
        ], true))['data'], 'incident');

        $defaultRow = $this->incidentRow((string) $default['uuid']);
        $this->assertNotNull($defaultRow['occurred_at'], 'occurred_at must default to the report time.');
        $defaultMoment = Clock::fromDb((string) $defaultRow['occurred_at']);
        $this->assertNotNull($defaultMoment);
        $this->assertTrue(
            abs($defaultMoment->getTimestamp() - Clock::now()->getTimestamp()) <= 120,
            'occurred_at must default to approximately now.'
        );
    }

    public function testOptionalEstateReferencesAreValidatedAndScoped(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $gate = $this->gate('Main Gate');
        $householdUuid = $this->householdUuid('A-001');

        $withReferences = $this->createIncident([
            'gate_uuid' => (string) $gate['uuid'],
            'household_uuid' => $householdUuid,
        ]);
        $row = $this->incidentRow((string) $withReferences['uuid']);
        $this->assertSame((int) $gate['id'], (int) $row['gate_id'], 'An active estate gate must be accepted.');
        $this->assertSame(
            (int) $this->db->scalar('SELECT id FROM households WHERE uuid = :uuid', ['uuid' => $householdUuid]),
            (int) $row['household_id'],
            'The caller\'s own household must be accepted.'
        );

        $invitation = $this->seededInvitation(DemoVisitorSeeder::INVITATION_UPCOMING_UUID);
        $withInvitation = $this->createIncident(['invitation_uuid' => (string) $invitation['uuid']]);
        $this->assertSame(
            (int) $invitation['id'],
            (int) $this->incidentRow((string) $withInvitation['uuid'])['invitation_id'],
            'The caller\'s own invitation must be accepted.'
        );

        $before = $this->countIncidents();

        $riverside = $this->seededEstate('riverside-court');
        $riversideGate = (string) $this->db->scalar(
            'SELECT uuid FROM gates WHERE estate_id = :id ORDER BY id ASC LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertErrorCode(
            'GATE_NOT_FOUND',
            $this->incidentCreateApi(['gate_uuid' => $riversideGate]),
            404
        );
        $this->assertErrorCode(
            'GATE_NOT_FOUND',
            $this->incidentCreateApi(['gate_uuid' => Uuid::v4()]),
            404
        );

        $brianInvitation = $this->createInvitation(
            ['household_uuid' => $this->householdUuid('A-002')],
            self::BRIAN
        );
        $this->assertErrorCode(
            'INVITATION_NOT_FOUND',
            $this->incidentCreateApi(['invitation_uuid' => (string) $brianInvitation['invitation_uuid']]),
            404
        );

        $this->apiFailure(404, $this->incidentCreateApi(['household_uuid' => $this->householdUuid('A-002')]));

        $this->assertSame($before, $this->countIncidents(), 'Rejected estates references must not persist an incident.');
    }

    public function testResidentCannotReadAnotherReportersIncident(): void
    {
        $brian = $this->createIncident(
            ['summary' => $this->newSummary('QA Brian detail')],
            self::BRIAN
        );

        $this->ensureLoggedInAs(self::JANE);

        $this->assertErrorCode(
            'INCIDENT_NOT_FOUND',
            $this->get('/api/v1/resident/incidents/' . (string) $brian['uuid']),
            404
        );
        $this->assertErrorCode(
            'INCIDENT_NOT_FOUND',
            $this->get('/api/v1/resident/incidents/' . Uuid::v4()),
            404
        );

        $jane = $this->createIncident(['summary' => $this->newSummary('QA Jane detail')]);
        $detail = $this->itemFrom(
            $this->assertSuccess($this->get('/api/v1/resident/incidents/' . (string) $jane['uuid']))['data'],
            'incident'
        );
        $this->assertSame((string) $jane['uuid'], (string) $detail['uuid'], 'A reporter must read their own incident detail.');
    }

    /* ------------------------------------------------------------------ */
    /* Guard reporting                                                    */
    /* ------------------------------------------------------------------ */

    public function testGuardReportsAtSessionGateAndRequiresGateContext(): void
    {
        $janeSummary = $this->newSummary('QA resident report not visible to guard');
        $this->createIncident(['summary' => $janeSummary]);

        $this->loginGuard();

        $summary = $this->newSummary('QA guard gate report');
        $response = $this->post('/gate/incidents', [
            'incident_type' => 'tailgating',
            'severity' => 'high',
            'summary' => $summary,
            'description' => 'QA guard incident report at Main Gate.',
            'occurred_at' => Clock::toDb(Clock::now()->modify('-2 minutes')),
        ], true);

        $this->assertStatus(302, $response, 'A successful guard incident report must redirect (PRG).');

        $row = $this->incidentBySummary($summary);
        $gate = $this->gate('Main Gate');

        $this->assertSame((int) $gate['id'], (int) $row['gate_id'], 'A guard report must default to the session gate.');
        $this->assertSame($this->userId(self::GUARD_MAIN), (int) $row['reported_by_user_id']);
        $this->assertSame('high', (string) $row['severity']);
        $this->assertSame('open', (string) $row['status']);

        $page = $this->get('/gate/incidents');
        $this->assertStatus(200, $page);
        $this->assertTextContains($summary, $page);
        $this->assertBodyNotContains($janeSummary, $page, 'Guards must only see incidents they reported.');
        $this->assertCleanMarkup($page, 'Guard incidents list');

        // A guard session without a selected gate must be rejected before any row is written.
        // reloginAs() clears the per-phone OTP cooldown (a second loginAs() would 429).
        $this->reloginAs(self::GUARD_MAIN);
        $before = $this->countIncidents();
        $noGateSummary = $this->newSummary('QA gate-less report');

        $noGate = $this->post('/gate/incidents', [
            'incident_type' => 'other',
            'severity' => 'low',
            'summary' => $noGateSummary,
            'description' => 'QA report without a gate context.',
        ], true);

        $this->assertTrue(
            in_array($noGate->status(), [409, 302], true),
            'Reporting without a gate context must be rejected, got HTTP ' . $noGate->status() . '.'
        );

        if ($noGate->status() === 409) {
            $this->assertBodyContains('GATE_CONTEXT_REQUIRED', $noGate);
        }

        $this->assertSame($before, $this->countIncidents(), 'A gate-less report must not persist an incident.');
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM access_incidents WHERE summary = :summary', ['summary' => $noGateSummary])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Resident web form + CSRF                                           */
    /* ------------------------------------------------------------------ */

    public function testResidentWebFormCreatesIncidentAndRequiresCsrf(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $formPage = $this->get('/app/incidents/new');
        $this->assertStatus(200, $formPage);

        $forms = $this->xpath('//form[@action="/app/incidents"]', $this->dom($formPage));
        $this->assertTrue($forms->length >= 1, 'The report screen must post to /app/incidents.');

        $form = $forms->item(0);
        $this->assertNotNull($form);

        foreach (['incident_type', 'severity', 'summary', 'description'] as $field) {
            $this->assertCount(
                1,
                $this->xpath('.//*[@name="' . $field . '"]', $form),
                'The incident report form must expose a ' . $field . ' field.'
            );
        }

        $this->assertCleanMarkup($formPage, 'Incident report form');

        $summary = $this->newSummary('QA web report');
        $before = $this->countIncidents();

        $response = $this->post('/app/incidents', [
            'incident_type' => 'property_damage',
            'severity' => 'low',
            'summary' => $summary,
            'description' => 'QA resident web incident description.',
        ], true);

        $this->assertRedirect($response, '/app/incidents');

        $row = $this->incidentBySummary($summary);
        $this->assertSame($this->userId(self::JANE), (int) $row['reported_by_user_id']);
        $this->assertSame($before + 1, $this->countIncidents());
        $this->assertTextContains($summary, $this->get('/app/incidents'));

        // Every web POST is CSRF protected.
        $csrfCount = $this->countIncidents();
        $blocked = $this->post('/app/incidents', [
            'incident_type' => 'other',
            'severity' => 'low',
            'summary' => $this->newSummary('QA csrf report'),
            'description' => 'QA CSRF blocked incident description.',
        ], false);

        $this->assertStatus(403, $blocked);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $blocked);
        $this->assertSame($csrfCount, $this->countIncidents(), 'A CSRF-rejected report must not persist a row.');
    }

    /* ------------------------------------------------------------------ */
    /* Admin queue, detail and workflow                                   */
    /* ------------------------------------------------------------------ */

    public function testAdminQueueFiltersByStatusAndSeverity(): void
    {
        $openSummary = $this->newSummary('QAOPEN');
        $highSummary = $this->newSummary('QAHIGHINV');
        $resolvedSummary = $this->newSummary('QARESOLVED');

        $this->createIncident(['summary' => $openSummary, 'severity' => 'medium']);
        $high = $this->createIncident(['summary' => $highSummary, 'severity' => 'high']);
        $resolved = $this->createIncident(['summary' => $resolvedSummary, 'severity' => 'low']);

        $page = $this->adminGet('/admin/incidents');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'Admin incidents queue');

        foreach ([$openSummary, $highSummary, $resolvedSummary] as $summary) {
            $this->assertTextContains($summary, $page, 'The unfiltered admin queue must list every estate incident.');
        }

        $this->assertStatus(302, $this->adminStatusWeb((string) $high['uuid'], 'investigating'));
        $this->assertStatus(302, $this->adminStatusWeb((string) $resolved['uuid'], 'resolved', 'Resolved during the QA filter test.'));

        $this->assertSame('investigating', (string) $this->incidentRow((string) $high['uuid'])['status']);
        $this->assertSame('resolved', (string) $this->incidentRow((string) $resolved['uuid'])['status']);

        $cases = [
            ['status=open', [$openSummary], [$highSummary, $resolvedSummary]],
            ['status=investigating', [$highSummary], [$openSummary, $resolvedSummary]],
            ['status=resolved', [$resolvedSummary], [$openSummary, $highSummary]],
            ['severity=high', [$highSummary], [$openSummary, $resolvedSummary]],
            ['severity=low', [$resolvedSummary], [$openSummary, $highSummary]],
            ['severity=medium', [$openSummary], [$highSummary, $resolvedSummary]],
            ['status=investigating&severity=high', [$highSummary], [$openSummary, $resolvedSummary]],
            ['status=not-a-status&severity=not-a-severity', [$openSummary, $highSummary, $resolvedSummary], []],
        ];

        foreach ($cases as [$query, $expected, $unexpected]) {
            $filtered = $this->adminGet('/admin/incidents?' . $query);
            $this->assertStatus(200, $filtered, 'Filter ' . $query . ' must render.');

            foreach ($expected as $summary) {
                $this->assertTextContains($summary, $filtered, 'Filter ' . $query . ' must include "' . $summary . '".');
            }

            foreach ($unexpected as $summary) {
                $this->assertTextNotContains($summary, $filtered, 'Filter ' . $query . ' must exclude "' . $summary . '".');
            }
        }

        $detail = $this->adminGet('/admin/incidents/' . (string) $high['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains($highSummary, $detail);
        $this->assertTextContains('QA incident description fixture for integration tests.', $detail, 'The admin detail must render the description.');
        $this->assertCleanMarkup($detail, 'Admin incident detail');
    }

    public function testAdminWebStatusTransitionsRequireResolutionNotesAndAudit(): void
    {
        $summary = $this->newSummary('QATRANS');
        $incident = $this->createIncident(['summary' => $summary, 'severity' => 'medium']);
        $uuid = (string) $incident['uuid'];

        $this->assertStatus(302, $this->adminStatusWeb($uuid, 'investigating'));
        $this->assertSame('investigating', (string) $this->incidentRow($uuid)['status']);

        $this->assertStatus(302, $this->adminStatusWeb($uuid, 'resolved', 'QA resolution notes for the transition test.'));
        $row = $this->incidentRow($uuid);

        $this->assertSame('resolved', (string) $row['status']);
        $this->assertNotNull($row['resolved_at'], 'Resolving an incident must record resolved_at.');
        $this->assertSame($this->userId(self::GRACE), (int) $row['resolved_by_user_id']);
        $this->assertSame('QA resolution notes for the transition test.', (string) $row['resolution_notes']);

        $statusAudits = $this->db->select(
            "SELECT metadata FROM audit_logs WHERE action = 'incident.status_changed' ORDER BY id ASC"
        );
        $this->assertTrue(count($statusAudits) >= 2, 'Every status transition must be audited as incident.status_changed.');

        $metadata = implode(' ', array_map(static fn (array $audit): string => (string) $audit['metadata'], $statusAudits));
        $this->assertStringContainsString('investigating', $metadata, 'The status audit must record the new status.');
        $this->assertStringContainsString('resolved', $metadata, 'The status audit must record the terminal status.');
        $this->assertStringNotContainsString($summary, $metadata, 'Status audit metadata must not contain the incident summary.');
        $this->assertStringNotContainsString(
            'QA resolution notes for the transition test.',
            $metadata,
            'Status audit metadata must not contain the resolution notes.'
        );
        $this->assertStringNotContainsString(
            'QA incident description fixture for integration tests.',
            (string) json_encode($statusAudits),
            'Status audit metadata must not contain the incident description.'
        );

        // Resolving requires resolution notes.
        $noNotes = $this->createIncident(['summary' => $this->newSummary('QA no notes'), 'severity' => 'low']);
        $rejected = $this->adminStatusWeb((string) $noNotes['uuid'], 'resolved');
        $this->assertStatusRejected($rejected, '/admin/incidents/' . (string) $noNotes['uuid'], [
            'resolution_notes',
            'esolution',
            'notes',
            'required',
            'VALIDATION_FAILED',
            'invalid',
        ]);
        $this->assertSame('open', (string) $this->incidentRow((string) $noNotes['uuid'])['status']);

        // Backwards transitions are rejected.
        $backwards = $this->createIncident(['summary' => $this->newSummary('QA backwards'), 'severity' => 'medium']);
        $this->assertStatus(302, $this->adminStatusWeb((string) $backwards['uuid'], 'investigating'));
        $backwardsRejected = $this->adminStatusWeb((string) $backwards['uuid'], 'open');
        $this->assertStatusRejected($backwardsRejected, '/admin/incidents/' . (string) $backwards['uuid'], [
            'INVALID_STATUS_TRANSITION',
            'not allowed',
            'invalid',
            'cannot',
            'reopen',
        ]);
        $this->assertSame('investigating', (string) $this->incidentRow((string) $backwards['uuid'])['status']);

        // Terminal incidents can never be changed again (409 INCIDENT_CLOSED).
        $terminal = $this->adminStatusWeb($uuid, 'investigating');
        $this->assertStatusRejected($terminal, '/admin/incidents/' . $uuid, ['INCIDENT_CLOSED', 'closed', 'terminal']);
        $this->assertSame('resolved', (string) $this->incidentRow($uuid)['status']);
    }

    public function testAdminApiStatusWorkflowAndTerminalState(): void
    {
        $incident = $this->createIncident(['summary' => $this->newSummary('QA API workflow'), 'severity' => 'high']);
        $uuid = (string) $incident['uuid'];

        $moved = $this->itemFrom($this->assertSuccess($this->adminStatusApi($uuid, ['status' => 'investigating']))['data'], 'incident');
        $this->assertSame('investigating', (string) ($moved['status'] ?? ''));

        $missingNotes = $this->assertErrorCode(
            'VALIDATION_FAILED',
            $this->adminStatusApi($uuid, ['status' => 'resolved']),
            422
        );
        $this->assertArrayHasKey(
            'resolution_notes',
            (array) ($missingNotes['error']['errors'] ?? []),
            'Resolving via the API must require resolution_notes.'
        );

        $resolved = $this->itemFrom(
            $this->assertSuccess($this->adminStatusApi($uuid, [
                'status' => 'resolved',
                'resolution_notes' => 'QA API resolution notes.',
            ]))['data'],
            'incident'
        );
        $this->assertSame('resolved', (string) ($resolved['status'] ?? ''));

        $this->assertErrorCode('INCIDENT_CLOSED', $this->adminStatusApi($uuid, ['status' => 'investigating']), 409);
        $this->assertErrorCode('INCIDENT_CLOSED', $this->adminStatusApi($uuid, ['status' => 'dismissed']), 409);
        $this->assertSame('resolved', (string) $this->incidentRow($uuid)['status'], 'A terminal incident must stay resolved.');

        // An unknown status value is a validation error.
        $invalidTarget = $this->createIncident(['summary' => $this->newSummary('QA API invalid target')]);
        $invalidPayload = $this->assertErrorCode(
            'VALIDATION_FAILED',
            $this->adminStatusApi((string) $invalidTarget['uuid'], ['status' => 'closed']),
            422
        );
        $this->assertArrayHasKey('status', (array) ($invalidPayload['error']['errors'] ?? []));

        // Unknown/foreign incidents are 404.
        $this->assertErrorCode(
            'INCIDENT_NOT_FOUND',
            $this->adminStatusApi(Uuid::v4(), ['status' => 'investigating']),
            404
        );

        // Admin API list filters + detail.
        $list = $this->itemsFrom(
            $this->assertSuccess($this->adminIncidentsApi('status=resolved'))['data'],
            'incidents'
        );
        $listUuids = array_map(static fn (array $item): string => (string) ($item['uuid'] ?? ''), $list);
        $this->assertContains($uuid, $listUuids, 'The admin API list must support the status filter.');

        $this->ensureLoggedInAs(self::GRACE);
        $detail = $this->itemFrom(
            $this->assertSuccess($this->get('/api/v1/admin/incidents/' . $uuid))['data'],
            'incident'
        );
        $this->assertSame($uuid, (string) $detail['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Tenant isolation + no hard deletes                                 */
    /* ------------------------------------------------------------------ */

    public function testRiversideAdminCannotSeeOrTriageGreenviewIncidents(): void
    {
        $summary = $this->newSummary('QATENANT');
        $incident = $this->createIncident(['summary' => $summary]);
        $uuid = (string) $incident['uuid'];

        $page = $this->adminGet('/admin/incidents', self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $page);
        $this->assertBodyNotContains($summary, $page, 'A Riverside admin must never see Greenview incidents.');
        $this->assertBodyNotContains($uuid, $page);

        $list = $this->itemsFrom(
            $this->assertSuccess($this->adminIncidentsApi('', self::DAVID_RIVERSIDE))['data'],
            'incidents'
        );
        $this->assertNotContains(
            $uuid,
            array_map(static fn (array $item): string => (string) ($item['uuid'] ?? ''), $list),
            'The Riverside admin API list must be estate scoped.'
        );

        $foreignDetail = $this->adminGet('/admin/incidents/' . $uuid, self::DAVID_RIVERSIDE);
        $this->assertStatus(404, $foreignDetail, 'A foreign-estate incident detail must be 404.');
        $this->assertBodyContains('INCIDENT_NOT_FOUND', $foreignDetail);

        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $this->assertErrorCode('INCIDENT_NOT_FOUND', $this->get('/api/v1/admin/incidents/' . $uuid), 404);
        $this->assertErrorCode(
            'INCIDENT_NOT_FOUND',
            $this->adminStatusApi($uuid, ['status' => 'investigating'], self::DAVID_RIVERSIDE),
            404
        );
        $this->assertSame('open', (string) $this->incidentRow($uuid)['status'], 'A cross-tenant triage attempt must not mutate the incident.');
    }

    public function testStatusChangesNeverHardDeleteIncidents(): void
    {
        $this->assertSame(
            3,
            $this->countIncidents(),
            'The Increment-6 demo seed must install exactly three demo incidents.'
        );

        $baseline = $this->countIncidents();
        $incident = $this->createIncident(['summary' => $this->newSummary('QA no delete')]);
        $uuid = (string) $incident['uuid'];

        $this->assertSame($baseline + 1, $this->countIncidents());

        $this->assertStatus(302, $this->adminStatusWeb($uuid, 'investigating'));
        $this->assertStatus(302, $this->adminStatusWeb($uuid, 'dismissed'));

        $this->assertSame(
            $baseline + 1,
            $this->countIncidents(),
            'Status transitions must never delete incident rows.'
        );
        $this->assertSame('dismissed', (string) $this->incidentRow($uuid)['status'], 'Dismissing is a soft status change.');
        $this->assertSame(
            3,
            (int) $this->db->scalar("SELECT COUNT(*) FROM access_incidents WHERE status IN ('open', 'investigating', 'resolved')"),
            'The three seeded demo incidents must remain in place (no hard deletes).'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Audit + XSS + permissions                                          */
    /* ------------------------------------------------------------------ */

    public function testIncidentAuditMetadataIsPiiFree(): void
    {
        $summary = $this->newSummary('QAAUDIT');
        $description = 'QA audit description with a private note.';

        $incident = $this->createIncident([
            'summary' => $summary,
            'description' => $description,
            'severity' => 'critical',
        ]);
        $uuid = (string) $incident['uuid'];
        $row = $this->incidentRow($uuid);

        $audit = $this->db->selectOne(
            "SELECT entity_type, entity_id, metadata FROM audit_logs WHERE action = 'incident.reported' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Incident creation must be audited as incident.reported.');

        $metadata = (string) $audit['metadata'];
        $this->assertTrue(
            str_contains($metadata, $uuid) || (int) $audit['entity_id'] === (int) $row['id'],
            'The incident audit row must reference the created incident.'
        );
        $this->assertStringContainsString('critical', $metadata, 'Incident audit metadata may carry the severity key.');
        $this->assertStringNotContainsString($summary, $metadata, 'Audit metadata must never contain the free-text summary.');
        $this->assertStringNotContainsString($description, $metadata, 'Audit metadata must never contain the free-text description.');
        $this->assertFalse(
            (bool) preg_match('/\+2547\d{6,9}/', $metadata),
            'Incident audit metadata must never contain a phone number.'
        );

        $this->assertStatus(302, $this->adminStatusWeb($uuid, 'investigating'));

        $statusAudit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'incident.status_changed' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($statusAudit, 'Status changes must be audited as incident.status_changed.');

        $statusMetadata = (string) $statusAudit['metadata'];
        $this->assertStringContainsString('open', $statusMetadata);
        $this->assertStringContainsString('investigating', $statusMetadata);
        $this->assertStringNotContainsString($summary, $statusMetadata);
        $this->assertStringNotContainsString($description, $statusMetadata);
    }

    public function testIncidentXssEscapingAcrossResidentGuardAndAdminScreens(): void
    {
        $summaryPayload = 'QA <script>alert("incident")</script>';
        $descriptionPayload = 'QA description <img src=x onerror="alert(1)"> tail';

        $incident = $this->createIncident([
            'summary' => $summaryPayload,
            'description' => $descriptionPayload,
        ]);
        $uuid = (string) $incident['uuid'];

        $resident = $this->get('/app/incidents');
        $this->assertStatus(200, $resident);
        $this->assertBodyNotContains($summaryPayload, $resident, 'Incident summaries must be escaped on the resident list.');
        $this->assertBodyContains('&lt;script&gt;', $resident, 'The escaped summary must still be visible as text.');
        $this->assertCleanMarkup($resident, 'Resident incidents list (XSS)');

        $adminList = $this->adminGet('/admin/incidents');
        $this->assertStatus(200, $adminList);
        $this->assertBodyNotContains($summaryPayload, $adminList, 'Incident summaries must be escaped on the admin queue.');
        $this->assertBodyContains('&lt;script&gt;', $adminList);

        $adminDetail = $this->adminGet('/admin/incidents/' . $uuid);
        $this->assertStatus(200, $adminDetail);
        $this->assertBodyNotContains($descriptionPayload, $adminDetail, 'Incident descriptions must be escaped on the admin detail.');
        $this->assertBodyContains('&lt;img', $adminDetail, 'The escaped description must still be visible as text.');
        $this->assertCleanMarkup($adminDetail, 'Admin incident detail (XSS)');

        $guardSummary = 'QA <script>alert("guard")</script>';
        $this->assertStatus(302, $this->guardCreateIncident([
            'summary' => $guardSummary,
            'description' => 'QA guard XSS description payload.',
        ]));

        $guardPage = $this->get('/gate/incidents');
        $this->assertStatus(200, $guardPage);
        $this->assertBodyNotContains($guardSummary, $guardPage, 'Incident summaries must be escaped on the guard list.');
        $this->assertBodyContains('&lt;script&gt;', $guardPage);
        $this->assertCleanMarkup($guardPage, 'Guard incidents list (XSS)');
    }

    public function testIncidentPermissionBoundaries(): void
    {
        $incident = $this->createIncident(['summary' => $this->newSummary('QA permission boundary')]);
        $uuid = (string) $incident['uuid'];

        $this->loginGuard();
        $this->assertStatus(403, $this->get('/admin/incidents'), 'A guard must not reach the admin incident queue.');
        $this->assertStatus(
            403,
            $this->post('/admin/incidents/' . $uuid . '/status', ['status' => 'investigating'], true),
            'A guard must not triage incidents.'
        );

        $this->reloginAs(self::JANE);
        $this->assertStatus(403, $this->get('/admin/incidents'), 'A resident must not reach the admin queue.');
        $this->assertStatus(
            403,
            $this->post('/admin/incidents/' . $uuid . '/status', ['status' => 'investigating'], true)
        );

        $this->reloginAs(self::PARKING_MANAGER);
        $this->assertStatus(403, $this->get('/app/incidents'), 'A parking manager holds no incident permission.');
        $this->assertStatus(403, $this->get('/api/v1/resident/incidents'));
        $this->assertStatus(
            403,
            $this->post('/api/v1/resident/incidents', [
                'incident_type' => 'other',
                'severity' => 'low',
                'summary' => 'QA blocked report',
                'description' => 'QA blocked report description.',
            ], true)
        );

        $this->reloginAs(self::AUDITOR);
        $this->assertStatus(403, $this->get('/admin/incidents'), 'Auditors are read-only and must not triage incidents.');

        $this->assertSame('open', (string) $this->incidentRow($uuid)['status'], 'Unauthorised triage attempts must not mutate the incident.');
    }
}
