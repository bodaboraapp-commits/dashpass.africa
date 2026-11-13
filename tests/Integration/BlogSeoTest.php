<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Modules\Marketing\BlogContent;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * SEO blog infrastructure: 6-post index + post detail pages, sitemap.xml,
 * robots.txt, meta/Open Graph/Twitter tags, schema.org microdata (no inline
 * JSON-LD because of the strict CSP) and the public-cache header exception.
 */
final class BlogSeoTest extends VisitorModuleTestCase
{
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const SCHEMA = 'https://schema.org';

    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posts(): array
    {
        $posts = BlogContent::posts();
        $this->assertCount(6, $posts, 'The blog must publish exactly six posts.');

        return array_values($posts);
    }

    private function metaContent(\DOMDocument $dom, string $attribute, string $value): string
    {
        $nodes = $this->xpath(
            '//meta[@' . $attribute . '="' . $value . '"]',
            $dom
        );

        if ($nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? trim((string) $node->getAttribute('content')) : '';
    }

    /* ------------------------------------------------------------------ */
    /* Blog content contract + index                                      */
    /* ------------------------------------------------------------------ */

    public function testBlogContentContract(): void
    {
        $posts = $this->posts();
        $previousDate = null;

        foreach ($posts as $post) {
            foreach ([
                'slug', 'title', 'seo_title', 'description', 'keywords', 'published_at', 'updated_at',
                'author', 'category', 'reading_minutes', 'excerpt', 'sections', 'faq', 'related_links', 'cta',
            ] as $key) {
                $this->assertArrayHasKey($key, $post, 'Every blog post needs the "' . $key . '" key.');
            }

            $this->assertMatches('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $post['slug'], 'Post slugs must be lowercase kebab-case.');
            $this->assertTrue(mb_strlen((string) $post['seo_title']) <= 60, 'seo_title must be <= 60 chars for ' . $post['slug']);
            $this->assertTrue(mb_strlen((string) $post['description']) <= 155, 'description must be <= 155 chars for ' . $post['slug']);
            $this->assertTrue(mb_strlen((string) $post['excerpt']) <= 200, 'excerpt must be <= 200 chars for ' . $post['slug']);
            $this->assertMatches('/^\d{4}-\d{2}-\d{2}$/', (string) $post['published_at'], 'published_at must be Y-m-d.');
            $this->assertMatches('/^\d{4}-\d{2}-\d{2}$/', (string) $post['updated_at'], 'updated_at must be Y-m-d.');
            $this->assertTrue(is_int($post['reading_minutes']) && $post['reading_minutes'] > 0, 'reading_minutes must be a positive int.');
            $this->assertTrue(is_array($post['keywords']) && $post['keywords'] !== [], 'keywords must be a non-empty array.');
            $this->assertTrue(is_array($post['sections']) && count($post['sections']) >= 3, 'Every post needs at least three sections.');
            $this->assertCount(3, (array) $post['faq'], 'Every post needs exactly three FAQ pairs.');
            $this->assertCount(3, (array) $post['related_links'], 'Every post needs exactly three internal related links.');
            $this->assertMatches('/^\//', (string) $post['cta']['href'], 'The post CTA must be an internal path.');

            foreach ((array) $post['related_links'] as $link) {
                $this->assertMatches('/^\//', (string) ($link['href'] ?? ''), 'Related links must be internal paths.');
            }

            if ($previousDate !== null) {
                $this->assertTrue(
                    strcmp((string) $post['published_at'], $previousDate) <= 0,
                    'BlogContent::posts() must be newest-first (found ' . $post['published_at'] . ' after ' . $previousDate . ').'
                );
            }

            $previousDate = (string) $post['published_at'];
        }
    }

    public function testBlogIndexListsSixPostsNewestFirst(): void
    {
        $response = $this->get('/blog');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Blog index');
        $dom = $this->dom($response);

        $this->assertCount(1, $this->xpath('//h1', $dom), 'The blog index must render exactly one h1.');

        $postLinks = [];

        foreach ($this->xpath('//article[contains(@class,"card")]//a[starts-with(@href,"/blog/")][not(contains(@class,"btn"))]', $dom) as $link) {
            if ($link instanceof DOMElement) {
                $href = (string) $link->getAttribute('href');

                if (!in_array($href, $postLinks, true)) {
                    $postLinks[] = $href;
                }
            }
        }

        $expected = array_map(
            static fn (array $post): string => '/blog/' . (string) $post['slug'],
            $this->posts()
        );

        $this->assertSame($expected, $postLinks, 'The blog index must list all six posts newest-first.');

        // Every title is visible text on the index.
        foreach ($this->posts() as $post) {
            $this->assertTextContains((string) $post['title'], $response);
        }

        // SEO structure: breadcrumb + publisher Organization microdata.
        $this->assertCount(1, $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/BreadcrumbList"]', $dom), 'The blog index needs BreadcrumbList microdata.');
        $this->assertTrue(
            $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/Organization"]', $dom)->length >= 1,
            'The blog index needs Organization microdata.'
        );

        // Internal CTAs.
        foreach (['/login', '/help', '/about'] as $href) {
            $this->assertTrue(
                $this->xpath('//a[@href="' . $href . '"]', $dom)->length >= 1,
                'The blog index must link to ' . $href . '.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Post pages                                                         */
    /* ------------------------------------------------------------------ */

    public function testEveryBlogPostHasSeoMetadataAndMicrodata(): void
    {
        $base = $this->baseUrl();

        foreach ($this->posts() as $post) {
            $slug = (string) $post['slug'];
            $path = '/blog/' . $slug;
            $canonical = $base . $path;
            $response = $this->get($path);

            $this->assertStatus(200, $response, $path . ' must render.');
            $this->assertCleanMarkup($response, 'Blog post ' . $slug);
            $dom = $this->dom($response);

            // Unique H1 = the post title.
            $h1s = $this->xpath('//h1', $dom);
            $this->assertCount(1, $h1s, $path . ' must render exactly one h1.');
            $h1 = $h1s->item(0);
            $this->assertNotNull($h1);
            $this->assertSame((string) $post['title'], $this->text($h1), 'The post h1 must be the post title.');

            // Title, description, canonical, robots (non-production is noindex).
            $titleNode = $this->xpath('//title', $dom)->item(0);
            $this->assertNotNull($titleNode);
            $this->assertStringContainsString((string) $post['seo_title'], $this->text($titleNode), 'The <title> must contain the SEO title.');
            $this->assertSame((string) $post['description'], $this->metaContent($dom, 'name', 'description'));
            // In non-production the canonical is rendered root-relative (noindex dev hosts).
            $this->assertSame($path, (string) $this->xpath('//link[@rel="canonical"]/@href', $dom)->item(0)?->nodeValue);
            $this->assertSame('noindex, nofollow', $this->metaContent($dom, 'name', 'robots'), 'Non-production hosts must be noindex.');
            $this->assertStringContainsString((string) $post['keywords'][0], $this->metaContent($dom, 'name', 'keywords'));

            // Open Graph + Twitter.
            $this->assertSame('article', $this->metaContent($dom, 'property', 'og:type'));
            $this->assertSame($canonical, $this->metaContent($dom, 'property', 'og:url'));
            $this->assertStringContainsString((string) $post['seo_title'], $this->metaContent($dom, 'property', 'og:title'));
            $this->assertStringStartsWith($base . '/assets/', $this->metaContent($dom, 'property', 'og:image'));
            $this->assertNotEmpty($this->metaContent($dom, 'property', 'og:site_name'));
            $this->assertSame((string) $post['published_at'], $this->metaContent($dom, 'property', 'article:published_time'));
            $this->assertSame((string) $post['updated_at'], $this->metaContent($dom, 'property', 'article:modified_time'));
            $this->assertSame((string) $post['author'], $this->metaContent($dom, 'property', 'article:author'));
            $this->assertSame('summary_large_image', $this->metaContent($dom, 'name', 'twitter:card'));

            // BlogPosting microdata.
            $articles = $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/BlogPosting"]', $dom);
            $this->assertCount(1, $articles, 'The post needs BlogPosting microdata.');

            $headline = $this->xpath('//*[@itemprop="headline"]', $dom)->item(0);
            $this->assertNotNull($headline);
            $this->assertSame((string) $post['title'], $this->text($headline), 'The headline microdata must be the title.');

            $times = $this->xpath('//time[@datetime="' . (string) $post['published_at'] . '"]', $dom);
            $this->assertTrue($times->length >= 1, 'The post must render a <time datetime> for its publication date.');

            // Breadcrumbs: Home / Blog / Post with three ListItems.
            $this->assertCount(1, $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/BreadcrumbList"]', $dom), 'The post needs BreadcrumbList microdata.');
            $this->assertCount(3, $this->xpath('//*[@itemprop="itemListElement"]', $dom), 'The post breadcrumb must have three items.');

            // FAQPage microdata with three answers.
            $this->assertCount(1, $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/FAQPage"]', $dom), 'The post needs FAQPage microdata.');
            $this->assertCount(3, $this->xpath('//*[@itemscope][@itemtype="' . self::SCHEMA . '/Question"]', $dom), 'Every FAQ pair must be Question microdata.');

            // Internal interlinking: related posts + About/Help/sign-in CTAs.
            foreach (['/about', '/help', '/login'] as $href) {
                $this->assertTrue(
                    $this->xpath('//a[@href="' . $href . '"]', $dom)->length >= 1,
                    $path . ' must link to ' . $href . '.'
                );
            }

            $relatedHrefs = [];

            foreach ($this->xpath('//section[@aria-labelledby="post-related-title"]//a[starts-with(@href,"/blog/")]', $dom) as $link) {
                if ($link instanceof DOMElement) {
                    $relatedHrefs[] = (string) $link->getAttribute('href');
                }
            }

            $this->assertTrue($relatedHrefs !== [], 'Every post must link to related posts.');
            $this->assertNotContains($path, $relatedHrefs, 'A post must not list itself as related.');

            // CSP hygiene: no inline scripts, no JSON-LD, no external hosts.
            $this->assertCount(0, $this->xpath('//script[not(@src)]', $dom), 'Blog pages must not contain inline scripts (CSP).');
            $this->assertStringNotContainsString('application/ld+json', $response->body(), 'JSON-LD is not allowed (CSP has no inline scripts); use microdata.');
            foreach (['gstatic.com', 'fonts.googleapis.com', 'cdn.', 'google-analytics.com'] as $externalHost) {
                $this->assertStringNotContainsString($externalHost, $response->body(), 'Blog pages must not load external assets (' . $externalHost . ').');
            }
        }
    }

    public function testEveryBlogPostUsesTheSharedArticleLayout(): void
    {
        foreach ($this->posts() as $post) {
            $slug = (string) $post['slug'];
            $response = $this->get('/blog/' . $slug);
            $this->assertStatus(200, $response, $slug . ' must render.');
            $dom = $this->dom($response);

            // Same long-form shell as the legal pages: hero, sticky sidebar, panel.
            $this->assertCount(1, $this->xpath('//*[contains(@class,"doc-layout")]', $dom), $slug . ' must use the shared article layout.');
            $this->assertCount(1, $this->xpath('//header[contains(@class,"doc-hero")]', $dom), $slug . ' must render the shared article hero.');
            $this->assertCount(1, $this->xpath('//*[contains(@class,"doc-panel")]', $dom), $slug . ' must render the shared reading panel.');
            $this->assertCount(1, $this->xpath('//aside[contains(@class,"doc-side")]', $dom), $slug . ' must render the sticky contents sidebar.');

            // Sidebar article actions: back to the index and the public pages.
            foreach (['/blog', '/about', '/help'] as $href) {
                $this->assertTrue(
                    $this->xpath('//aside[contains(@class,"doc-side")]//a[@href="' . $href . '"]', $dom)->length >= 1,
                    $slug . ' sidebar must link ' . $href . '.'
                );
            }

            // Every on-this-page link must point at a real anchor in the article.
            $tocLinks = $this->xpath('//nav[contains(@class,"doc-toc")]//a[starts-with(@href,"#")]', $dom);
            $this->assertTrue($tocLinks->length >= 3, $slug . ' must expose an on-this-page contents list.');

            foreach ($tocLinks as $tocLink) {
                if (!$tocLink instanceof DOMElement) {
                    continue;
                }

                $target = substr((string) $tocLink->getAttribute('href'), 1);
                $this->assertTrue($target !== '', $slug . ' contents links must not be empty anchors.');
                $this->assertCount(1, $this->xpath('//*[@id="' . $target . '"]', $dom), $slug . ' contents anchor #' . $target . ' must exist.');
            }
        }
    }

    public function testUnknownBlogSlugReturns404(): void
    {        $response = $this->get('/blog/this-post-does-not-exist');

        $this->assertStatus(404, $response, 'An unknown blog slug must be a 404.');
        $this->assertTextContains('Page not found', $response);
    }

    public function testProductionPagesGetAbsoluteCanonicalAndIndexRobots(): void
    {
        $posts = $this->posts();
        $slug = (string) $posts[0]['slug'];
        $originalEnv = (string) Config::get('app.env', 'production');
        Config::set('app.env', 'production');

        try {
            $response = $this->get('/blog/' . $slug);
            $this->assertStatus(200, $response);
            $dom = $this->dom($response);

            $this->assertSame(
                $this->baseUrl() . '/blog/' . $slug,
                (string) $this->xpath('//link[@rel="canonical"]/@href', $dom)->item(0)?->nodeValue,
                'Production canonicals must be absolute URLs.'
            );
            $this->assertSame(
                'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
                $this->metaContent($dom, 'name', 'robots'),
                'Production posts must be indexable with rich AI/search previews.'
            );
            $this->assertSame($this->baseUrl() . '/blog/' . $slug, $this->metaContent($dom, 'property', 'og:url'));
        } finally {
            Config::set('app.env', $originalEnv);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Sitemap + robots                                                   */
    /* ------------------------------------------------------------------ */

    public function testSitemapListsAllPublicPagesAndPostsWithLastmod(): void
    {
        $response = $this->get('/sitemap.xml');
        $this->assertStatus(200, $response);
        $this->assertSame('application/xml; charset=utf-8', (string) $response->header('Content-Type'));

        $xml = $response->body();
        $this->assertStringStartsWith('<?xml', $xml, 'The sitemap must be an XML document.');

        $dom = new DOMDocument();

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
        }

        $this->assertTrue($loaded === true, 'The sitemap XML must be well-formed.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sm', self::SITEMAP_NS);
        $base = $this->baseUrl();

        $locations = [];

        foreach ($xpath->query('//sm:url/sm:loc') ?: [] as $node) {
            $locations[] = trim((string) $node->textContent);
        }

        $expected = [
            $base . '/',
            $base . '/about',
            $base . '/blog',
            $base . '/help',
            $base . '/privacy',
            $base . '/terms',
            $base . '/data-policy',
            $base . '/privacy/choices',
        ];

        foreach ($this->posts() as $post) {
            $expected[] = $base . '/blog/' . (string) $post['slug'];
        }

        $this->assertSame($expected, $locations, 'The sitemap must list the public pages then every post URL.');

        $lastmods = [];

        foreach ($xpath->query('//sm:url/sm:lastmod') ?: [] as $node) {
            $lastmods[] = trim((string) $node->textContent);
        }

        $this->assertCount(count($expected), $lastmods, 'Every sitemap URL must carry a <lastmod>.');

        foreach ($lastmods as $lastmod) {
            $this->assertMatches('/^\d{4}-\d{2}-\d{2}$/', $lastmod, 'Sitemap <lastmod> values must be Y-m-d.');
        }

        // Public crawl documents may be cached (the only no-store exception); no private/no-store.
        $cache = strtolower((string) $response->header('Cache-Control'));
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=3600', $cache);
        $this->assertStringNotContainsString('no-store', $cache);
        $this->assertStringNotContainsString('private', $cache);
    }

    public function testRobotsTxtHasProductionSitemapAndNonProductionDenyAll(): void
    {
        // Non-production (test environment): deny-all, no sitemap pointer.
        $nonProduction = $this->get('/robots.txt');
        $this->assertStatus(200, $nonProduction);
        $this->assertSame('text/plain; charset=utf-8', (string) $nonProduction->header('Content-Type'));
        $this->assertStringContainsString('User-agent: *', $nonProduction->body());
        $this->assertStringContainsString("Disallow: /\n", $nonProduction->body());
        $this->assertStringNotContainsString('Sitemap:', $nonProduction->body());

        $cache = strtolower((string) $nonProduction->header('Cache-Control'));
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=3600', $cache);
        $this->assertStringNotContainsString('no-store', $cache);
        $this->assertStringNotContainsString('private', $cache);

        // Production branch: allow public pages, disallow private surfaces, point at the sitemap.
        $originalEnv = (string) Config::get('app.env', 'production');
        Config::set('app.env', 'production');

        try {
            $production = $this->get('/robots.txt');
            $this->assertStatus(200, $production);
            $body = $production->body();

            $this->assertStringContainsString('Allow: /', $body);
            $this->assertStringContainsString('Sitemap: ' . $this->baseUrl() . '/sitemap.xml', $body);
            $this->assertStringNotContainsString("User-agent: *\nDisallow: /\n", $body);

            foreach (['/app', '/admin', '/platform', '/gate', '/api', '/notifications', '/login/'] as $path) {
                $this->assertStringContainsString('Disallow: ' . $path, $body, 'Production robots.txt must disallow ' . $path . '.');
            }
        } finally {
            Config::set('app.env', $originalEnv);
        }
    }

    public function testCacheHeadersPreserveNoStoreOutsideSeoDocuments(): void
    {
        foreach (['/sitemap.xml', '/robots.txt', '/llms.txt', '/llms-full.txt', '/feed.xml'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response);
            $cache = strtolower((string) $response->header('Cache-Control'));
            $this->assertStringContainsString('public', $cache, $path . ' must be publicly cacheable.');
            $this->assertStringNotContainsString('private', $cache, $path . ' must not be private.');
            $this->assertStringNotContainsString('no-store', $cache, $path . ' must not be no-store.');
        }

        // A normal public guest page stays strictly no-store (no regression).
        $about = $this->get('/about');
        $this->assertSame('no-store, private, max-age=0', (string) $about->header('Cache-Control'));
        $this->assertSame('no-cache', (string) $about->header('Pragma'));

        // An authenticated page stays no-store too.
        $this->loginAs(self::JANE);
        $app = $this->get('/app');
        $this->assertStatus(200, $app);
        $this->assertSame('no-store, private, max-age=0', (string) $app->header('Cache-Control'));
    }

    public function testEveryPostShipsALocalCoverImageWithAttribution(): void
    {
        // Owner request: a cover image for each blog article, sourced from a
        // public repository (Wikimedia Commons) but served locally.
        $index = $this->get('/blog');
        $this->assertStatus(200, $index);
        $this->assertCount(
            6,
            $this->xpath('//img[contains(@class,"blog-card__image")]', $this->dom($index)),
            'The blog index must list one local cover image per post.'
        );

        foreach (BlogContent::posts() as $post) {
            $slug = (string) $post['slug'];
            $response = $this->get('/blog/' . rawurlencode($slug));
            $this->assertStatus(200, $response, $slug . ' must render.');

            $dom = $this->dom($response);
            $img = $this->xpath('//img[contains(@class,"blog-post__image")]', $dom)->item(0);
            $this->assertNotNull($img, $slug . ' must render its cover image.');
            $src = (string) $img->getAttribute('src');
            $this->assertStringContainsString('/assets/img/blog/' . $slug . '.webp', $src, $slug . ' must use its local blog cover image.');
            $this->assertTrue(!str_starts_with($src, 'http'), $slug . ' cover image must be served locally (no hotlinking).');
            $this->assertTrue(trim((string) $img->getAttribute('alt')) !== '', $slug . ' cover image needs descriptive alt text.');
            $this->assertTrue(trim((string) $img->getAttribute('width')) !== '', $slug . ' cover image needs width/height attributes.');
            $this->assertTrue(is_file(BASE_PATH . '/public/assets/img/blog/' . $slug . '.webp'), $slug . ' cover image file must exist locally.');

            $ogImage = $this->xpath('//meta[@property="og:image"]', $dom)->item(0);
            $this->assertNotNull($ogImage, $slug . ' must expose og:image.');
            $this->assertStringContainsString('/assets/img/blog/' . $slug . '.webp', (string) $ogImage->getAttribute('content'), $slug . ' og:image must point at its cover image.');

            $this->assertTextContains('Wikimedia Commons', $response, 'Cover image credit must be shown (open licence attribution).');
        }
    }
}
