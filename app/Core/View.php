<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Tiny server-side template renderer.
 *
 * Templates are plain PHP files under `app/Views`. All dynamic output must be
 * escaped with e() unless it is deliberately trusted markup.
 */
final class View
{
    /**
     * Render a view inside a layout and return an HTML response.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], string $layout = 'app'): Response
    {
        $data['title'] ??= (string) Config::get('app.name', 'MtaaPass');
        $content = self::capture($view, $data);
        $html = self::capture('layouts/' . $layout, $data + ['content' => $content]);

        return Response::html($html);
    }

    /**
     * Render a view to a string without a layout.
     *
     * @param array<string, mixed> $data
     */
    public static function capture(string $view, array $data = []): string
    {
        $path = BASE_PATH . '/app/Views/' . $view . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $view);
        }

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $path;

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }

    public static function exists(string $view): bool
    {
        return is_file(BASE_PATH . '/app/Views/' . $view . '.php');
    }
}
