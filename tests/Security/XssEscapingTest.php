<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Uuid;
use RuntimeException;

/**
 * Output escaping / XSS (Blueprint §60): no dynamic value may reach the page as
 * raw markup, including debug error messages and user-controlled names.
 */
final class XssEscapingTest extends SecurityTestCase
{
    private const PAYLOAD = '<script>alert("xss")</script>';

    public function testErrorPageEscapesDebugExceptionMessage(): void
    {
        $response = ErrorHandler::handle(
            new RuntimeException(self::PAYLOAD),
            Request::create('GET', '/boom'),
            Uuid::v4()
        );

        $this->assertStatus(500, $response);

        $body = $response->body();
        $this->assertFalse(str_contains($body, self::PAYLOAD), 'The raw exception message must never be rendered as HTML.');
        $this->assertFalse(str_contains($body, 'alert("xss")'), 'Injected script code must be escaped.');
        $this->assertContains('&lt;script&gt;', $body, 'The escaped markup should still be visible in debug mode.');
        $this->assertContains('500', $body);
    }

    public function testErrorPageIsACompleteHtmlDocument(): void
    {
        $response = ErrorHandler::handle(
            new RuntimeException('boom'),
            Request::create('GET', '/boom'),
            Uuid::v4()
        );

        $body = strtolower($response->body());

        $this->assertContains('<!doctype html', $body);
        $this->assertContains('<html', $body);
        $this->assertContains('</html>', $body);
    }

    public function testUserFullNameIsEscapedInAuthenticatedPages(): void
    {
        $jane = $this->seededUser('+254700000010');

        $affected = $this->db->update(
            'users',
            ['full_name' => 'Jane <script>alert("xss")</script>'],
            ['id' => (int) $jane['id']]
        );
        $this->assertSame(1, $affected, 'Database::update() returns the affected row count.');

        $this->loginAs('+254700000010');

        foreach (['/app', '/app/account'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response);

            $body = $response->body();
            $this->assertFalse(
                str_contains($body, '<script>alert("xss")</script>'),
                'A user-controlled full name must be escaped on ' . $path . '.'
            );
        }
    }

    public function testEstateNameIsEscapedInAuthenticatedPages(): void
    {
        $greenview = $this->seededEstate('greenview-residences');

        $affected = $this->db->update(
            'estates',
            ['name' => 'Greenview <script>alert("estate")</script>'],
            ['id' => (int) $greenview['id']]
        );
        $this->assertSame(1, $affected);

        $this->loginAs('+254700000010');

        $response = $this->get('/app');
        $this->assertStatus(200, $response);

        $this->assertFalse(
            str_contains($response->body(), '<script>alert("estate")</script>'),
            'A database value rendered into the page must be escaped.'
        );
    }

    public function testFlashErrorMessageIsEscaped(): void
    {
        \App\Core\Session::flash('error', '<script>alert("flash")</script>');

        $response = $this->get('/login');

        $this->assertStatus(200, $response);
        $this->assertFalse(
            str_contains($response->body(), '<script>alert("flash")</script>'),
            'A flashed error message must be escaped when rendered.'
        );
    }

    public function testJsonResponsesDoNotContainUnescapedHtml(): void
    {
        $response = $this->get('/api/v1/definitely-missing');

        $this->assertContains('application/json', (string) $response->header('Content-Type'));
        $this->assertNotContains('<html', $response->body());
        $this->assertNotContains('<script', $response->body());
    }
}
