<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Central error handling: nothing leaks to the client, everything is logged.
 *
 * HTTP exceptions map to their status and public message. Anything else becomes
 * a generic 500 with a correlation id that a support engineer can trace in the
 * application log.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $exception): void {
            $request = Request::fromGlobals();
            $correlationId = Logger::correlationId();

            self::handle($exception, $request, $correlationId)->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error('Fatal error', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        });
    }

    public static function handle(Throwable $exception, Request $request, string $correlationId): Response
    {
        $status = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'Something went wrong on our side. Please try again.';
        $extra = [];

        if ($exception instanceof HttpException) {
            $status = $exception->status();
            $code = $exception->errorCode();
            $message = $exception->publicMessage();
            $extra = $exception->extra();
        }

        if ($status >= 500) {
            Logger::error('Unhandled exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => mb_substr($exception->getTraceAsString(), 0, 4000),
            ]);
        } elseif ($status === 403 || $status === 401) {
            Logger::security('Request rejected', [
                'status' => $status,
                'code' => $code,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);
        } else {
            Logger::info('Request rejected', [
                'status' => $status,
                'code' => $code,
                'path' => $request->path(),
            ]);
        }

        if ($request->expectsJson()) {
            $errorExtra = $extra;

            if ((bool) Config::get('app.debug', false) && $status >= 500) {
                $errorExtra['debug'] = [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            return Response::error($code, $message, $status, $errorExtra, $correlationId);
        }

        $viewData = [
            'title' => 'Error ' . $status,
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'correlationId' => $correlationId,
            'debug' => (bool) Config::get('app.debug', false),
            'debugMessage' => (bool) Config::get('app.debug', false) ? $exception->getMessage() : null,
        ];

        if ($status === 404) {
            $viewData['title'] = 'Page not found';
        }

        $body = View::capture('errors/error', $viewData);

        return Response::html($body, $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Correlation-ID', $correlationId);
    }
}
