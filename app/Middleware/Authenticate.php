<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Requires an authenticated session. API callers receive a JSON 401, browser
 * callers are redirected to the login screen with the intended path remembered.
 */
final class Authenticate
{
    /**
     * @param array<string, string|null> $params
     */
    public function __invoke(Request $request, array $params, Context $context, callable $next): Response
    {
        if ($context->auth->check()) {
            return $next($request, $params, $context);
        }

        if ($request->expectsJson()) {
            throw HttpException::unauthorized();
        }

        Session::put('intended_url', $request->path());

        return Response::redirect('/login');
    }
}
