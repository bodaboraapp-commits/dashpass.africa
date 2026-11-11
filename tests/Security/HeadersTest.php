<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Uuid;

/**
 * Baseline browser hardening headers (Blueprint §60, contract §2):
 * CSP without inline script/style, nosniff, frame denial and no-store caching.
 */
final class HeadersTest extends SecurityTestCase
{
    private function assertSecurityHeaders(\App\Core\Response $response, string $context): void
    {
        $this->assertHeader('X-Content-Type-Options', 'nosniff', $response);
        $this->assertHeader('X-Frame-Options', 'DENY', $response);
        $this->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin', $response);

        $permissions = (string) $response->header('Permissions-Policy');
        $this->assertContains('camera=()', $permissions);
        $this->assertContains('microphone=()', $permissions);

        $cacheControl = (string) $response->header('Cache-Control');
        $this->assertContains('no-store', $cacheControl);

        $csp = (string) $response->header('Content-Security-Policy');
        $this->assertContains("default-src 'self'", $csp);
        $this->assertContains("script-src 'self'", $csp);
        $this->assertContains("style-src 'self'", $csp);
        $this->assertContains("frame-ancestors 'none'", $csp);
        $this->assertContains("object-src 'none'", $csp);
        $this->assertFalse(str_contains($csp, 'unsafe-inline'), 'CSP must not allow inline script/style (' . $context . ').');
        $this->assertFalse(str_contains($csp, 'unsafe-eval'));

        $correlationId = $response->header('X-Correlation-ID');
        $this->assertNotNull($correlationId, 'Every response carries an X-Correlation-ID.');
        $this->assertTrue(Uuid::isValid((string) $correlationId));
    }

    public function testJsonResponseHeaders(): void
    {
        $this->assertSecurityHeaders($this->get('/api/v1/health'), 'json');
    }

    public function testHtmlResponseHeaders(): void
    {
        $this->assertSecurityHeaders($this->get('/login'), 'html');
    }

    public function testErrorResponseHeaders(): void
    {
        $this->assertSecurityHeaders($this->get('/api/v1/definitely-missing'), 'api 404');
        $this->assertSecurityHeaders($this->get('/definitely-missing-page'), 'web 404');
    }

    public function testAuthenticatedResponsesAreNotCacheable(): void
    {
        $this->loginAs('+254700000010');

        $me = $this->get('/api/v1/auth/me');
        $this->assertContains('no-store', (string) $me->header('Cache-Control'));

        $app = $this->get('/app');
        $this->assertStatus(200, $app);
        $this->assertContains('no-store', (string) $app->header('Cache-Control'));
    }

    public function testCorrelationIdHeaderIsHonouredWhenValid(): void
    {
        $correlationId = Uuid::v4();

        $response = $this->get('/api/v1/health', false, ['X-Correlation-ID' => $correlationId]);

        $this->assertHeader('X-Correlation-ID', $correlationId, $response);
    }

    public function testInvalidCorrelationIdHeaderIsReplaced(): void
    {
        $response = $this->get('/api/v1/health', false, ['X-Correlation-ID' => 'not-a-uuid']);

        $replacement = (string) $response->header('X-Correlation-ID');

        $this->assertNotSame('not-a-uuid', $replacement);
        $this->assertTrue(Uuid::isValid($replacement));
    }

    public function testHstsIsNotSentOverPlainHttp(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertNull($response->header('Strict-Transport-Security'), 'HSTS is disabled for local HTTP.');
    }

    public function testApiErrorsDoNotRenderHtml(): void
    {
        $response = $this->get('/api/v1/definitely-missing');

        $this->assertStatus(404, $response);
        $this->assertContains('application/json', (string) $response->header('Content-Type'));
        $this->assertFalse(str_contains($response->body(), '<html'), 'API errors must never return an HTML error page.');
    }
}
