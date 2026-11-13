<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * AI-agent crawl guides: `/llms.txt` (llmstxt.org style index) and
 * `/llms-full.txt` (the full plain-text blog corpus for LLM ingestion).
 *
 * Both are plain text/markdown (no HTML) and publicly cacheable public SEO
 * documents (see App\Middleware\SecurityHeaders). Content comes from
 * BlogContent via BlogController so the guide and the site never disagree.
 */
final class LlmsController
{
    private const SITE_FALLBACK = 'https://dashpass.africa';

    public function index(Request $request, array $params, Context $context): Response
    {
        $origin = $this->origin();
        $supportEmail = (string) Config::get('app.support_email', 'hello@dashpass.africa');

        $lines = [
            '# DashPASS',
            '',
            '> DashPASS is the Pass & Pay management system for estates. It automates visitor',
            '> access with QR and PIN passes, collects estate payments from a phone, and keeps',
            '> an auditable record of every arrival and payment.',
            '',
            'DashPASS is built for gated estates, apartment blocks and mixed-use properties in',
            'Kenya and East Africa. Residents pre-register visitors and pay bills from their',
            'phones; guards verify single-use passes at the gate; estate administrators manage',
            'households, notices, parking and billing from one console.',
            '',
            '## Key pages',
            '',
            '- [Home](' . $origin . '/): what DashPASS does, with the six core value props.',
            '- [About](' . $origin . '/about): why estates choose DashPASS, who it serves and how it works.',
            '- [Blog](' . $origin . '/blog): practical guidance on estate access, operations and payments.',
            '- [Help](' . $origin . '/help): frequently asked questions and the support contact form.',
            '- [Sign in](' . $origin . '/login): resident, guard and administrator sign-in.',
            '',
            '## Blog posts',
            '',
        ];

        foreach (BlogController::postsWithImages() as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');
            $description = (string) ($post['description'] ?? '');

            if ($slug === '' || $title === '') {
                continue;
            }

            $lines[] = '- [' . $title . '](' . $origin . '/blog/' . rawurlencode($slug) . '): ' . $description;
        }

        $lines = array_merge($lines, [
            '',
            '## Support',
            '',
            '- Email: ' . $supportEmail,
            '- Contact form: ' . $origin . '/help#contact',
            '',
            '## Images and licence',
            '',
            'Blog cover images are openly licensed photographs (CC0, CC BY or CC BY-SA) from',
            'Wikimedia Commons. Each article shows its per-image credit and licence under the',
            'cover image. The images are served locally from this site and are not third-party',
            'hotlinks.',
            '',
        ]);

        return $this->textResponse(implode("\n", $lines));
    }

    public function full(Request $request, array $params, Context $context): Response
    {
        $origin = $this->origin();

        $lines = [
            '# DashPASS blog: full text',
            '',
            'Complete plain-text corpus of every published DashPASS blog post, provided for',
            'large language model and AI search ingestion. Source: ' . $origin . '/blog',
            '',
        ];

        foreach (BlogController::postsWithImages() as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');

            if ($slug === '' || $title === '') {
                continue;
            }

            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## ' . $title;
            $lines[] = '';
            $lines[] = '- URL: ' . $origin . '/blog/' . rawurlencode($slug);
            $lines[] = '- Published: ' . (string) ($post['published_at'] ?? '');
            $lines[] = '- Updated: ' . (string) ($post['updated_at'] ?? '');
            $lines[] = '- Author: ' . (string) ($post['author'] ?? 'DashPASS Team');
            $lines[] = '- Category: ' . (string) ($post['category'] ?? '');
            $lines[] = '- Summary: ' . (string) ($post['description'] ?? '');
            $lines[] = '';

            foreach ((array) ($post['sections'] ?? []) as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $heading = trim((string) ($section['heading'] ?? ''));

                if ($heading !== '') {
                    $lines[] = '### ' . $heading;
                    $lines[] = '';
                }

                foreach ((array) ($section['paragraphs'] ?? []) as $paragraph) {
                    if (is_string($paragraph) && trim($paragraph) !== '') {
                        $lines[] = trim($paragraph);
                        $lines[] = '';
                    }
                }

                foreach ((array) ($section['bullets'] ?? []) as $bullet) {
                    if (is_string($bullet) && trim($bullet) !== '') {
                        $lines[] = '- ' . trim($bullet);
                    }
                }

                if ((array) ($section['bullets'] ?? []) !== []) {
                    $lines[] = '';
                }
            }

            $faq = is_array($post['faq'] ?? null) ? $post['faq'] : [];

            if ($faq !== []) {
                $lines[] = '### Frequently asked questions';
                $lines[] = '';

                foreach ($faq as $pair) {
                    if (!is_array($pair)) {
                        continue;
                    }

                    $question = trim((string) ($pair['q'] ?? ''));
                    $answer = trim((string) ($pair['a'] ?? ''));

                    if ($question === '' || $answer === '') {
                        continue;
                    }

                    $lines[] = 'Q: ' . $question;
                    $lines[] = '';
                    $lines[] = 'A: ' . $answer;
                    $lines[] = '';
                }
            }
        }

        return $this->textResponse(implode("\n", $lines));
    }

    private function textResponse(string $body): Response
    {
        return (new Response(rtrim($body) . "\n", 200))
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function origin(): string
    {
        $origin = rtrim((string) Config::get('app.url', ''), '/');

        return $origin !== '' ? $origin : self::SITE_FALLBACK;
    }
}
