<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Request;
use Tests\TestCase;

/**
 * Request value object: path normalisation, JSON/form body parsing and the
 * header handling the CSRF/JSON layers rely on.
 */
final class RequestTest extends TestCase
{
    public function testNormalizesPaths(): void
    {
        $this->assertSame('/', Request::normalizePath('/'));
        $this->assertSame('/health', Request::normalizePath('/health'));
        $this->assertSame('/health', Request::normalizePath('/health/'));
        $this->assertSame('/foo/bar', Request::normalizePath('//foo///bar//'));
        $this->assertSame('/login', Request::normalizePath('/login?next=/app'));
        $this->assertSame('/app/estates', Request::normalizePath('app/estates/'));
    }

    public function testMethodIsUppercasedAndSafeMethodsDetected(): void
    {
        $get = Request::create('get', '/health');
        $this->assertSame('GET', $get->method());
        $this->assertTrue($get->isMethod('GET'));
        $this->assertTrue($get->isMethod('get'));
        $this->assertTrue($get->isSafeMethod());

        $post = Request::create('post', '/login/otp');
        $this->assertSame('POST', $post->method());
        $this->assertFalse($post->isSafeMethod());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = Request::create('GET', '/', [], ['X-Correlation-ID' => 'abc-123', 'Accept' => 'application/json']);

        $this->assertSame('abc-123', $request->header('x-correlation-id'));
        $this->assertSame('abc-123', $request->header('X-CORRELATION-ID'));
        $this->assertSame('application/json', $request->header('accept'));
        $this->assertNull($request->header('missing'));
        $this->assertSame('fallback', $request->header('missing', 'fallback'));
    }

    public function testJsonBodyParsing(): void
    {
        $request = Request::create(
            'POST',
            '/api/v1/auth/request-otp',
            [],
            ['Content-Type' => 'application/json'],
            '{"phone":"0712345678","code":"123456"}'
        );

        $this->assertTrue($request->isJson());
        $this->assertTrue($request->expectsJson());
        $this->assertSame(['phone' => '0712345678', 'code' => '123456'], $request->json());
        $this->assertSame('0712345678', $request->input('phone'));
        $this->assertSame('123456', $request->string('code'));
        $this->assertSame(123456, $request->integer('code'));
        $this->assertSame('fallback', $request->input('missing', 'fallback'));
    }

    public function testJsonTakesPrecedenceOverFormFieldsForApiPaths(): void
    {
        $request = Request::create(
            'POST',
            '/api/v1/auth/request-otp',
            ['phone' => 'form-value'],
            ['Content-Type' => 'application/json'],
            '{"phone":"json-value"}'
        );

        $this->assertSame('json-value', $request->input('phone'));
    }

    public function testInvalidJsonThrowsBadRequest(): void
    {
        $request = Request::create('POST', '/api/v1/auth/request-otp', [], ['Content-Type' => 'application/json'], '{broken');

        try {
            $request->json();
            $this->fail('Malformed JSON must throw a 400 HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->status());
            $this->assertSame('INVALID_JSON', $exception->errorCode());
        }
    }

    public function testNonObjectJsonBodyIsRejected(): void
    {
        foreach (['123', '"string"', 'true'] as $body) {
            $request = Request::create('POST', '/api/v1/x', [], ['Content-Type' => 'application/json'], $body);

            try {
                $request->json();
                $this->fail(sprintf('A non-object JSON body (%s) must be rejected.', $body));
            } catch (HttpException $exception) {
                $this->assertSame(400, $exception->status());
                $this->assertSame('INVALID_JSON', $exception->errorCode());
            }
        }
    }

    public function testFormBodyIsReturnedForWebPaths(): void
    {
        $request = Request::create('POST', '/login/otp', ['phone' => '0712345678']);

        $this->assertFalse($request->isJson());
        $this->assertSame(['phone' => '0712345678'], $request->all());
        $this->assertSame('0712345678', $request->input('phone'));
    }

    public function testFormContentTypeDetection(): void
    {
        $form = Request::create(
            'POST',
            '/login/otp',
            ['phone' => '0712345678'],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );
        $this->assertTrue($form->isForm());

        $multipart = Request::create('POST', '/login/otp', [], ['Content-Type' => 'multipart/form-data; boundary=x']);
        $this->assertTrue($multipart->isForm());
    }

    public function testExpectsJson(): void
    {
        $this->assertTrue(Request::create('GET', '/api/v1/auth/me')->expectsJson());
        $this->assertFalse(Request::create('GET', '/login')->expectsJson());
        $this->assertTrue(Request::create('GET', '/login', [], ['Accept' => 'application/json'])->expectsJson());
    }

    public function testQueryStringAccess(): void
    {
        $request = new Request('GET', '/api/v1/estate/audit-logs', ['limit' => '25']);

        $this->assertSame(['limit' => '25'], $request->query());
        $this->assertSame('25', $request->queryString('limit'));
        $this->assertNull($request->queryString('missing'));
        $this->assertSame('50', $request->queryString('missing', '50'));
    }

    public function testUserAgentIsTruncatedToColumnLength(): void
    {
        $request = Request::create('GET', '/', [], [], '', '127.0.0.1', str_repeat('a', 300));

        $this->assertSame(255, strlen($request->userAgent()));
    }

    public function testIpAndUserAgentDefaults(): void
    {
        $request = Request::create('GET', '/');

        $this->assertSame('127.0.0.1', $request->ip());
        $this->assertSame('MtaaPass-Test/1.0', $request->userAgent());
    }

    public function testTrailingSlashIsRemovedFromCreatedRequests(): void
    {
        $this->assertSame('/api/v1/health', Request::create('GET', '/api/v1/health/')->path());
    }
}
