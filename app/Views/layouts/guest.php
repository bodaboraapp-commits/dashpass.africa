<?php

declare(strict_types=1);

/**
 * Guest layout: welcome, login, no-estate, error-adjacent public pages.
 *
 * Data (all optional, contract §8.2):
 *   $title, $csrfToken, $appVersion, $isDev, $flashError, $flashSuccess
 *   $content (rendered inner view)
 */

$title = isset($title) && is_string($title) ? $title : (string) config('app.name', 'MtaaPass');
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);
$isDev = (bool) ($isDev ?? false);
$flashError = $flashError ?? null;
$flashSuccess = $flashSuccess ?? null;
$content = $content ?? '';
$appName = (string) config('app.name', 'MtaaPass');
$hasFlash = (is_string($flashError) && trim($flashError) !== '')
    || (is_string($flashSuccess) && trim($flashSuccess) !== '');

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<!DOCTYPE html>
<html lang="en" data-theme-default="dark">
<head>
<?= \App\Core\View::capture('partials/head', [
    'title' => $title,
    'csrfToken' => $csrfToken,
    'appVersion' => $appVersion,
    'isDev' => $isDev,
    'appResident' => true,
]) ?>
</head>
<body class="guest-body<?= !empty($landingMode) ? ' guest-body--landing' : '' ?>">
  <a class="skip-link" href="#main">Skip to content</a>

  <div class="net-banner" data-net-banner role="status" hidden>
    <?= $icon('wifi-off', 'ico ico--sm') ?>
    <span data-net-text>You are offline. Fresh estate data and sign-in are unavailable.</span>
  </div>

  <div class="guest-shell">
    <header class="guest-header">
      <a class="brand" href="<?= e('/') ?>">
        <?= \App\Core\View::capture('partials/brand-mark') ?>
        <span class="brand__text">
          <?= e($appName) ?>
          <small>Skip the Queue</small>
        </span>
      </a>
      <div class="cluster">
        <?php if (!empty($guestNav)): ?>
          <nav class="guest-nav" aria-label="Guest navigation">
            <a class="guest-nav__link" href="<?= e('/login') ?>">Check in</a>
            <a class="guest-nav__link" href="mailto:hello@dashpass.africa">Need help?</a>
            <a class="btn btn--primary btn--sm guest-nav__cta" href="<?= e('/login') ?>">Login</a>
          </nav>
        <?php endif; ?>
        <?php if ($isDev): ?>
          <span class="env-badge" title="Development environment — never enabled in production">Dev</span>
        <?php endif; ?>
        <?= \App\Core\View::capture('partials/theme-toggle') ?>
      </div>
    </header>

    <main id="main" class="guest-main">
      <?php if ($hasFlash): ?>
        <?php if (is_string($flashError) && trim($flashError) !== ''): ?>
          <div class="alert alert--error" role="alert">
            <?= $icon('alert') ?>
            <div class="alert__body">
              <p class="alert__title">Something went wrong</p>
              <p><?= e($flashError) ?></p>
            </div>
          </div>
        <?php endif; ?>
        <?php if (is_string($flashSuccess) && trim($flashSuccess) !== ''): ?>
          <div class="alert alert--success" role="status">
            <?= $icon('check') ?>
            <div class="alert__body">
              <p><?= e($flashSuccess) ?></p>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?= $content ?>
    </main>

    <?php if (!empty($landingMode)): ?>
      <footer class="guest-footer guest-footer--landing">
        <p>Copyright © 2026 DashPASS · <a href="mailto:hello@dashpass.africa">hello@dashpass.africa</a></p>
      </footer>
    <?php else: ?>
    <footer class="guest-footer">
      <p><?= e($appName) ?> <?= e($appVersion) ?> · Secure, Fast &amp; Accountable.</p>
      <p class="guest-footer__contact"><a href="mailto:hello@dashpass.africa">hello@dashpass.africa</a></p>
      <p class="guest-footer__meta">
        <span>Need help? <a href="mailto:hello@dashpass.africa">hello@dashpass.africa</a></span>
        <?php if ($isDev): ?>
          <span class="env-badge">Development</span>
        <?php endif; ?>
      </p>
    </footer>
    <?php endif; ?>
  </div>

  <div class="toast-region toast-region--guest" id="toast-region" aria-live="polite" aria-atomic="false"></div>

  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
