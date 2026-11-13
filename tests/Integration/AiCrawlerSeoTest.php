<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Modules\Marketing\BlogController;
use DOMDocument;
use DOMXPath;

/**
 * AI/search crawler layer: llms.txt, llms-full.txt, RSS feed, AI-bot robots
 * rules, sitemap image entries and the RSS/rich-preview meta on guest pages.
 *
 * All documents are public, cacheable, XML/text (no HTML) and escaped; the site
 * remains CSP-safe (no inline JS/JSON-LD).
 */
final class AiCrawlerSeoTest extends VisitorModuleTestCase
{
    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posts(): array
    {
        $posts = BlogController::postsWithImages();
        $this->assertCount(6, $posts, 'Six published posts with cover images are expected.');

        return $posts;
    }

    private function assertPubliclyCacheable(\App\Core\Response $response, string $path): void
    {
        $cache = strtolower((string) $response->header('Cache-Control'));
        $this->assertStringContainsString('public', $cache, $path . ' must be publicly cacheable.');
        $this->assertStringContainsString('max-age=3600', $cache, $path . ' must cache for an hour.');
        $this->assertStringNotContainsString('no-store', $cache, $path . ' must not be no-store.');
        $this->assertStringNotContainsString('private', $cache, $path . ' must not be private.');
    }

    /* ------------------------------------------------------------------ */
    /* llms.txt / llms-full.txt                                           */
    /* ------------------------------------------------------------------ */

    public function testLlmsTxtIsAnAiAgentGuideToEveryPublicPage(): void
    {
        $response = $this->get('/llms.txt');
        $this->assertStatus(200, $response);
        $this->assertSame('text/markdown; charset=utf-8', (string) $response->header('Content-Type'));
        $this->assertPubliclyCacheable($response, '/llms.txt');

        $body = $response->body();
        $this->assertStringStartsWith('# DashPASS', $body, 'llms.txt must start with the product title.');
        $this->assertStringContainsString('> DashPASS is the Pass & Pay management system for estates.', $body);
        $this->assertStringContainsString('## Key pages', $body);
        $this->assertStringContainsString('## Blog posts', $body);
        $this->assertStringContainsString('## Support', $body);
        $this->assertStringContainsString('## Images and licence', $body);

        $base = $this->baseUrl();

        foreach (['/', '/about', '/blog', '/help'] as $path) {
            $this->assertStringContainsString('](' . $base . $path . ')', $body, 'llms.txt must link ' . $path . '.');
        }

        foreach ($this->posts() as $post) {
            $url = $base . '/blog/' . (string) $post['slug'];
            $this->assertStringContainsString('](' . $url . '):', $body, 'llms.txt must list ' . $url . ' with a summary.');
            $this->assertStringContainsString((string) $post['title'], $body);
        }

        $this->assertStringContainsString('hello@dashpass.africa', $body, 'llms.txt must give the support contact.');
        $this->assertMatches('/Wikimedia Commons|CC0|CC BY/i', $body, 'llms.txt must carry the blog image licence/attribution note.');

        // Every absolute URL points at our own origin (no external assets/hotlinks).
        preg_match_all('#https?://[^\s)\]"\']+#', $body, $urls);
        foreach (array_unique($urls[0]) as $url) {
            $this->assertStringStartsWith($base, $url, 'llms.txt must not reference external hosts: ' . $url);
        }
    }

