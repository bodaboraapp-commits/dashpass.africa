<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Dynamic robots.txt (`GET /robots.txt`).
 *
 * Production allows the public marketing pages, points crawlers at the sitemap
 * and keeps authenticated/private surfaces out of the index. Non-production
 * environments are deny-all so dev/staging/ngrok hosts can never be indexed.
 * This is one of the two intentionally cacheable public SEO documents (see
 * App\Middleware\SecurityHeaders).
 */
final class RobotsController
{
    private const SITE_FALLBACK = 'https://dashpass.africa';

    private const DISALLOW = [
        '/app',
        '/admin',
        '/platform',
        '/gate',
        '/api',
        '/notifications',
        '/login/',
    ];

    /**
     * Major AI and search crawlers that are explicitly welcome on the public
     * marketing content (private paths stay disallowed by the wildcard block).
     *
     * @var array<int, string>
     */
    private const AI_AGENTS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'anthropic-ai',
        'PerplexityBot',
        'Googlebot',
        'Google-Extended',
        'Bingbot',
        'CCBot',
        'Applebot-Extended',
    ];

    public function show(Request $request, array $params, Context $context): Response
    {
        $isProduction = (string) Config::get('app.env', 'production') === 'production';

        if (!$isProduction) {
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $origin = rtrim((string) Config::get('app.url', ''), '/');

            if ($origin === '') {
                $origin = self::SITE_FALLBACK;
            }

            $lines = [
                'User-agent: *',
                'Allow: /',
            ];

            foreach (self::DISALLOW as $path) {
                $lines[] = 'Disallow: ' . $path;
            }

            $lines[] = '';
            $lines[] = '# AI and search crawlers: the public marketing content is welcome.';
            $lines[] = '# AI-agent guide: ' . $origin . '/llms.txt';

            foreach (self::AI_AGENTS as $agent) {
                $lines[] = '';
                $lines[] = 'User-agent: ' . $agent;
                $lines[] = 'Allow: /';
            }

            $lines[] = '';
            $lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';
            $body = implode("\n", $lines) . "\n";
        }

        return (new Response($body, 200))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
