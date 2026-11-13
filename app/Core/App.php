<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\CsrfProtection;
use App\Middleware\EnsureEstate;
use App\Middleware\GuestOnly;
use App\Middleware\RequirePermission;
use App\Middleware\SecurityHeaders;
use App\Middleware\Throttle;
use Throwable;

/**
 * Application kernel: boots configuration, registers routes and middleware,
 * then dispatches one request through the pipeline.
 */
final class App
{
    private Router $router;

    private MiddlewareResolver $middlewareResolver;

    private function __construct()
    {
        $this->router = new Router();
        $this->middlewareResolver = new MiddlewareResolver();
    }

    public static function boot(): self
    {
        ErrorHandler::register();

        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }

        $app = new self();
        $app->registerMiddleware();
        $app->loadRoutes();

        // Owner request 2026-11-13: cPanel Git deploys apply pending migrations
        // automatically on the first web request (CLI keeps explicit control).
        AutoMigrator::runIfNeeded();

        return $app;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function middlewareResolver(): MiddlewareResolver
    {
        return $this->middlewareResolver;
    }

    /**
     * Handle a single request end to end. Never throws: every failure becomes a
     * response rendered by the central error handler.
     */
    public function handle(Request $request): Response
    {
        $correlationId = Uuid::v4();

        try {
            Session::start($request);
            $correlationId = $this->resolveCorrelationId($request);
            Logger::setCorrelationId($correlationId);

            $context = new Context($request, $correlationId);
            Context::setCurrent($context);
            $router = $this->router;
            $resolver = $this->middlewareResolver;

            $pipeline = fn (Request $current): Response => $router->dispatch($current, $context, $resolver);

            // Global CSRF protection wraps the router and every route.
            $csrf = new CsrfProtection();
            $response = $csrf(
                $request,
                [],
                $context,
                static fn (Request $next): Response => $pipeline($next)
            );
        } catch (Throwable $exception) {
            $response = ErrorHandler::handle($exception, $request, $correlationId);
        }

        return SecurityHeaders::apply($response, $request)
            ->withHeader('X-Correlation-ID', $correlationId);
    }

    private function loadRoutes(): void
    {
        $router = $this->router;

        foreach (['web.php', 'api.php', 'platform.php'] as $file) {
            $path = BASE_PATH . '/routes/' . $file;

            if (is_file($path)) {
                require $path;
            }
        }
    }

    private function registerMiddleware(): void
    {
        $this->middlewareResolver->register('auth', static fn (?string $argument): callable => new Authenticate());
        $this->middlewareResolver->register('guest', static fn (?string $argument): callable => new GuestOnly());
        $this->middlewareResolver->register('estate', static fn (?string $argument): callable => new EnsureEstate());
        $this->middlewareResolver->register(
            'permission',
            static fn (?string $argument): callable => new RequirePermission((string) $argument)
        );
        $this->middlewareResolver->register(
            'throttle',
            static fn (?string $argument): callable => Throttle::fromSpec((string) $argument)
        );
    }

    private function resolveCorrelationId(Request $request): string
    {
        if ((bool) Config::get('security.headers.trust_correlation_header', true)) {
            $candidate = (string) $request->header('X-Correlation-ID', '');

            if (Uuid::isValid($candidate)) {
                return $candidate;
            }
        }

        return Uuid::v4();
    }
}
