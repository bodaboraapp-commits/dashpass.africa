<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * RSS 2.0 blog feed (`GET /feed.xml`).
 *
 * Channel plus the six newest posts (title, link, guid, pubDate, description,
 * author) built from BlogContent via BlogController. Publicly cacheable public
 * SEO document; XML-escaped, no external assets.
 */
final class FeedController
{
    private const SITE_FALLBACK = 'https://dashpass.africa';

    public function show(Request $request, array $params, Context $context): Response
    {
        $origin = $this->origin();
        $blogUrl = $origin . '/blog';
        $feedUrl = $origin . '/feed.xml';
        $posts = BlogController::postsWithImages();

        $latestDate = null;

        foreach ($posts as $post) {
            $date = trim((string) (($post['updated_at'] ?? '') !== '' ? $post['updated_at'] : ($post['published_at'] ?? '')));

            if ($date !== '' && ($latestDate === null || strcmp($date, $latestDate) > 0)) {
                $latestDate = $date;
            }
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">',
            '  <channel>',
            '    <title>DashPASS blog</title>',
            '    <link>' . $this->xml($blogUrl) . '</link>',
            '    <description>Practical guidance on estate access, visitor passes and payments from the DashPASS team.</description>',
            '    <language>en</language>',
            '    <atom:link href="' . $this->xml($feedUrl) . '" rel="self" type="application/rss+xml"/>',
        ];

        if ($latestDate !== null) {
            $lines[] = '    <lastBuildDate>' . $this->xml($this->rssDate($latestDate)) . '</lastBuildDate>';
        }

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');

            if ($slug === '' || $title === '') {
                continue;
            }

            $link = $blogUrl . '/' . rawurlencode($slug);
            $published = (string) ($post['published_at'] ?? '');

            $lines[] = '    <item>';
            $lines[] = '      <title>' . $this->xml($title) . '</title>';
            $lines[] = '      <link>' . $this->xml($link) . '</link>';
            $lines[] = '      <guid isPermaLink="true">' . $this->xml($link) . '</guid>';

            if ($published !== '') {
                $lines[] = '      <pubDate>' . $this->xml($this->rssDate($published)) . '</pubDate>';
            }

            $lines[] = '      <description>' . $this->xml((string) ($post['description'] ?? $post['excerpt'] ?? '')) . '</description>';
            $lines[] = '      <dc:creator>' . $this->xml((string) ($post['author'] ?? 'DashPASS Team')) . '</dc:creator>';
            $lines[] = '      <category>' . $this->xml((string) ($post['category'] ?? 'Estate management')) . '</category>';
            $lines[] = '    </item>';
        }

        $lines[] = '  </channel>';
        $lines[] = '</rss>';

        return (new Response(implode("\n", $lines) . "\n", 200))
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function rssDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return '';
        }

        return $parsed->format(DATE_RSS);
    }

    private function xml(string $value): string
    {
        return (string) htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function origin(): string
    {
        $origin = rtrim((string) Config::get('app.url', ''), '/');

        return $origin !== '' ? $origin : self::SITE_FALLBACK;
    }
}
