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

    <section class="card" aria-labelledby="memberships-title">
      <div class="card__head">
        <div>
          <h2 class="card__title" id="memberships-title"><?= $icon('building', 'ico ico--sm') ?> Your estates</h2>
          <p class="card__hint">Memberships are managed by your estate administrator.</p>
        </div>
        <a class="link-quiet" href="<?= e('/app/estates') ?>">Manage</a>
      </div>

      <?php if ($memberships !== []): ?>
        <ul class="list">
          <?php foreach ($memberships as $membership): ?>
            <?php
            if (!is_array($membership)) {
                continue;
            }
            $estateUuid = trim((string) ($membership['estate_uuid'] ?? ''));
            $isActive = $activeUuid !== '' && $estateUuid === $activeUuid;
            ?>
            <li class="row">
              <span class="icon-tile icon-tile--sm icon-tile--forest" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
              <div class="row__body">
                <p class="row__title"><?= e((string) ($membership['estate_name'] ?? 'Estate')) ?></p>
                <p class="row__sub"><?= e($humanise(trim((string) ($membership['role_name'] ?? '')))) ?><?php if ($isActive): ?> · current estate<?php endif; ?></p>
              </div>
              <div class="row__trail">
                <span class="status <?= $isActive ? 'status--active' : 'status--inactive' ?>">
                  <?= $isActive ? 'Active' : 'Switchable' ?>
                </span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty empty--inline">
          <span class="empty__icon" aria-hidden="true"><?= $icon('building') ?></span>
          <div>
            <p class="empty__title">No estate memberships</p>
            <p class="empty__text">Ask your estate administrator to add your phone number to a household.</p>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="security-title">
      <div class="card__head">
        <div>
          <h2 class="card__title" id="security-title"><?= $icon('lock', 'ico ico--sm') ?> Sign-in &amp; security</h2>
          <p class="card__hint">Passwordless by design — no password to leak or reuse.</p>
        </div>
      </div>

      <div class="kv-list">
        <div class="kv">
          <span class="kv__key">Sign-in method</span>
          <span class="kv__value">Phone + one-time code</span>
        </div>
        <div class="kv">
          <span class="kv__key">Phone number</span>
          <span class="kv__value"><?= e($displayPhone) ?></span>
        </div>
        <div class="kv">
          <span class="kv__key">Session</span>
          <span class="kv__value">Secure cookie · CSRF protected</span>
        </div>
        <?php if ($lastLogin !== ''): ?>
          <div class="kv">
            <span class="kv__key">Last sign-in</span>
            <span class="kv__value"><?= e(format_datetime($lastLogin, $timezone)) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($isDev): ?>
          <div class="kv">
            <span class="kv__key">Development OTP</span>
            <span class="kv__value"><span class="chip chip--sunshine">Shown on screen</span></span>
          </div>
        <?php endif; ?>
      </div>

      <form method="post" action="<?= e('/logout') ?>" class="signout-form" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger-ghost">
          <?= $icon('logout', 'ico ico--sm') ?>
          Sign out of this device
        </button>
      </form>
    </section>
  </div>

  <div class="stack">
    <section class="card" aria-labelledby="appearance-title">
      <div class="card__head">
        <div>
          <h2 class="card__title" id="appearance-title"><?= $icon('sun', 'ico ico--sm') ?> Appearance</h2>
          <p class="card__hint">The resident app is dark by default. Your choice is stored on this device only.</p>
        </div>
      </div>

      <div class="segmented" role="group" aria-label="Colour theme">
        <button type="button" class="segmented__item" data-theme-set="system" aria-pressed="true">
          <?= $icon('sparkle', 'ico ico--sm') ?>
          Default
        </button>
        <button type="button" class="segmented__item" data-theme-set="light" aria-pressed="false">
          <?= $icon('sun', 'ico ico--sm') ?>
          Light
        </button>
        <button type="button" class="segmented__item" data-theme-set="dark" aria-pressed="false">
          <?= $icon('moon', 'ico ico--sm') ?>
          Dark
        </button>
      </div>

      <div class="pref-row">
        <div class="pref-row__body">
          <p class="pref-row__title">Reduced motion</p>
          <p class="pref-row__desc"><?= e((string) config('app.name', 'MtaaPass')) ?> already respects your device motion preference.</p>
        </div>
        <span class="status status--active">Automatic</span>
      </div>
    </section>

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

      <div class="install-check" data-install-check>
        <div class="install-check__head">
          <h3 class="install-check__title"><?= $icon('download', 'ico ico--sm') ?> Installation check</h3>
          <p class="row__sub">If installing is unavailable, this shows the exact blocker.</p>
        </div>

        <dl class="install-check__list">
          <div class="install-check__row">
            <dt>Connection</dt>
            <dd data-install-check-https>Checking…</dd>
          </div>
          <div class="install-check__row">
            <dt>Manifest</dt>
            <dd data-install-check-manifest>Checking…</dd>
          </div>
          <div class="install-check__row">
            <dt>Service worker</dt>
            <dd data-install-check-sw>Checking…</dd>
          </div>
          <div class="install-check__row">
            <dt>Install prompt</dt>
            <dd data-install-check-prompt>Checking…</dd>
          </div>
          <div class="install-check__row">
            <dt>Display mode</dt>
            <dd data-install-check-display>Checking…</dd>
          </div>
          <div class="install-check__row">
            <dt>Browser</dt>
            <dd data-install-check-platform>Checking…</dd>
          </div>
        </dl>

        <p class="install-check__verdict" data-install-check-verdict>Checking install readiness…</p>
        <p class="row__sub install-steps" data-install-steps-text></p>

        <details class="install-why">
          <summary>Why can't I install?</summary>
          <ul class="install-why__list">
            <li><strong>In-app browsers</strong> (WhatsApp, Instagram, Facebook, LinkedIn, X, TikTok, embedded webviews) cannot install apps — open this page in Chrome or Safari instead.</li>
            <li><strong>iOS</strong> has no native install prompt: in Safari tap Share → “Add to Home Screen”.</li>
            <li><strong>Android Chrome</strong> may delay the install prompt until you have used the site a little — the ⋮ menu → “Install app” (or “Add to Home screen”) usually works.</li>
            <li><strong>HTTPS is required</strong> — install only works on the secure address; HTTP or ngrok free links cannot install.</li>
            <li>A <strong>stale deployment</strong> can hide a newer install setup — close and reopen the tab first.</li>
          </ul>
        </details>
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
        <a href="mailto:<?= e((string) config('app.support_email', 'support@mtaapass.com')) ?>"><?= e((string) config('app.support_email', 'support@mtaapass.com')) ?></a>.
      </p>
    </section>
    </div>
  </div>
</div>
