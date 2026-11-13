<?php

declare(strict_types=1);

/**
 * Estate admin console layout (estate-admin-management-contract §3).
 *
 * Separate shell from the resident PWA: sticky top bar (config-driven product
 * name, active estate, signed-in admin, back-to-app, sign-out), a seven-section
 * admin navigation (sidebar at ≥1024px, scrollable tabs below), flash messages,
 * and the shared design tokens from app.css plus admin.css layout styles.
 *
 * All data is optional so partial integration data still renders without
 * notices: $title, $user, $estate, $permissions, $csrfToken, $appVersion,
 * $isDev, $activeNav, $flash_success / $flash_error (legacy: $flashSuccess /
 * $flashError), $content.
 */

$title = isset($title) && is_string($title) && trim($title) !== '' ? trim($title) : 'Admin';

require __DIR__ . '/../admin/_helpers.php';

$user = isset($user) && is_array($user) ? $user : [];
$estate = isset($estate) && is_array($estate) ? $estate : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);
$isDev = (bool) ($isDev ?? false);
$content = $content ?? '';
$appName = (string) config('app.name', 'MtaaPass');

$flashSuccess = $flash_success ?? $flashSuccess ?? null;
$flashError = $flash_error ?? $flashError ?? null;

$estateName = trim((string) ($estate['estate_name'] ?? ''));
$roleName = trim((string) ($estate['role_name'] ?? ''));
$roleLabel = $roleName !== '' ? admin_humanize($roleName) : 'Administrator';

$fullName = trim((string) ($user['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Administrator';
}
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: [], static fn (string $part): bool => $part !== ''));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'AD';
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$navItems = [
    ['key' => 'overview', 'label' => 'Overview', 'icon' => 'grid', 'href' => '/admin', 'can' => 'estate.view'],
    ['key' => 'buildings', 'label' => 'Buildings', 'icon' => 'building', 'href' => '/admin/buildings', 'can' => 'household.view'],
    ['key' => 'households', 'label' => 'Households', 'icon' => 'home', 'href' => '/admin/households', 'can' => 'household.view'],
    ['key' => 'residents', 'label' => 'Residents', 'icon' => 'users', 'href' => '/admin/residents', 'can' => 'estate.user.manage'],
    ['key' => 'move-outs', 'label' => 'Move-outs', 'icon' => 'logout', 'href' => '/admin/residents?tab=move-outs', 'can' => 'estate.user.manage'],
    ['key' => 'guards', 'label' => 'Guards', 'icon' => 'shield', 'href' => '/admin/guards', 'can' => 'guard.manage'],
    ['key' => 'staff', 'label' => 'Staff', 'icon' => 'users', 'href' => '/admin/staff', 'can' => 'household.staff.manage'],
    ['key' => 'incidents', 'label' => 'Incidents', 'icon' => 'alert', 'href' => '/admin/incidents', 'can' => 'visitor.incident.manage'],
    ['key' => 'notices', 'label' => 'Notices', 'icon' => 'bell', 'href' => '/admin/notices', 'can' => 'notice.manage'],
    ['key' => 'gates', 'label' => 'Gates', 'icon' => 'gate', 'href' => '/admin/gates', 'can' => 'gate.view'],
    ['key' => 'parking', 'label' => 'Parking', 'icon' => 'parking', 'href' => '/admin/parking', 'can' => 'parking.view'],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'href' => '/admin/settings', 'can' => 'estate.settings.manage'],
];

// Only advertise sections this administrator can actually open (no 403 links).
$visibleNavItems = array_values(array_filter(
    $navItems,
    static fn (array $item): bool => admin_can($permissions, (string) $item['can'])
));

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
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="app-body admin-body">
  <a class="skip-link" href="#main">Skip to content</a>

  <div class="net-banner" data-net-banner role="status" hidden>
    <?= $icon('wifi-off', 'ico ico--sm') ?>
    <span data-net-text>You are offline. Admin changes will not reach the estate server.</span>
  </div>

  <header class="admin-topbar">
    <a class="admin-topbar__brand" href="/admin">
      <?= \App\Core\View::capture('partials/brand-mark') ?>
      <span class="admin-topbar__brand-text">
        <span class="admin-topbar__name"><?= e($appName) ?></span>
        <span class="admin-topbar__console">Admin console</span>
      </span>
    </a>

    <span class="admin-topbar__estate">
      <?= $icon('pin', 'ico ico--sm') ?>
      <span class="admin-topbar__estate-name">
        <?= e($estateName !== '' ? $estateName : 'No active estate') ?>
      </span>
      <?php if ($roleName !== ''): ?>
        <span class="chip chip--on-dark chip--sm"><?= e($roleLabel) ?></span>
      <?php endif; ?>
    </span>

    <div class="admin-topbar__actions">
      <?= \App\Core\View::capture('partials/incident-siren', [
          'incidents_unread_count' => $incidents_unread_count ?? null,
          'incidents_latest_link' => $incidents_latest_link ?? null,
      ]) ?>
      <?= \App\Core\View::capture('partials/theme-toggle') ?>
      <span class="admin-topbar__user" title="<?= e($fullName) ?>">
        <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials) ?></span>
        <span class="admin-topbar__user-name"><?= e($fullName) ?></span>
      </span>
      <a class="admin-topbar__link" href="/app"><?= $icon('home', 'ico ico--sm') ?> <span>Back to app</span></a>
      <form method="post" action="/logout" class="admin-topbar__logout" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm" aria-label="Sign out">
          <?= $icon('logout', 'ico ico--sm') ?>
          <span>Sign out</span>
        </button>
      </form>
    </div>
  </header>

  <div class="admin-shell">
    <nav class="admin-nav" aria-label="Admin sections">
      <?php foreach ($visibleNavItems as $item): ?>
        <a class="admin-nav__item<?= $activeNav === $item['key'] ? ' admin-nav__item--active' : '' ?>"
           href="<?= e((string) $item['href']) ?>"
           <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
          <?= $icon((string) $item['icon'], 'ico') ?>
          <span class="admin-nav__label"><?= e((string) $item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <main id="main" class="admin-main">
      <div class="admin-page">
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

  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
  <script src="<?= e(asset('js/date-range.js')) ?>" defer></script>
  <script src="<?= e(asset('js/utility-run.js')) ?>" defer></script>
</body>
</html>
