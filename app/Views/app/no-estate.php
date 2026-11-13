<?php

declare(strict_types=1);

/**
 * No-estate explanation (guest layout).
 *
 * Two audiences:
 *  - estate-less residents: sign-in worked but no estate membership is attached
 *    yet (unchanged copy/actions);
 *  - platform operators: accounts that legitimately have no estate membership
 *    (e.g. a platform super admin). They get platform-appropriate copy and a
 *    primary "Go to platform portal" CTA; the resident onboarding wording would
 *    be misleading for them.
 *
 * Variables (contract §8.2): $csrfToken, $appVersion, $isDev, plus optional
 * platform hints from the controller (T152/T153): `platform_access` (bool,
 * preferred), `platform_permissions` [], `platform_roles` [], or
 * `user['platform_access']` (bool alias). Everything is optional/defensive: if
 * no platform hint is present the page renders the resident state.
 */

$platformPermissions = isset($platform_permissions) && is_array($platform_permissions) ? $platform_permissions : [];
$platformRoles = isset($platform_roles) && is_array($platform_roles) ? $platform_roles : [];
$user = isset($user) && is_array($user) ? $user : [];

$platformAccess = false;
foreach (['platform_access', 'is_platform_operator', 'is_platform_user', 'can_platform', 'can_platform_portal'] as $flagKey) {
    if (isset($$flagKey) && filter_var($$flagKey, FILTER_VALIDATE_BOOLEAN)) {
        $platformAccess = true;
        break;
    }
}
if (!$platformAccess && isset($user['platform_access']) && filter_var($user['platform_access'], FILTER_VALIDATE_BOOLEAN)) {
    $platformAccess = true;
}
if (!$platformAccess) {
    foreach ($platformPermissions as $permission) {
        if (is_string($permission) && str_starts_with($permission, 'platform.')) {
            $platformAccess = true;
            break;
        }
    }
}
if (!$platformAccess) {
    $platformRoleNames = ['platform_super_admin', 'platform_finance', 'platform_operations', 'platform_support'];
    foreach ($platformRoles as $role) {
        if (is_string($role) && in_array($role, $platformRoleNames, true)) {
            $platformAccess = true;
            break;
        }
    }
}

$supportEmail = 'hello@dashpass.africa';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="state-page<?= $platformAccess ? ' state-page--platform' : '' ?>">
  <span class="state-page__icon" aria-hidden="true"><?= $icon($platformAccess ? 'shield' : 'building') ?></span>
  <?php if ($platformAccess): ?>
    <span class="status status--active">Platform operator</span>

    <h1>Your platform account is ready</h1>
    <p class="state-page__copy">
      This account manages <?= e((string) config('app.name', 'MtaaPass')) ?> at the platform level, so it does
      not need an estate household membership. Use the platform portal to review estates, plans,
      subscriptions and billing.
    </p>

    <div class="state-page__actions">
      <a class="btn btn--primary btn--lg btn--block" href="<?= e('/platform') ?>">
        <?= $icon('shield', 'ico ico--sm') ?>
        Go to platform portal
      </a>
      <form method="post" action="<?= e('/logout') ?>" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--outline btn--lg btn--block">
          <?= $icon('logout', 'ico ico--sm') ?>
          Sign out
        </button>
      </form>
    </div>

    <p class="text-sm muted">
      Looking for a resident dashboard? Sign in with the estate-registered number instead, or contact
      <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.
    </p>
  <?php else: ?>
    <span class="status status--pending">No active estate</span>

    <h1>Your account is not linked to an estate yet</h1>
    <p class="state-page__copy">
      Sign-in worked, but there is no active estate membership attached to this phone
      number. Your estate administrator links residents to a household, once they
      add you, sign in again and your estate will appear.
    </p>

    <div class="state-page__actions">
      <a class="btn btn--primary btn--lg btn--block" href="<?= e('/app') ?>">Try again</a>
      <form method="post" action="<?= e('/logout') ?>" data-logout>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--outline btn--lg btn--block">
          <?= $icon('logout', 'ico ico--sm') ?>
          Sign out
        </button>
      </form>
    </div>

    <p class="text-sm muted">
      Need help? Contact your estate administrator or
      <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.
    </p>
  <?php endif; ?>
</div>
