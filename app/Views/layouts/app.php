<?php

declare(strict_types=1);

/**
 * Resident application layout (addendum §2A/§2D, §5).
 *
 * Dark navy resident shell: sidebar ≥1024px, five-slot bottom navigation with a
 * raised centre QR button <1024px, shared access-pass sheet, flash alerts and
 * the progressive-enhancement script.
 *
 * Data (contract §8.2 + addendum §4, all optional so views survive partial
 * integration): $title, $user, $estate, $memberships, $activeNav, $csrfToken,
 * $appVersion, $isDev, $flashError, $flashSuccess, $permissions, $content
 */

$title = isset($title) && is_string($title) && trim($title) !== '' ? $title : (string) config('app.name', 'MtaaPass');
$user = isset($user) && is_array($user) ? $user : [];
$estate = isset($estate) && is_array($estate) ? $estate : null;
$memberships = isset($memberships) && is_array($memberships) ? $memberships : [];
$activeNav = isset($activeNav) && is_string($activeNav) ? $activeNav : 'home';
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);
$isDev = (bool) ($isDev ?? false);
$flashError = $flashError ?? null;
$flashSuccess = $flashSuccess ?? null;
$content = $content ?? '';
$appName = (string) config('app.name', 'MtaaPass');
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$nextPass = isset($next_pass) && is_array($next_pass) ? $next_pass : null;

$fullName = trim((string) ($user['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Resident';
}
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: [], static fn (string $part): bool => $part !== ''));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'MP';
}

$estateName = trim((string) ($estate['estate_name'] ?? ''));
$roleName = trim((string) ($estate['role_name'] ?? ''));
$roleLabel = $roleName !== '' ? ucwords(str_replace(['_', '-'], ' ', $roleName)) : 'Resident';
$membershipCount = count($memberships);

// Visitors and Bookings are pushed screens: their views render their own top
// bars and the bottom bar makes way for the sticky primary CTA. The desktop
// sidebar keeps the original four-section treatment; the Home quick tiles are
// the entry points for the two list screens (as in the approved mockup).
$screen = strtolower(trim($title));
$pushedScreen = in_array($screen, ['visitors', 'bookings', 'invite a visitor', 'invite visitor', 'visitor pass', 'pass', 'vehicles', 'my parking', 'parking'], true);
$sidebarActive = $activeNav === 'account' ? 'account' : 'home';

