<?php

declare(strict_types=1);

/**
 * Guard application layout (visitor-access-module-contract §7, §7.1).
 *
 * A deliberately focused, high-contrast shell for gate staff: gate context in the
 * header, four primary destinations (Dashboard, Verify, Active, Walk-in), a
 * logout action, and large mobile navigation. It reuses the resident design
 * tokens from app.css (navy dark default) and adds only guard-specific styles in
 * guard.css.
 *
 * Canonical data (§7.1): $title, $user {full_name, phone}, $gate (name|null),
 * $selected_gate {uuid,name}|null, $gates [{uuid,name,assigned}], $csrfToken,
 * $activeNav (dashboard|verify|active|walkin), $flashError, $flashSuccess,
 * $appVersion, $isDev. Every key is optional so partial integration data still
 * renders without notices.
 */

$title = isset($title) && is_string($title) && trim($title) !== '' ? trim($title) : 'Guard';
$user = isset($user) && is_array($user) ? $user : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ''));
if ($gateName === '' && $selectedGate !== null) {
    $gateName = trim((string) ($selectedGate['name'] ?? ''));
}
$gates = isset($gates) && is_array($gates) ? $gates : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);
$isDev = (bool) ($isDev ?? false);
$flashError = $flashError ?? null;
$flashSuccess = $flashSuccess ?? null;
$content = $content ?? '';
$appName = (string) config('app.name', 'MtaaPass');
$estate = isset($estate) && is_array($estate) ? $estate : [];
// Inc-13 P2a notification shell data. Guard controllers build their own shell,
// so fall back to the shared helper (BuildsShell already merges the same keys
// for app pages).
$notificationShell = function_exists('notification_shell') ? notification_shell() : [];
$notificationsEnabled = (bool) ($notifications_enabled ?? $notificationShell['notifications_enabled'] ?? false);
$notificationsUnreadCount = max(0, (int) ($notifications_unread_count ?? $notificationShell['notifications_unread_count'] ?? 0));
$notificationsLatest = is_array($notifications_latest ?? null) && ($notifications_latest ?? []) !== []
    ? $notifications_latest
    : (is_array($notificationShell['notifications_latest'] ?? null) ? $notificationShell['notifications_latest'] : []);

$navKeys = ['dashboard', 'verify', 'active', 'walkin', 'incidents', 'notices'];
$activeNav = isset($activeNav) && is_string($activeNav) && in_array($activeNav, $navKeys, true)
    ? $activeNav
    : 'dashboard';

// Nested/derived guard screens (e.g. /gate/incidents/new, /gate/parking/violations,
// or any controller that passes no nav key) still highlight the correct section:
// infer the active nav item from the request path.
$guardRequestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$pathNav = match (true) {
    str_starts_with($guardRequestPath, '/gate/incidents') => 'incidents',
    str_starts_with($guardRequestPath, '/gate/notices') => 'notices',
    str_starts_with($guardRequestPath, '/gate/verify') => 'verify',
    str_starts_with($guardRequestPath, '/gate/active') => 'active',
    str_starts_with($guardRequestPath, '/gate/walkin') => 'walkin',
    $guardRequestPath === '/gate' || $guardRequestPath === '/gate/' => 'dashboard',
    default => null,
};

if ($pathNav !== null) {
    $activeNav = $pathNav;
}

$fullName = trim((string) ($user['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Guard';
}
$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: [], static fn (string $part): bool => $part !== ''));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'GD';
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'short' => 'Home', 'icon' => 'home', 'href' => '/gate'],
    ['key' => 'verify', 'label' => 'Check-in', 'short' => 'Verify', 'icon' => 'key', 'href' => '/gate/verify'],
    ['key' => 'active', 'label' => 'Active', 'short' => 'Active', 'icon' => 'users', 'href' => '/gate/active'],
    ['key' => 'walkin', 'label' => 'Walk-in', 'short' => 'Walk-in', 'icon' => 'ticket', 'href' => '/gate/walkin'],
    ['key' => 'incidents', 'label' => 'Incidents', 'short' => 'Incidents', 'icon' => 'alert', 'href' => '/gate/incidents'],
    ['key' => 'notices', 'label' => 'Notices', 'short' => 'Notices', 'icon' => 'bell', 'href' => '/gate/notices'],
];

