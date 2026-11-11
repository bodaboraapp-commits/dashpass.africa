<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Resolves route middleware specifications such as `permission:audit.view`
 * into callables that participate in the request pipeline.
 */
final class MiddlewareResolver
{
    /** @var array<string, callable(?string): callable> */
    private array $factories = [];

    /**
     * @param callable(?string): callable $factory
     */
    public function register(string $alias, callable $factory): void
    {
        $this->factories[$alias] = $factory;
    }

    /**
     * @return callable(Request, array<string, string|null>, Context, callable): Response
     */
    public function resolve(string $spec): callable
    {
        $parts = explode(':', $spec, 2);
        $alias = $parts[0];
        $argument = $parts[1] ?? null;

        if (!isset($this->factories[$alias])) {
            throw new RuntimeException('Unknown middleware alias: ' . $alias);
        }

        $middleware = ($this->factories[$alias])($argument);

        if (!is_callable($middleware)) {
            throw new RuntimeException('Middleware factory did not return a callable for alias: ' . $alias);
        }

        return $middleware;
    }

    /**
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return array_keys($this->factories);
    }
}
