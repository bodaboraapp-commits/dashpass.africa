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
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('icons/favicon.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