    public function testLlmsFullTxtContainsTheWholeBlogCorpus(): void
    {
        $response = $this->get('/llms-full.txt');
        $this->assertStatus(200, $response);
        $this->assertSame('text/markdown; charset=utf-8', (string) $response->header('Content-Type'));
        $this->assertPubliclyCacheable($response, '/llms-full.txt');

        $body = $response->body();
        $this->assertStringContainsString('# DashPASS blog: full text', $body);
        $base = $this->baseUrl();

        foreach ($this->posts() as $post) {
            $slug = (string) $post['slug'];
            $this->assertStringContainsString('## ' . (string) $post['title'], $body, 'llms-full must include the title of ' . $slug . '.');
            $this->assertStringContainsString('- URL: ' . $base . '/blog/' . $slug, $body);
            $this->assertStringContainsString('- Published: ' . (string) $post['published_at'], $body);
            $this->assertStringContainsString('- Summary: ' . (string) $post['description'], $body);

            foreach ((array) $post['sections'] as $section) {
                $heading = trim((string) ($section['heading'] ?? ''));

                if ($heading !== '') {
                    $this->assertStringContainsString('### ' . $heading, $body, 'llms-full must include every section heading of ' . $slug . '.');
                }
            }

            foreach ((array) $post['faq'] as $pair) {
                $this->assertStringContainsString('Q: ' . (string) ($pair['q'] ?? ''), $body);
                $this->assertStringContainsString('A: ' . (string) ($pair['a'] ?? ''), $body);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* RSS feed                                                           */
    /* ------------------------------------------------------------------ */

    public function testFeedXmlIsValidRssWithTheSixPosts(): void
    {
        $response = $this->get('/feed.xml');
        $this->assertStatus(200, $response);
        $this->assertSame('application/rss+xml; charset=utf-8', (string) $response->header('Content-Type'));
        $this->assertPubliclyCacheable($response, '/feed.xml');

        $dom = new DOMDocument();

        try {
            $loaded = $dom->loadXML($response->body(), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
        }

        $this->assertTrue($loaded === true, 'The RSS feed must be well-formed XML.');

        $xpath = new DOMXPath($dom);
        $base = $this->baseUrl();

        $this->assertSame(1, $xpath->query('/rss/channel')->length, 'The feed must have one RSS channel.');
        $this->assertSame('DashPASS blog', trim((string) $xpath->query('/rss/channel/title')->item(0)?->textContent));
        $this->assertSame($base . '/blog', trim((string) $xpath->query('/rss/channel/link')->item(0)?->textContent));

        $items = $xpath->query('/rss/channel/item');
        $this->assertSame(6, $items->length, 'The feed must contain all six posts.');

        $expectedSlugs = array_map(static fn (array $post): string => (string) $post['slug'], $this->posts());

        for ($index = 0; $index < $items->length; $index++) {
            $item = $items->item($index);
            $this->assertNotNull($item);
            $slug = $expectedSlugs[$index];
            $link = $base . '/blog/' . $slug;

            $this->assertSame(
                $link,
                trim((string) $xpath->query('link', $item)->item(0)?->textContent),
                'RSS item ' . $index . ' must link to ' . $link . '.'
            );
            $this->assertSame($link, trim((string) $xpath->query('guid', $item)->item(0)?->textContent), 'RSS guid must be the permalink.');
            $this->assertTrue(trim((string) $xpath->query('title', $item)->item(0)?->textContent) !== '', 'Every RSS item needs a title.');
            $this->assertTrue(trim((string) $xpath->query('description', $item)->item(0)?->textContent) !== '', 'Every RSS item needs a description.');
            $this->assertTrue(trim((string) $xpath->query('dc:creator', $item)->item(0)?->textContent) !== '', 'Every RSS item needs an author.');

            $pubDate = trim((string) $xpath->query('pubDate', $item)->item(0)?->textContent);
            $parsed = \DateTimeImmutable::createFromFormat(DATE_RSS, $pubDate);
            $this->assertNotFalse($parsed, 'RSS pubDate must be RFC 2822: ' . $pubDate);
        }
    }

    /* ------------------------------------------------------------------ */
    /* robots.txt AI agents + sitemap images                              */
    /* ------------------------------------------------------------------ */

    public function testRobotsTxtExplicitlyWelcomesAiAndSearchCrawlers(): void
    {
        $originalEnv = (string) Config::get('app.env', 'production');
        Config::set('app.env', 'production');

        try {
            $response = $this->get('/robots.txt');
            $this->assertStatus(200, $response);
            $body = $response->body();

            foreach ([
                'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'anthropic-ai',
                'PerplexityBot', 'Googlebot', 'Google-Extended', 'Bingbot', 'CCBot', 'Applebot-Extended',
            ] as $agent) {
                $this->assertStringContainsString('User-agent: ' . $agent, $body, 'robots.txt must name ' . $agent . '.');
            }

            $this->assertTrue(
                substr_count($body, 'Allow: /') >= 11,
                'Each AI agent needs an explicit allow.'
            );
            $this->assertStringContainsString('Sitemap: ' . $this->baseUrl() . '/sitemap.xml', $body);
            $this->assertStringContainsString('/llms.txt', $body, 'robots.txt must reference the AI-agent guide.');

            foreach (['/app', '/admin', '/platform', '/gate', '/api', '/notifications', '/login/'] as $path) {
                $this->assertStringContainsString('Disallow: ' . $path, $body);
            }

            $this->assertPubliclyCacheable($response, '/robots.txt');
        } finally {
            Config::set('app.env', $originalEnv);
        }

        // Non-production remains deny-all (no AI allowlist).
        $dev = $this->get('/robots.txt');
        $this->assertStringContainsString("User-agent: *\nDisallow: /\n", $dev->body());
        $this->assertStringNotContainsString('GPTBot', $dev->body());
    }

    public function testSitemapHasImageEntriesForTheSixBlogCovers(): void
    {
        $response = $this->get('/sitemap.xml');
        $this->assertStatus(200, $response);

        $dom = new DOMDocument();

        try {
            $loaded = $dom->loadXML($response->body(), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
        }

        $this->assertTrue($loaded === true, 'The sitemap must stay well-formed XML.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');

        $imageLocs = [];

        foreach ($xpath->query('//sm:url/image:image/image:loc') ?: [] as $node) {
            $imageLocs[] = trim((string) $node->textContent);
        }

        $this->assertCount(6, $imageLocs, 'The sitemap must list one image per blog post.');

        $base = $this->baseUrl();

        foreach ($this->posts() as $post) {
            $slug = (string) $post['slug'];
            $this->assertContains(
                $base . '/assets/img/blog/' . $slug . '.webp',
                $imageLocs,
                'The sitemap must include the cover image for ' . $slug . '.'
            );
        }

        // Standard URL entries are preserved.
        $locs = [];

        foreach ($xpath->query('//sm:url/sm:loc') ?: [] as $node) {
            $locs[] = trim((string) $node->textContent);
        }

        $this->assertContains($base . '/blog', $locs);
        $this->assertContains($base . '/privacy', $locs);
        $this->assertContains($base . '/terms', $locs);
        $this->assertCount(14, $locs, 'Fourteen URL entries are expected (eight pages + six posts).');
    }

    /* ------------------------------------------------------------------ */
    /* Guest head: RSS alternate + rich robots meta                       */
    /* ------------------------------------------------------------------ */

    public function testGuestPagesAdvertiseTheRssFeedAndKeepRichRobotsMeta(): void
    {
        // Non-production: root-relative feed link + noindex robots meta.
        $dev = $this->get('/blog');
        $this->assertStatus(200, $dev);
        $devDom = $this->dom($dev);

        $alternates = $this->xpath('//link[@rel="alternate"][@type="application/rss+xml"]', $devDom);
        $this->assertCount(1, $alternates, 'Guest pages must advertise the RSS feed.');
        $alternate = $alternates->item(0);
        $this->assertNotNull($alternate);
        $this->assertSame('/feed.xml', (string) $alternate->getAttribute('href'));
        $this->assertSame('DashPASS blog', (string) $alternate->getAttribute('title'));

        $devRobots = $this->xpath('//meta[@name="robots"]', $devDom)->item(0);
        $this->assertNotNull($devRobots);
        $this->assertSame('noindex, nofollow', (string) $devRobots->getAttribute('content'));

        // Production: absolute feed URL + rich preview robots meta.
        $originalEnv = (string) Config::get('app.env', 'production');
        Config::set('app.env', 'production');

        try {
            $production = $this->get('/blog');
            $this->assertStatus(200, $production);
            $dom = $this->dom($production);

            $alternate = $this->xpath('//link[@rel="alternate"][@type="application/rss+xml"]', $dom)->item(0);
            $this->assertNotNull($alternate);
            $this->assertSame($this->baseUrl() . '/feed.xml', (string) $alternate->getAttribute('href'));

            $robots = $this->xpath('//meta[@name="robots"]', $dom)->item(0);
            $this->assertNotNull($robots);

            foreach (['index', 'follow', 'max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1'] as $directive) {
                $this->assertStringContainsString($directive, (string) $robots->getAttribute('content'));
            }
        } finally {
            Config::set('app.env', $originalEnv);
        }
    }

    public function testNewCrawlerDocumentsAreCacheableButNormalPagesStayNoStore(): void
    {
        foreach (['/llms.txt', '/llms-full.txt', '/feed.xml'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response);
            $this->assertPubliclyCacheable($response, $path);
        }

        $about = $this->get('/about');
        $this->assertSame('no-store, private, max-age=0', (string) $about->header('Cache-Control'));

        $this->loginAs(self::JANE);
        $app = $this->get('/app');
        $this->assertStatus(200, $app);
        $this->assertSame('no-store, private, max-age=0', (string) $app->header('Cache-Control'));
    }
}
