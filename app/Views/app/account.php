<?php

declare(strict_types=1);

/**
 * R16 — Profile / account & preferences (app layout).
 * Variables: shell data + $user.
 */

$user = isset($user) && is_array($user) ? $user : [];
$shellEstate = isset($estate) && is_array($estate) ? $estate : [];
$memberships = isset($memberships) && is_array($memberships) ? $memberships : [];
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$isDev = (bool) ($isDev ?? false);
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];

// Admin console entry point: only for roles that can actually administer the
// estate. Deliberately excludes broad read slugs (estate.view, household.view)
// and household.member.manage, which ordinary residents also hold.
$adminAccessSlugs = [
    'estate.user.manage',
    'household.manage',
    'guard.manage',
    'gate.manage',
    'estate.settings.manage',
];
$canAdminConsole = false;
foreach ($permissions as $permissionSlug) {
    if (is_string($permissionSlug) && in_array($permissionSlug, $adminAccessSlugs, true)) {
        $canAdminConsole = true;
        break;
    }
}

// Household services (Increment 6/7): mirror the permissions that gate the routes
// so residents are never shown a link that would 403.
$canStaffDirectory = in_array('household.staff.manage', $permissions, true);
$canReportIncident = in_array('visitor.incident.create', $permissions, true);
$canViewNotices = in_array('notice.view', $permissions, true);

// Platform console (Increment 9): only platform operators hold platform.* slugs.
// The estate PWA shell exposes estate-scoped permissions, so platform access is
// also accepted from an explicit `platform_access` / `platform_permissions`
// shell payload once the platform module starts passing it (a platform-only
// operator has no estate context and therefore 0 estate permissions).
$platformPermissionSlugs = [];
if (isset($platform_permissions) && is_array($platform_permissions)) {
    foreach ($platform_permissions as $platformPermission) {
        if (is_string($platformPermission)) {
            $platformPermissionSlugs[] = $platformPermission;
        }
    }
}
$canPlatformConsole = isset($platform_access) ? (bool) $platform_access : false;
if (!$canPlatformConsole && isset($user['platform_access'])) {
    $canPlatformConsole = (bool) $user['platform_access'];
}
if (!$canPlatformConsole) {
    foreach (array_merge($permissions, $platformPermissionSlugs) as $permissionSlug) {
        if (is_string($permissionSlug) && str_starts_with($permissionSlug, 'platform.')) {
            $canPlatformConsole = true;
            break;
        }
    }
}

