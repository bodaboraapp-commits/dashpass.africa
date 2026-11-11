<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Rejects state-changing requests that do not present the session CSRF token.
 *
 * Applies to every cookie-authenticated route, including login, logout and
 * estate switching. Safe methods (GET/HEAD/OPTIONS) and the public health
 * endpoints are exempt.
 */
final class CsrfProtection
{
    /** @var array<int, string> */
    private const EXEMPT_PATHS = ['/health', '/api/v1/health'];

    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        if ($request->isSafeMethod()) {
            return $next($request, $params, $context);
        }

        if (in_array($request->path(), self::EXEMPT_PATHS, true)) {
            return $next($request, $params, $context);
        }

        if (!Csrf::check(Csrf::tokenFromRequest($request))) {
            throw HttpException::forbidden(
                'CSRF_TOKEN_INVALID',
                'Your session security token is missing or has expired. Refresh the page and try again.'
            );
        }

        return $next($request, $params, $context);
    }
}
