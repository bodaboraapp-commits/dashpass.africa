<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\MiddlewareResolver;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * The router is the front door of the whole application: matching, parameter
 * decoding, 404 vs 405 and middleware composition must be exact.
 */
final class RouterTest extends TestCase
{
    private function context(string $path = '/'): Context
    {
        return new Context(Request::create('GET', $path), Uuid::v4());
    }

    private function echoingHandler(): callable
    {
        return static fn (Request $request, array $params, Context $context): Response => Response::json([
            'params' => $params,
            'method' => $request->method(),
        ]);
    }

    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): Response => Response::json(['status' => 'ok']));

        $response = $router->dispatch(Request::create('GET', '/health'), $this->context('/health'), new MiddlewareResolver());

        $this->assertSame(200, $response->status());
        $this->assertSame(['status' => 'ok'], $this->json($response));
    }

    public function testExtractsRouteParameters(): void
    {
        $router = new Router();
        $router->get('/greet/{name}', $this->echoingHandler());

        $response = $router->dispatch(Request::create('GET', '/greet/grace'), $this->context('/greet/grace'), new MiddlewareResolver());
        $payload = $this->json($response);

        $this->assertSame(['name' => 'grace'], $payload['params']);
    }

    public function testExtractsMultipleUrlDecodedParameters(): void
    {
        $router = new Router();
        $router->post('/estates/{estateUuid}/members/{memberUuid}', $this->echoingHandler());

        $request = Request::create('POST', '/estates/abc-123/members/Grace%20Wanjiku');
        $response = $router->dispatch($request, $this->context(), new MiddlewareResolver());
        $payload = $this->json($response);

        $this->assertSame(
            ['estateUuid' => 'abc-123', 'memberUuid' => 'Grace Wanjiku'],
            $payload['params']
        );
    }

    public function testTrailingSlashIsNormalisedBeforeMatching(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): Response => Response::json(['ok' => true]));

        $response = $router->dispatch(Request::create('GET', '/health/'), $this->context('/health/'), new MiddlewareResolver());

        $this->assertSame(200, $response->status());
    }

    public function testUnknownRouteThrows404(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): Response => Response::json(['ok' => true]));

        try {
            $router->dispatch(Request::create('GET', '/nope'), $this->context('/nope'), new MiddlewareResolver());
            $this->fail('An unknown path must throw a 404 HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->status());
            $this->assertSame('ROUTE_NOT_FOUND', $exception->errorCode());
        }
    }

    public function testWrongMethodThrows405(): void
    {
        $router = new Router();
        $router->post('/logout', static fn (): Response => Response::noContent());

        try {
            $router->dispatch(Request::create('GET', '/logout'), $this->context('/logout'), new MiddlewareResolver());
            $this->fail('A wrong method on an existing path must throw a 405.');
        } catch (HttpException $exception) {
            $this->assertSame(405, $exception->status());
            $this->assertSame('METHOD_NOT_ALLOWED', $exception->errorCode());
        }
    }

    public function testPlaceholderDoesNotMatchAcrossSegments(): void
    {
        $router = new Router();
        $router->get('/estates/{uuid}', $this->echoingHandler());

        try {
            $router->dispatch(Request::create('GET', '/estates/a/b'), $this->context(), new MiddlewareResolver());
            $this->fail('A {uuid} placeholder must not match a nested path.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->status());
        }
    }

    public function testMiddlewareRunsInThePipeline(): void
    {
        $router = new Router();
        $resolver = new MiddlewareResolver();
        $resolver->register('tag', static fn (?string $argument): callable => static function (
            Request $request,
            array $params,
            Context $context,
            callable $next
        ) use ($argument): Response {
            $response = $next($request, $params, $context);

            return $response->withHeader('X-Tag-' . $argument, '1');
        });

        $router->get('/tagged', static fn (): Response => Response::json(['ok' => true]), ['tag:first', 'tag:second']);

        $response = $router->dispatch(Request::create('GET', '/tagged'), $this->context(), $resolver);

        $this->assertSame('1', $response->header('x-tag-first'));
        $this->assertSame('1', $response->header('x-tag-second'));
    }

    public function testUnknownMiddlewareAliasThrows(): void
    {
        $router = new Router();
        $router->get('/guarded', static fn (): Response => Response::json(['ok' => true]), ['does-not-exist']);

        try {
            $router->dispatch(Request::create('GET', '/guarded'), $this->context(), new MiddlewareResolver());
            $this->fail('An unresolved middleware alias must throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertContains('Unknown middleware alias', $exception->getMessage());
        }
    }

    public function testParameterisedRouteRegistrationList(): void
    {
        $router = new Router();
        $router->get('/a/{x}', $this->echoingHandler());
        $router->post('/a/{x}', $this->echoingHandler());

        $routes = $router->routes();

        $this->assertCount(2, $routes);
        $this->assertSame(['method' => 'GET', 'path' => '/a/{x}'], $routes[0]);
        $this->assertSame(['method' => 'POST', 'path' => '/a/{x}'], $routes[1]);
    }
}