$hasFlash = (is_string($flashError) && trim($flashError) !== '')
    || (is_string($flashSuccess) && trim($flashSuccess) !== '');
$gateAssigned = $gates !== [] && (bool) array_filter(
    $gates,
    static fn (mixed $candidate): bool => is_array($candidate) && (bool) ($candidate['assigned'] ?? false)
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
<link rel="stylesheet" href="<?= e(asset('css/guard.css')) ?>">
</head>
<body class="app-body guard-body">
  <a class="skip-link" href="#main">Skip to content</a>

  <div class="net-banner" data-net-banner role="status" hidden>
    <?= $icon('wifi-off', 'ico ico--sm') ?>
    <span data-net-text>You are offline. Verification will not reach the estate server.</span>
  </div>

  <header class="guard-header">
    <div class="guard-header__brand">
      <?= \App\Core\View::capture('partials/brand-mark') ?>
      <span class="guard-header__name">
        <?= e($appName) ?>
        <small>Guard console</small>
      </span>
    </div>

    <div class="guard-header__gate">
      <?= $icon('gate', 'ico ico--sm') ?>
      <span class="guard-header__gate-name<?= $gateName === '' ? ' guard-header__gate-name--empty' : '' ?>">
        <?= e($gateName !== '' ? $gateName : 'No gate selected') ?>
      </span>
      <?php if ($gateName !== ''): ?>
        <span class="chip chip--on-dark chip--sm">On duty</span>
      <?php endif; ?>
    </div>

    <div class="guard-header__actions">
      <div class="guard-header__bell">
        <?= \App\Core\View::capture('partials/notification-bell', [
            'notifications_enabled' => $notificationsEnabled,
            'notifications_unread_count' => $notificationsUnreadCount,
            'notifications_latest' => $notificationsLatest,
            'csrfToken' => $csrfToken,
            'estate' => $estate,
        ]) ?>
      </div>
      <span class="guard-header__user" title="<?= e($fullName) ?>">
        <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials) ?></span>
        <span class="guard-header__user-name"><?= e($fullName) ?></span>
      </span>
      <form method="post" action="/logout" data-logout class="guard-header__logout">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm" aria-label="Sign out">
          <?= $icon('logout', 'ico ico--sm') ?>
          <span>Exit</span>
        </button>
      </form>
    </div>
  </header>

  <nav class="guard-nav" aria-label="Guard sections">
    <?php foreach ($navItems as $item): ?>
      <a class="guard-nav__item<?= $activeNav === $item['key'] ? ' guard-nav__item--active' : '' ?>"
         href="<?= e($item['href']) ?>"
         <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
        <?= $icon($item['icon'], 'ico') ?>
        <span><?= e($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <main id="main" class="guard-main">
    <div class="page guard-page">
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

      <?php if (!$gateAssigned && $activeNav !== 'dashboard'): ?>
        <div class="alert alert--warning" role="status">
          <?= $icon('shield') ?>
          <div class="alert__body">
            <p class="alert__title">No gate selected</p>
            <p>Choose one of your assigned gates on the dashboard before verifying visitors.</p>
            <p><a class="text-link" href="/gate">Go to dashboard</a></p>
          </div>
        </div>
      <?php endif; ?>

      <?= $content ?>
    </div>
  </main>

  <nav class="guard-tabbar" aria-label="Guard sections">
    <?php foreach ($navItems as $item): ?>
      <a class="guard-tab<?= $activeNav === $item['key'] ? ' guard-tab--active' : '' ?>"
         href="<?= e($item['href']) ?>"
         <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
        <span class="guard-tab__icon"><?= $icon($item['icon'], 'ico') ?></span>
        <span class="guard-tab__label"><?= e($item['short']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>

  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
  <script src="<?= e(asset('js/guard.js')) ?>" defer></script>
</body>
</html>
