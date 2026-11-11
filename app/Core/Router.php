<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Route table and dispatcher.
 *
 * Handlers receive (Request, array $params, Context) and return a Response.
 * Middleware receives (Request, array $params, Context, callable $next).
 */
final class Router
{
    /** @var array<int, array{method: string, path: string, regex: string, params: array<int, string>, handler: callable|array{0: class-string, 1: string}, middleware: array<int, string>}> */
    private array $routes = [];

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        [$regex, $params] = self::compile($path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request $request, Context $context, MiddlewareResolver $resolver): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method()) {
                $allowed[] = $route['method'];

                continue;
            }

            $params = [];

            foreach ($route['params'] as $name) {
                $params[$name] = isset($matches[$name]) ? rawurldecode($matches[$name]) : null;
            }

            return $this->runPipeline($route['handler'], $route['middleware'], $request, $params, $context, $resolver);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'The requested method is not allowed for this endpoint.');
        }

        throw HttpException::notFound('ROUTE_NOT_FOUND', 'The page you requested could not be found.');
    }

    /**
     * @return array<int, array{method: string, path: string}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $route): array => ['method' => $route['method'], 'path' => $route['path']],
            $this->routes
        );
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     * @param array<string, string|null> $params
     */
    private function runPipeline(
        callable|array $handler,
        array $middleware,
        Request $request,
        array $params,
        Context $context,
        MiddlewareResolver $resolver
    ): Response {
        $core = fn (Request $current): Response => $this->invoke($handler, $current, $params, $context);

        $next = $core;

        foreach (array_reverse($middleware) as $spec) {
            $resolved = $resolver->resolve($spec);
            $previous = $next;

            $next = static fn (Request $current): Response => $resolved($current, $params, $context, $previous);
        }

        return $next($request);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<string, string|null> $params
     */
    private function invoke(callable|array $handler, Request $request, array $params, Context $context): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;

            if (!class_exists($class)) {
                throw new \RuntimeException('Route handler class does not exist: ' . $class);
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException(sprintf('Route handler %s::%s does not exist.', $class, $method));
            }

            $result = $controller->{$method}($request, $params, $context);
        } else {
            $result = $handler($request, $params, $context);
        }

        if (!$result instanceof Response) {
            throw new \RuntimeException('Route handlers must return an App\\Core\\Response instance.');
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private static function compile(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $params = [];
        $patternSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                $patternSegments[] = '';

                continue;
            }

            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $name = $matches[1];
                $params[] = $name;
                $patternSegments[] = '(?P<' . $name . '>[^/]+)';

                continue;
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $patternSegments) . '$#';

        return [$regex, $params];
    }
}
