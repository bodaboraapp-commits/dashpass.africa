<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Ensures a validated tenant context exists for the request.
 */
final class EnsureEstate
{
    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        if ($context->tenant->active() === null) {
            if ($request->expectsJson()) {
                throw new HttpException(
                    403,
                    'ESTATE_CONTEXT_REQUIRED',
                    'Your account does not have access to an active estate.'
                );
            }

            return Response::redirect('/app/no-estate');
        }

        return $next($request, $params, $context);
    }
}
