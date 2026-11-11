<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Keeps authenticated users away from the login screen.
 */
final class GuestOnly
{
    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        if ($context->auth->check()) {
            return Response::redirect('/app');
        }

        return $next($request, $params, $context);
    }
}
