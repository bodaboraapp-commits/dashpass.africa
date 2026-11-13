<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Public Blog (`GET /blog`) and blog posts (`GET /blog/{slug}`).
 *
 * Content lives in {@see BlogContent::posts()} (owned by the content task);
 * this controller only shapes page data (newest-first index, 404 for unknown
 * slugs, up to three related posts) and the SEO metadata forwarded to the guest
 * layout. All post strings are treated as untrusted and escaped in the views.
 */
final class BlogController
{
    use BuildsGuestMarketingPage;

    /**
     * schema.org base URL is kept in PHP (not the views) so view/asset sources
     * stay free of literal external URLs; microdata is emitted with it.
     */
    public const SCHEMA_BASE = 'https://schema.org';

    private const SITE_FALLBACK = 'https://dashpass.africa';

    public function index(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/blog',
            'Insights & updates',
            'DashPASS insights for estate managers and residents: visitor passes, QR and PIN access, '
                . 'offline passes, estate payments, account security and estate administration best practice.',
            [
                'posts' => self::allPosts(),
                'canonical' => $this->canonical('/blog'),
                'ogType' => 'website',
                'keywords' => [
                    'estate management software',
                    'visitor pass system',
                    'QR gate access',
                    'estate payments',
                    'DashPASS',
                ],
                'schemaBase' => self::SCHEMA_BASE,
            ]
        );
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $posts = self::allPosts();
        $index = null;

        foreach ($posts as $position => $post) {
            if ((string) ($post['slug'] ?? '') === $slug) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            throw HttpException::notFound('BLOG_POST_NOT_FOUND', 'That blog post could not be found.');
        }

        $post = $posts[$index];
        $publishedAt = (string) ($post['published_at'] ?? '');
        $modifiedAt = (string) ($post['updated_at'] ?? '') !== '' ? (string) $post['updated_at'] : $publishedAt;

        return $this->renderMarketingPage(
            'marketing/blog-post',
            (string) ($post['seo_title'] ?? '') !== '' ? (string) $post['seo_title'] : (string) ($post['title'] ?? 'Blog post'),
            (string) ($post['description'] ?? ''),
            [
                'post' => $post,
                'relatedPosts' => self::relatedPosts($posts, $index, 3),
                'canonical' => $this->canonical('/blog/' . rawurlencode($slug)),
                'ogType' => 'article',
                'publishedAt' => $publishedAt,
                'modifiedAt' => $modifiedAt,
                'author' => (string) ($post['author'] ?? 'DashPASS Team'),
                'keywords' => $post['keywords'] ?? [],
                'schemaBase' => self::SCHEMA_BASE,
            ]
        );
    }

    /**
     * The published posts, newest first. Defensive when the content data file is
     * not installed yet (returns an empty list, never an error).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allPosts(): array
    {
        if (!class_exists(BlogContent::class) || !method_exists(BlogContent::class, 'posts')) {
            return [];
        }

        $posts = BlogContent::posts();

        if (!is_array($posts)) {
            return [];
        }

        $posts = array_values(array_filter($posts, 'is_array'));

        usort(
            $posts,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['published_at'] ?? ''),
                (string) ($left['published_at'] ?? '')
            )
        );

        return $posts;
    }

    /**
     * Related posts: up to $limit other posts, taken nearest-first in the
     * newest-first list (wrapping around, never repeating the current post).
     *
     * @param array<int, array<string, mixed>> $posts
     * @return array<int, array<string, mixed>>
     */
    private static function relatedPosts(array $posts, int $index, int $limit): array
    {
        $related = [];
        $count = count($posts);
        $currentSlug = (string) ($posts[$index]['slug'] ?? '');

        for ($offset = 1; $offset < $count && count($related) < $limit; $offset++) {
            $candidate = $posts[($index + $offset) % $count];

            if ((string) ($candidate['slug'] ?? '') === $currentSlug) {
                continue;
            }

            $related[] = $candidate;
        }

        return $related;
    }

    private function canonical(string $path): string
    {
        $origin = rtrim((string) Config::get('app.url', ''), '/');

        if ($origin === '') {
            $origin = self::SITE_FALLBACK;
        }

        return $origin . '/' . ltrim($path, '/');
    }
}
