<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Dynamic XML sitemap (`GET /sitemap.xml`).
 *
 * Lists the public marketing pages plus every published blog post with its
 * `<lastmod>`. Absolute URLs come from `app.url` (https://dashpass.africa
 * fallback); values are XML-escaped. This is one of the two intentionally
 * cacheable public SEO documents (see App\Middleware\SecurityHeaders).
 */
final class SitemapController
{
    private const SITE_FALLBACK = 'https://dashpass.africa';

    public function show(Request $request, array $params, Context $context): Response
    {
        $origin = rtrim((string) Config::get('app.url', ''), '/');

        if ($origin === '') {
            $origin = self::SITE_FALLBACK;
        }

        $posts = BlogController::postsWithImages();
        $latestUpdate = '';

        foreach ($posts as $post) {
            $updated = trim((string) ($post['updated_at'] ?? ''));
            $published = trim((string) ($post['published_at'] ?? ''));
            $candidate = $updated !== '' ? $updated : $published;

            if ($candidate !== '' && strcmp($candidate, $latestUpdate) > 0) {
                $latestUpdate = $candidate;
            }
        }

        if ($latestUpdate === '') {
            $latestUpdate = date('Y-m-d');
        }

        $entries = [];

        foreach (['/', '/about', '/blog', '/help'] as $path) {
            $entries[] = ['loc' => $origin . $path, 'lastmod' => $latestUpdate, 'images' => []];
        }

        foreach ($posts as $post) {
            $slug = trim((string) ($post['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $updated = trim((string) ($post['updated_at'] ?? ''));
            $published = trim((string) ($post['published_at'] ?? ''));
            $images = [];
            $imageUrl = trim((string) ($post['image']['absolute_url'] ?? ''));

            if ($imageUrl !== '') {
                $images[] = [
                    'loc' => $imageUrl,
                    'title' => trim((string) ($post['title'] ?? '')),
                    'caption' => trim((string) ($post['image']['credit'] ?? '')),
                ];
            }

            $entries[] = [
                'loc' => $origin . '/blog/' . rawurlencode($slug),
                'lastmod' => $updated !== '' ? $updated : ($published !== '' ? $published : $latestUpdate),
                'images' => $images,
            ];
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
                . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
        ];

        foreach ($entries as $entry) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->xml($entry['loc']) . '</loc>';
            $lines[] = '    <lastmod>' . $this->xml($entry['lastmod']) . '</lastmod>';

            foreach ((array) ($entry['images'] ?? []) as $image) {
                $lines[] = '    <image:image>';
                $lines[] = '      <image:loc>' . $this->xml((string) $image['loc']) . '</image:loc>';
                $lines[] = '      <image:title>' . $this->xml((string) $image['title']) . '</image:title>';

                if ((string) ($image['caption'] ?? '') !== '') {
                    $lines[] = '      <image:caption>' . $this->xml((string) $image['caption']) . '</image:caption>';
                }

                $lines[] = '    </image:image>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return (new Response(implode("\n", $lines) . "\n", 200))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function xml(string $value): string
    {
        return (string) htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
