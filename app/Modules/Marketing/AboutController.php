<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Guest About page for the landing redesign (no auth; no DB writes).
 */
final class AboutController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $data = [
            'title' => 'About',
            'appName' => (string) Config::get('app.name', 'MtaaPass'),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
        ];

        if (View::exists('guest/about')) {
            return View::render('guest/about', $data, 'guest');
        }

        return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>About · ' . e($data['appName']) . '</title></head><body><main>'
            . '<h1>About ' . e($data['appName']) . '</h1>'
            . '<p>The calm, secure front door to your estate: visitor passes, parking and gate access in one place.</p>'
            . '<p><a href="/">Back to home</a></p></main></body></html>');
    }
}
