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

    /**
     * Local, openly licensed cover images for the six posts (owner request:
     * images from public repositories). Files live in public/assets/img/blog/
     * and are served locally (no hotlinking or CDN, CSP/offline intact). The
     * credit string is rendered under each image and the full source/licence
     * list lives in docs/blog-image-credits.md.
     *
     * @var array<string, array{file: string, alt: string, credit: string, width: int, height: int}>
     */
    private const IMAGES = [
        'digital-visitor-passes-vs-paper-registers' => [
            'file' => 'img/blog/digital-visitor-passes-vs-paper-registers.webp',
            'alt' => 'Guests checking in with a receptionist at a hotel front desk.',
            'credit' => 'Photo: Usien, CC0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
        'true-cost-of-queues-at-the-gate' => [
            'file' => 'img/blog/true-cost-of-queues-at-the-gate.webp',
            'alt' => 'Cars queuing in slow-moving traffic on a busy approach road.',
            'credit' => 'Photo: Editor5807, CC BY 3.0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
        'automated-access-rules-explained' => [
            'file' => 'img/blog/automated-access-rules-explained.webp',
            'alt' => 'A keycard reader mounted on a wall beside a use-keycard-for-entry sign.',
            'credit' => 'Photo: Donald Trung Quoc Don, CC BY-SA 4.0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
        'frictionless-payment-collection-for-estates' => [
            'file' => 'img/blog/frictionless-payment-collection-for-estates.webp',
            'alt' => 'An M-Pesa and Equity mobile money agent shop in Nairobi, Kenya.',
            'credit' => 'Photo: Fiona Graham, CC BY-SA 2.0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
        'qr-plates-and-boom-barriers' => [
            'file' => 'img/blog/qr-plates-and-boom-barriers.webp',
            'alt' => 'A parking boom barrier and payment machine at a commercial car park entrance.',
            'credit' => 'Photo: ParkingBOXXSolutions, CC BY 4.0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
        'real-time-logs-and-blacklisting-playbook' => [
            'file' => 'img/blog/real-time-logs-and-blacklisting-playbook.webp',
            'alt' => 'A CCTV security camera mounted on the corner of a building facade.',
            'credit' => 'Photo: WrS.tm.pl, CC0, via Wikimedia Commons (cropped and resized).',
            'width' => 1280,
            'height' => 720,
        ],
    ];

    public function index(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/blog',
            'Insights & updates',
            'DashPASS insights for estate managers and residents: visitor passes, QR and PIN access, '
                . 'offline passes, estate payments, account security and estate administration best practice.',
            [
                'posts' => $this->attachImages(self::allPosts()),
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
        $posts = $this->attachImages(self::allPosts());
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
                'ogImage' => isset($post['image']['absolute_url'])
                    ? (string) $post['image']['absolute_url']
                    : $this->canonical('/assets/img/landing-hero-dashpass.webp'),
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

    /**
     * Attach the local cover image metadata (url/alt/credit/dimensions) and the
     * absolute URL used for Open Graph/Twitter metadata to each post.
     *
     * @param array<int, array<string, mixed>> $posts
     * @return array<int, array<string, mixed>>
     */
    private function attachImages(array $posts): array
    {
        foreach ($posts as $position => $post) {
            $slug = (string) ($post['slug'] ?? '');
            $image = self::IMAGES[$slug] ?? null;

            if ($image === null) {
                continue;
            }

            $posts[$position]['image'] = [
                'url' => asset($image['file']),
                'absolute_url' => $this->canonical('/assets/' . ltrim($image['file'], '/')),
                'alt' => $image['alt'],
                'credit' => $image['credit'],
                'width' => $image['width'],
                'height' => $image['height'],
            ];
        }

        return $posts;
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
