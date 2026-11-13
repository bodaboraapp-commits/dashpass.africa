<?php

declare(strict_types=1);

/**
 * Shared <head> contents. Included by every layout and by the standalone error
 * page. Values are defensive: views may be rendered with partial data during
 * integration and must never emit notices.
 *
 * Expected (all optional): $title, $csrfToken, $appVersion, $isDev,
 * $description, $appResident (resident navy shell)
 *
 * Asset URLs are deliberately root-relative (no http(s) origins) so the same
 * markup works on any host/port — addendum §6 and the markup safety tests.
 */

$pageName = isset($title) && is_string($title) && trim($title) !== '' ? trim($title) : (string) config('app.name', 'MtaaPass');
$appName = (string) config('app.name', 'MtaaPass');
$documentTitle = $pageName === $appName ? $appName : $pageName . ' · ' . $appName;
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$metaDescription = isset($description) && is_string($description) && $description !== ''
    ? $description
    : $appName . ' is the calm, secure front door to your estate: visitor passes, parking and gate access in one place.';
$version = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($version);
$appResident = (bool) ($appResident ?? false);
// T85: the installed PWA tints the Android/Chrome status bar from these metas.
// Both variants use the app navy so the system bar matches the real body/top-bar
// background (#070B16, same as --bg in the navy design system and the manifest).
$themeColor = '#070B16';
$themeColorDark = '#070B16';

// --- SEO / social metadata (forwarded by the guest layout) ------------------
// Optional keys: $canonical, $robots, $ogType, $ogImage, $keywords, $siteName,
// $publishedAt, $modifiedAt, $author. Non-production hosts are always noindex so
// dev/staging/ngrok pages can never be indexed by search engines.
$isProductionEnv = (string) config('app.env', 'production') === 'production';
$robotsValue = isset($robots) && is_string($robots) && trim($robots) !== ''
    ? trim($robots)
    : 'index, follow';

if (!$isProductionEnv) {
    $robotsValue = 'noindex, nofollow';
}

$canonicalValue = isset($canonical) ? trim((string) $canonical) : '';
// Canonical links are absolute in production; non-production pages are noindex
// anyway and render them root-relative so the shared markup-safety scan (which
// rejects absolute hrefs) stays strict for app assets.
$canonicalHrefValue = $canonicalValue;

if ($canonicalHrefValue !== '' && !$isProductionEnv) {
    $canonicalPath = parse_url($canonicalHrefValue, PHP_URL_PATH);

    if (is_string($canonicalPath) && $canonicalPath !== '') {
        $canonicalHrefValue = $canonicalPath;
    }
}

$ogTypeValue = isset($ogType) && in_array($ogType, ['website', 'article'], true) ? (string) $ogType : 'website';
$ogImageValue = isset($ogImage) ? trim((string) $ogImage) : '';
$siteNameValue = isset($siteName) && is_string($siteName) && trim($siteName) !== ''
    ? trim($siteName)
    : $appName;
$keywordsValue = '';

if (isset($keywords)) {
    if (is_array($keywords)) {
        $keywordParts = [];

        foreach ($keywords as $keyword) {
            if (is_scalar($keyword) && trim((string) $keyword) !== '') {
                $keywordParts[] = trim((string) $keyword);
            }
        }

        $keywordsValue = implode(', ', $keywordParts);
    } elseif (is_string($keywords)) {
        $keywordsValue = trim($keywords);
    }
}

$publishedAtValue = isset($publishedAt) ? trim((string) $publishedAt) : '';
$modifiedAtValue = isset($modifiedAt) ? trim((string) $modifiedAt) : '';
$authorValue = isset($author) ? trim((string) $author) : '';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<title><?= e($documentTitle) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<meta name="robots" content="<?= e($robotsValue) ?>">
<?php if ($keywordsValue !== ''): ?>
<meta name="keywords" content="<?= e($keywordsValue) ?>">
<?php endif; ?>
<?php if ($canonicalValue !== ''): ?>
<link rel="canonical" href="<?= e($canonicalHrefValue) ?>">
<meta property="og:title" content="<?= e($documentTitle) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<meta property="og:type" content="<?= e($ogTypeValue) ?>">
<meta property="og:url" content="<?= e($canonicalValue) ?>">
<?php if ($ogImageValue !== ''): ?>
<meta property="og:image" content="<?= e($ogImageValue) ?>">
<?php endif; ?>
<meta property="og:site_name" content="<?= e($siteNameValue) ?>">
<?php if ($ogTypeValue === 'article'): ?>
<?php if ($publishedAtValue !== ''): ?>
<meta property="article:published_time" content="<?= e($publishedAtValue) ?>">
<?php endif; ?>
<?php if ($modifiedAtValue !== ''): ?>
<meta property="article:modified_time" content="<?= e($modifiedAtValue) ?>">
<?php endif; ?>
<?php if ($authorValue !== ''): ?>
<meta property="article:author" content="<?= e($authorValue) ?>">
<?php endif; ?>
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($documentTitle) ?>">
<meta name="twitter:description" content="<?= e($metaDescription) ?>">
<?php if ($ogImageValue !== ''): ?>
<meta name="twitter:image" content="<?= e($ogImageValue) ?>">
<?php endif; ?>
<?php endif; ?>
<meta name="application-name" content="<?= e($appName) ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<meta name="theme-color" content="<?= e($themeColorDark) ?>" media="(prefers-color-scheme: dark)" data-theme-color>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('icons/favicon.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