$navItems = [
    ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'href' => '/app', 'soon' => null],
    ['key' => 'activity', 'label' => 'Activity', 'icon' => 'activity', 'href' => null, 'soon' => 'Activity arrives with the visitor timeline in a later increment.'],
    ['key' => 'inbox', 'label' => 'Inbox', 'icon' => 'inbox', 'href' => null, 'soon' => 'Estate notices and notifications arrive with the Notifications module.'],
    ['key' => 'account', 'label' => 'Account', 'icon' => 'account', 'href' => '/app/account', 'soon' => null],
];

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
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
</head>
<body class="app-body<?= $pushedScreen ? ' app-body--pushed' : '' ?>">
  <a class="skip-link" href="#main">Skip to content</a>

  <div class="net-banner" data-net-banner role="status" hidden>
    <?= $icon('wifi-off', 'ico ico--sm') ?>
    <span data-net-text>You are offline. Fresh estate data is unavailable.</span>
  </div>

  <div class="app-shell">
    <aside class="sidebar" aria-label="Estate workspace">
      <a class="sidebar__brand" href="/app">
        <?= \App\Core\View::capture('partials/brand-mark') ?>
        <span>
          <?= e($appName) ?>
          <small>Estate workspace</small>
        </span>
      </a>

      <a class="sidebar__estate" href="/app/estates">
        <span class="sidebar__estate-label">
          <?= $icon('pin', 'ico ico--sm') ?>
          Active estate
        </span>
        <span class="sidebar__estate-name"><?= e($estateName !== '' ? $estateName : 'No active estate') ?></span>
        <?php if ($estateName !== ''): ?>
          <span class="sidebar__estate-meta"><?= e($roleLabel) ?><?php if ($membershipCount > 1): ?> · <?= e((string) $membershipCount) ?> estates<?php endif; ?></span>
        <?php endif; ?>
      </a>

      <div>
        <p class="sidebar__section-label">Menu</p>
        <nav class="sidebar__nav" aria-label="Sections">
          <?php foreach ($navItems as $item): ?>
            <?php if ($item['soon'] !== null): ?>
              <button type="button" class="nav-item" data-soon="<?= e($item['soon']) ?>" aria-disabled="true">
                <?= $icon($item['icon'], 'ico') ?>
                <span class="nav-item__label"><?= e($item['label']) ?></span>
                <span class="chip chip--soon">Soon</span>
              </button>
            <?php else: ?>
              <a class="nav-item<?= $sidebarActive === $item['key'] ? ' nav-item--active' : '' ?>"
                 href="<?= e((string) $item['href']) ?>"
                 <?= $sidebarActive === $item['key'] ? 'aria-current="page"' : '' ?>>
                <?= $icon($item['icon'], 'ico') ?>
                <span class="nav-item__label"><?= e($item['label']) ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </div>

      <div class="sidebar__spacer"></div>

      <div class="sidebar__foot">
        <a class="sidebar__user" href="/app/account">
          <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials) ?></span>
          <span>
            <span class="sidebar__user-name"><?= e($fullName) ?></span>
            <span class="sidebar__user-role"><?= e($roleLabel) ?><?php if ($isDev): ?> · Dev<?php endif; ?></span>
          </span>
        </a>
        <div class="cluster cluster--between">
          <?= \App\Core\View::capture('partials/theme-toggle') ?>
          <form method="post" action="/logout" data-logout>
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost" aria-label="Sign out">
              <?= $icon('logout', 'ico ico--sm') ?>
              Sign out
            </button>
          </form>
        </div>
        <p class="sidebar__version"><?= e($appName) ?> <?= e($appVersion) ?></p>
      </div>
    </aside>

    <div class="app-frame">
      <main id="main" class="app-main">
        <div class="page">
          <div class="install-bar" data-install-bar data-install-banner role="status" aria-live="polite" hidden>
            <span class="icon-tile icon-tile--sm" aria-hidden="true"><?= $icon('download', 'ico ico--sm') ?></span>
            <div class="install-bar__text">
              <p class="install-bar__title">Install <?= e($appName) ?> on this device</p>
              <p class="row__sub" data-install-message>Add it to your home screen for one-tap access.</p>
            </div>
            <button type="button" class="btn btn--primary btn--sm" data-install>Install</button>
            <button type="button" class="btn btn--icon btn--ghost" data-install-dismiss aria-label="Dismiss install prompt" title="Dismiss install prompt">
              <?= $icon('close', 'ico ico--sm') ?>
            </button>
          </div>

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
        </div>
      </main>
    </div>

    <nav class="tabbar" aria-label="Primary">
      <a class="tab<?= $activeNav === 'home' ? ' tab--active' : '' ?>" href="/app"
         <?= $activeNav === 'home' ? 'aria-current="page"' : '' ?>>
        <span class="tab__icon"><?= $icon('home', 'ico') ?></span>
        <span class="tab__label">Home</span>
      </a>

      <button type="button" class="tab tab--soon"
              data-soon="Activity arrives with the visitor timeline in a later increment."
              aria-disabled="true">
        <span class="tab__icon"><?= $icon('activity', 'ico') ?></span>
        <span class="tab__label">Activity</span>
      </button>

      <button type="button" class="tab-fab" data-qr-open aria-haspopup="dialog" aria-controls="qr-sheet"
              aria-label="Show my access QR" title="Show my access QR">
        <?= $icon('qr', 'ico tab-fab__icon') ?>
      </button>

      <button type="button" class="tab tab--soon"
              data-soon="Estate notices and notifications arrive with the Notifications module."
              aria-disabled="true">
        <span class="tab__icon"><?= $icon('inbox', 'ico') ?></span>
        <span class="tab__label">Inbox</span>
      </button>

      <a class="tab<?= $activeNav === 'account' ? ' tab--active' : '' ?>" href="/app/account"
         <?= $activeNav === 'account' ? 'aria-current="page"' : '' ?>>
        <span class="tab__icon"><?= $icon('account', 'ico') ?></span>
        <span class="tab__label">Account</span>
      </a>
    </nav>
  </div>

  <?= \App\Core\View::capture('partials/qr-sheet', ['estate' => $estate, 'next_pass' => $nextPass]) ?>

  <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>

  <script src="/assets/js/app.js?v=<?= e($assetVersion) ?>" defer></script>
</body>
</html>