$fullName = trim((string) ($user['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Resident';
}
$phone = trim((string) ($user['phone'] ?? ''));
$email = trim((string) ($user['email'] ?? ''));
$lastLogin = trim((string) ($user['last_login_at'] ?? ''));
$status = trim((string) ($user['status'] ?? 'active'));

$nameParts = array_values(array_filter(preg_split('/\s+/', $fullName) ?: [], static fn (string $p): bool => $p !== ''));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'MP';
}

$displayPhone = $phone;
if ($phone !== '' && \App\Core\Phone::isValid($phone)) {
    $displayPhone = \App\Core\Phone::display($phone);
}
if ($displayPhone === '') {
    $displayPhone = 'Not set';
}

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

$activeUuid = trim((string) ($shellEstate['estate_uuid'] ?? ''));
$activeRole = $humanise(trim((string) ($shellEstate['role_name'] ?? '')));
$estateCount = count($memberships);
$timezone = trim((string) ($shellEstate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident">
  <header class="screen-bar screen-bar--root">
    <h1 class="screen-bar__title">Account</h1>
    <span class="screen-bar__actions"><?= \App\Core\View::capture('partials/theme-toggle') ?></span>
  </header>

  <div class="account-grid">
  <div class="stack">
    <section class="card profile-card" aria-labelledby="profile-name">
      <span class="avatar avatar--lg" aria-hidden="true"><?= e($initials) ?></span>
      <div class="profile-card__body">
        <h2 class="profile-card__name" id="profile-name"><?= e($fullName) ?></h2>
        <p class="profile-card__meta"><?= e($displayPhone) ?><?php if ($email !== ''): ?> · <?= e($email) ?><?php endif; ?></p>
        <div class="profile-card__tags">
          <?php if ($activeRole !== ''): ?>
            <span class="chip chip--emerald"><?= e($activeRole) ?></span>
          <?php endif; ?>
          <span class="status <?= $status === 'active' ? 'status--active' : 'status--pending' ?>">
            <?= e($status === 'active' ? 'Active account' : $humanise($status)) ?>
          </span>
          <?php if ($activeUuid !== ''): ?>
            <span class="chip"><?= e((string) ($shellEstate['estate_name'] ?? 'Estate')) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($canStaffDirectory || $canReportIncident || $canViewNotices): ?>
      <section class="card" aria-labelledby="household-services-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="household-services-title"><?= $icon('users', 'ico ico--sm') ?> Household services</h2>
            <p class="card__hint">Estate notices, household staff and security or access incidents.</p>
          </div>
        </div>
        <div class="form-actions">
          <?php if ($canViewNotices): ?>
            <a class="btn btn--outline btn--block" href="/app/notices">
              <?= $icon('bell', 'ico ico--sm') ?>
              Estate notices
            </a>
          <?php endif; ?>
          <?php if ($canStaffDirectory): ?>
            <a class="btn btn--outline btn--block" href="/app/staff">
              <?= $icon('users', 'ico ico--sm') ?>
              Household staff
            </a>
          <?php endif; ?>
          <?php if ($canReportIncident): ?>
            <a class="btn btn--outline btn--block" href="/app/incidents">
              <?= $icon('alert', 'ico ico--sm') ?>
              My incidents
            </a>
            <a class="btn btn--ghost btn--block" href="/app/incidents/new">
              <?= $icon('plus', 'ico ico--sm') ?>
              Report an incident
            </a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($activeUuid !== ''): ?>
      <section class="card" aria-labelledby="bills-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="bills-title"><?= $icon('receipt', 'ico ico--sm') ?> Bills &amp; payments</h2>
            <p class="card__hint">Utility bills for your household, with payment links from your estate office.</p>
          </div>
        </div>
        <a class="btn btn--outline btn--block" href="<?= e('/app/bills') ?>">
          <?= $icon('receipt', 'ico ico--sm') ?>
          View my bills
        </a>
      </section>
    <?php endif; ?>

    <?php if ($canPlatformConsole): ?>
      <section class="card admin-entry" aria-labelledby="platform-console-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="platform-console-title"><?= $icon('grid', 'ico ico--sm') ?> Platform console</h2>
            <p class="card__hint">Provision estates, manage the plan catalogue and estate subscriptions across the platform.</p>
          </div>
        </div>
        <a class="btn btn--primary btn--block" href="/platform">
          <?= $icon('arrow-up-right', 'ico ico--sm') ?>
          Open platform console
        </a>
      </section>
    <?php endif; ?>

    <?php if ($canAdminConsole): ?>
      <section class="card admin-entry" aria-labelledby="admin-console-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="admin-console-title"><?= $icon('shield', 'ico ico--sm') ?> Admin console</h2>
            <p class="card__hint">Manage buildings, households, residents, guards, gates and estate settings for this estate.</p>
          </div>
        </div>
        <a class="btn btn--primary btn--block" href="/admin">
          <?= $icon('arrow-up-right', 'ico ico--sm') ?>
          Open admin console
        </a>
      </section>
    <?php endif; ?>

    <section class="card" aria-label="Sign out of this device">
      <form method="post" action="<?= e('/logout') ?>" class="signout-form" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger-ghost btn--block">
          <?= $icon('logout', 'ico ico--sm') ?>
          Sign out of this device
        </button>
      </form>
    </section>
  </div>

  <div class="stack">


    <section class="card" aria-labelledby="about-title">
      <div class="card__head">
        <div>
          <h2 class="card__title" id="about-title"><?= $icon('info', 'ico ico--sm') ?> About <?= e((string) config('app.name', 'MtaaPass')) ?></h2>
          <p class="card__hint">Foundation increment — built in small, honest steps.</p>
        </div>
      </div>

      <div class="kv-list">
        <div class="kv">
          <span class="kv__key">Version</span>
          <span class="kv__value"><?= e($appVersion) ?></span>
        </div>
        <div class="kv">
          <span class="kv__key">Environment</span>
          <span class="kv__value"><?= $isDev ? 'Development' : 'Production' ?></span>
        </div>
        <div class="kv">
          <span class="kv__key">App status</span>
          <span class="kv__value" data-install-state
                data-installed-text="Installed on this device"
                data-browser-text="Browser mode — not installed">Browser mode — not installed</span>
        </div>
        <div class="kv">
          <span class="kv__key">Permissions</span>
          <span class="kv__value"><?= e((string) count($permissions)) ?> granted</span>
        </div>
        <div class="kv">
          <span class="kv__key">Estates</span>
          <span class="kv__value"><?= e((string) $estateCount) ?></span>
        </div>
      </div>

      <div class="card__foot">
        <p class="row__sub">Install <?= e((string) config('app.name', 'MtaaPass')) ?> on your home screen for one-tap access.</p>
        <button type="button" class="btn btn--outline btn--block" data-install hidden data-install-trigger>
          <?= $icon('download', 'ico ico--sm') ?>
          Install app
        </button>
        <button type="button" class="btn btn--ghost btn--block" data-install>
          <?= $icon('refresh', 'ico ico--sm') ?>
          How to install
        </button>
        <p class="row__sub" data-install-hint>
          On iPhone or iPad: tap Share, then “Add to Home Screen”.
        </p>
      </div>
    </section>

    <section class="card card--tint" aria-labelledby="support-title">
      <div class="card__head">
        <div>
          <h2 class="card__title" id="support-title"><?= $icon('shield', 'ico ico--sm') ?> Need help?</h2>
          <p class="card__hint">Your estate administrator manages access and household links.</p>
        </div>
      </div>
      <p class="row__sub">
        For account or access issues, contact
        <a href="mailto:<?= e((string) config('app.support_email', 'hello@dashpass.africa')) ?>"><?= e((string) config('app.support_email', 'hello@dashpass.africa')) ?></a>.
      </p>
    </section>
    </div>
  </div>
</div>
