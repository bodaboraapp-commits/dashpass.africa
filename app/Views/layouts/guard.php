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

$navKeys = ['dashboard', 'verify', 'active', 'walkin'];
$activeNav = isset($activeNav) && is_string($activeNav) && in_array($activeNav, $navKeys, true)
    ? $activeNav
    : 'dashboard';

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
<link rel="stylesheet" href="/assets/css/guard.css?v=<?= e($assetVersion) ?>">
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
      <span class="guard-header__user" title="<?= e($fullName) ?>">
        <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials) ?></span>
        <span class="guard-header__user-name"><?= e($fullName) ?></span>
      </span>
      <a class="guard-header__link" href="/app/account">Account</a>
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

  <script src="/assets/js/app.js?v=<?= e($assetVersion) ?>" defer></script>
  <script src="/assets/js/guard.js?v=<?= e($assetVersion) ?>" defer></script>
</body>
</html>
