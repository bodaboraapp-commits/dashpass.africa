<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Enforces a single granular permission slug for the active estate.
 */
final class RequirePermission
{
    public function __construct(private readonly string $permission)
    {
    }

    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        $context->permission->authorize($this->permission);

        return $next($request, $params, $context);
    }
}
