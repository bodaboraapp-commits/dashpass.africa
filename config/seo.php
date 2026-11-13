<?php

declare(strict_types=1);

/*
 * Search-engine + AI-crawler configuration.
 *
 * The verification values are public ownership tokens that Google Search
 * Console / Bing Webmaster Tools give you; they are rendered as meta tags in
 * the shared <head>. They are not secrets, but they are deployment-specific, so
 * they live in .env and default to empty (no tag rendered).
 */

return [
    // Google Search Console "HTML tag" verification content.
    'google_site_verification' => (string) env('SEO_GOOGLE_SITE_VERIFICATION', ''),
    // Bing Webmaster Tools verification content (meta name="msvalidate.01").
    'bing_site_verification' => (string) env('SEO_BING_SITE_VERIFICATION', ''),
];
