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
$themeColor = $appResident ? '#070B16' : '#123D35';
$themeColorDark = $appResident ? '#070B16' : '#0B1613';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<title><?= e($documentTitle) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<meta name="application-name" content="<?= e($appName) ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<meta name="theme-color" content="<?= e($themeColorDark) ?>" media="(prefers-color-scheme: dark)" data-theme-color>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon.png?v=<?= e($assetVersion) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-touch-icon.png?v=<?= e($assetVersion) ?>">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e($assetVersion) ?>">
<script src="/assets/js/theme.js?v=<?= e($assetVersion) ?>"></script>
