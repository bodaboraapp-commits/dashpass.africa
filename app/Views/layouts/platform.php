<?php

declare(strict_types=1);

/**
 * Platform super-admin portal layout (platform-admin contract §3).
 *
 * Separate shell from the resident PWA and the estate admin console: dark navy
 * tokens from app.css plus platform.css layout styles, config-driven product
 * name with a "Platform" badge, operator identity/sign-out, back-to-app only
 * when the operator also has estate access, permission-filtered navigation
 * (Overview / Estates / Plans / Subscriptions — the audit viewer lives on the
 * dashboard) and flash messages.
 *
 * All data is optional so partial integration renders without notices:
 * $title, $user, $permissions, $csrfToken, $appVersion, $isDev, $activeNav,
 * $flash_success / $flash_error (legacy $flashSuccess/$flashError),
 * $memberships/$hasEstateAccess, $content.
 */

require_once __DIR__ . '/../admin/_helpers.php';

$title = isset($title) && is_string($title) && trim($title) !== '' ? trim($title) : 'Platform';
$user = isset($user) && is_array($user) ? $user : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);
$isDev = (bool) ($isDev ?? false);
$content = $content ?? '';
$appName = (string) config('app.name', 'MtaaPass');

$flashSuccess = $flash_success ?? $flashSuccess ?? null;
$flashError = $flash_error ?? $flashError ?? null;

$slugStart = static fn (string $slug, string $prefix): bool => str_starts_with($slug, $prefix);
$can = static function (string $permission) use ($permissions): bool {
    return in_array($permission, array_map('strval', $permissions), true);
};
$canAnyPlatform = static function () use ($permissions, $slugStart): bool {
    foreach ($permissions as $permission) {
        if (!is_string($permission)) {
            continue;
        }
        if ($slugStart($permission, 'platform.') || $slugStart($permission, 'billing.')) {
            return true;
        }
    }

    return false;
};

$fullName = trim((string) ($user['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Platform operator';
}
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: [], static fn (string $part): bool => $part !== ''));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'PL';
}
$operatorPhone = trim((string) ($user['phone'] ?? ''));
if ($operatorPhone !== '' && \App\Core\Phone::isValid($operatorPhone)) {
    $operatorPhone = \App\Core\Phone::mask($operatorPhone);
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$navItems = [
    [
        'key' => 'overview',
        'label' => 'Overview',
        'icon' => 'grid',
        'href' => '/platform',
        'visible' => $can('platform.audit.view') || $can('platform.estate.manage'),
    ],
    ['key' => 'estates', 'label' => 'Estates', 'icon' => 'building', 'href' => '/platform/estates', 'visible' => $can('platform.estate.manage')],
    ['key' => 'plans', 'label' => 'Plans', 'icon' => 'wallet', 'href' => '/platform/plans', 'visible' => $can('platform.plan.manage')],
    ['key' => 'subscriptions', 'label' => 'Subscriptions', 'icon' => 'activity', 'href' => '/platform/subscriptions', 'visible' => $can('platform.subscription.manage')],
    ['key' => 'billing', 'label' => 'Billing', 'icon' => 'ticket', 'href' => '/platform/billing', 'visible' => $can('billing.invoice.view')],
];
$visibleNavItems = array_values(array_filter($navItems, static fn (array $item): bool => (bool) $item['visible']));
$navKeys = array_map(static fn (array $item): string => (string) $item['key'], $visibleNavItems);
if ($navKeys === []) {
    $navKeys = ['overview'];
}
if (isset($activeNav) && $activeNav === 'dashboard') {
    $activeNav = 'overview';
}
$activeNav = isset($activeNav) && is_string($activeNav) && in_array($activeNav, $navKeys, true)
    ? $activeNav
    : $navKeys[0];

$memberships = isset($memberships) && is_array($memberships) ? $memberships : [];
$hasEstateAccess = isset($hasEstateAccess) ? (bool) $hasEstateAccess : ($memberships !== []);

$hasFlash = (is_string($flashError) && trim($flashError) !== '')
    || (is_string($flashSuccess) && trim($flashSuccess) !== '');
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
<link rel="stylesheet" href="/assets/css/platform.css?v=<?= e($assetVersion) ?>">
</head>
<body class="app-body platform-body">
  <a class="skip-link" href="#main">Skip to content</a>

  <div class="net-banner" data-net-banner role="status" hidden>
    <?= $icon('wifi-off', 'ico ico--sm') ?>
    <span data-net-text>You are offline. Platform changes will not reach the server.</span>
  </div>

  <header class="platform-topbar">
    <a class="platform-topbar__brand" href="/platform">
      <?= \App\Core\View::capture('partials/brand-mark') ?>
      <span class="platform-topbar__brand-text">
        <span class="platform-topbar__name"><?= e($appName) ?></span>
        <span class="platform-topbar__console">Platform</span>
      </span>
    </a>

    <span class="platform-topbar__badge chip chip--on-dark chip--sm">
      <?= $icon('shield', 'ico ico--xs') ?> Operator portal
    </span>

    <div class="platform-topbar__actions">
      <?= \App\Core\View::capture('partials/theme-toggle') ?>
      <span class="platform-topbar__user" title="<?= e($fullName) ?>">
        <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials) ?></span>
        <span class="platform-topbar__user-body">
          <span class="platform-topbar__user-name"><?= e($fullName) ?></span>
          <?php if ($operatorPhone !== ''): ?>
            <span class="platform-topbar__user-phone"><?= e($operatorPhone) ?></span>
          <?php endif; ?>
        </span>
      </span>
      <?php if ($hasEstateAccess): ?>
        <a class="platform-topbar__link" href="/app"><?= $icon('home', 'ico ico--sm') ?> <span>Back to app</span></a>
      <?php endif; ?>
      <form method="post" action="/logout" class="platform-topbar__logout" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm" aria-label="Sign out">
          <?= $icon('logout', 'ico ico--sm') ?>
          <span>Sign out</span>
        </button>
      </form>
    </div>
  </header>

  <div class="platform-shell">
    <nav class="platform-nav" aria-label="Platform sections">
      <?php foreach ($visibleNavItems as $item): ?>
        <a class="platform-nav__item<?= $activeNav === $item['key'] ? ' platform-nav__item--active' : '' ?>"
           href="<?= e((string) $item['href']) ?>"
           <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
          <?= $icon((string) $item['icon'], 'ico') ?>
          <span class="platform-nav__label"><?= e((string) $item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <main id="main" class="platform-main">
      <div class="platform-page">
        <?php if (!$canAnyPlatform()): ?>
          <div class="alert alert--error" role="alert">
            <?= $icon('lock') ?>
            <div class="alert__body">
              <p class="alert__title">Platform access required</p>
              <p>This portal is limited to platform operators.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($hasFlash): ?>
          <?php if (is_string($flashError) && trim($flashError) !== ''): ?>
            <div class="alert alert--error" role="alert">
              <?= $icon('alert') ?>
              <div class="alert__body">
                <p class="alert__title">Action not completed</p>
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
      </div>
    </main>
  </div>

  <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>

  <script src="/assets/js/app.js?v=<?= e($assetVersion) ?>" defer></script>
</body>
</html>
