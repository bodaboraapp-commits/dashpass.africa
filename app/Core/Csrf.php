<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser token CSRF protection.
 *
 * The token lives in the session and must be echoed back in the
 * `X-CSRF-Token` header or the `_token` form field for every state-changing
 * request. Tokens rotate on login and logout.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Session::start();

        $existing = Session::get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = Crypto::randomToken(32);
        Session::put(self::SESSION_KEY, $token);

        return $token;
    }

    public static function regenerate(): string
    {
        $token = Crypto::randomToken(32);
        Session::put(self::SESSION_KEY, $token);

        return $token;
    }

    public static function check(?string $token): bool
    {
        $stored = Session::get(self::SESSION_KEY);

        if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public static function tokenFromRequest(Request $request): ?string
    {
        foreach ((array) Config::get('security.csrf.header_names', []) as $header) {
            $value = $request->header((string) $header);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $field = (string) Config::get('security.csrf.form_field', '_token');
        $body = $request->input($field);

        return is_string($body) && $body !== '' ? $body : null;
    }
}
