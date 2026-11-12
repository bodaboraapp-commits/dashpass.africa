<?php

declare(strict_types=1);

/**
 * Estate screen (app layout).
 *
 * Shows, in order:
 *  1. "Current estate" details card — role, estate type/timezone, household(s)
 *     with the primary chip, assigned gates with status chips and the plan
 *     (moved here from Home in T97). Data comes from the T96 `$dashboard`
 *     payload with defensive fallbacks to the shell estate/membership.
 *  2. The existing estate switcher list (only estates the user belongs to).
 *
 * Variables: shell data + optional `dashboard` {estate, households, gates},
 * `memberships`, `preview`. Everything is defensive: partial integration data
 * must still render without notices.
 */

$memberships = isset($memberships) && is_array($memberships) ? $memberships : [];
$shellEstate = isset($estate) && is_array($estate) ? $estate : [];
$activeUuid = trim((string) ($shellEstate['estate_uuid'] ?? ''));
$activeName = trim((string) ($shellEstate['estate_name'] ?? ''));

$dashboard = isset($dashboard) && is_array($dashboard) ? $dashboard : [];
$summary = isset($dashboard['estate']) && is_array($dashboard['estate']) ? $dashboard['estate'] : [];
$households = isset($dashboard['households']) && is_array($dashboard['households']) ? $dashboard['households'] : [];
$gates = isset($dashboard['gates']) && is_array($dashboard['gates']) ? $dashboard['gates'] : [];
$preview = (bool) ($preview ?? false);

$activeMembership = null;
foreach ($memberships as $membership) {
    if (!is_array($membership)) {
        continue;
    }
    $membershipUuid = trim((string) ($membership['estate_uuid'] ?? ''));
    if ($activeUuid !== '' && $membershipUuid !== '' && hash_equals($activeUuid, $membershipUuid)) {
        $activeMembership = $membership;
        break;
    }
}

$estateName = trim((string) ($summary['estate_name'] ?? $activeName));
if ($estateName === '' && is_array($activeMembership)) {
    $estateName = trim((string) ($activeMembership['estate_name'] ?? ''));
}
$roleName = trim((string) ($summary['role_name'] ?? $shellEstate['role_name'] ?? ''));
if ($roleName === '' && is_array($activeMembership)) {
    $roleName = trim((string) ($activeMembership['role_name'] ?? ''));
}
$estateType = trim((string) ($summary['estate_type'] ?? $shellEstate['estate_type'] ?? ''));
if ($estateType === '' && is_array($activeMembership)) {
    $estateType = trim((string) ($activeMembership['estate_type'] ?? ''));
}
$timezone = trim((string) ($summary['estate_timezone'] ?? $shellEstate['estate_timezone'] ?? $shellEstate['timezone'] ?? ''));
if ($timezone === '' && is_array($activeMembership)) {
    $timezone = trim((string) ($activeMembership['estate_timezone'] ?? ''));
}
$planName = trim((string) ($summary['plan_name'] ?? ''));
$hasCurrentEstate = $estateName !== '';

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};
$roleLabel = $humanise($roleName) !== '' ? $humanise($roleName) : 'Resident';
$typeLabel = $humanise($estateType) !== '' ? $humanise($estateType) : 'Estate';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Your estates</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <section class="resident-section" aria-labelledby="details-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="details-title">Current estate</h2>
        <p class="resident-section__hint">
          The estate your dashboard shows right now. Household and gate details are managed by your estate administrator.
        </p>
      </div>
    </div>

    <?php if ($hasCurrentEstate): ?>
      <div class="detail-card">
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('shield', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Your role</span>
          <span class="detail-line__value"><?= e($roleLabel) ?></span>
        </div>
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Estate</span>
          <span class="detail-line__value"><?= e($typeLabel) ?><?php if ($timezone !== ''): ?> · <?= e($timezone) ?><?php endif; ?></span>
        </div>

        <?php if ($households !== []): ?>
          <?php foreach ($households as $household): ?>
            <?php
            if (!is_array($household)) {
                continue;
            }
            $unit = trim((string) ($household['unit_number'] ?? ''));
            $building = trim((string) ($household['building_name'] ?? ''));
            $relationship = trim((string) ($household['relationship'] ?? ''));
            $isPrimary = (bool) ($household['is_primary'] ?? false);
            ?>
            <div class="detail-line">
              <span class="detail-line__icon" aria-hidden="true"><?= $icon('home', 'ico ico--sm') ?></span>
              <span class="detail-line__label">Your household</span>
              <span class="detail-line__value">
                <?= e($unit !== '' ? $unit : 'Linked unit') ?>
                <?php if ($building !== ''): ?> · <?= e($building) ?><?php endif; ?>
                <?php if ($relationship !== ''): ?> · <?= e($humanise($relationship)) ?><?php endif; ?>
                <?php if ($isPrimary): ?> <span class="chip chip--accent">Primary</span><?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="detail-line">
            <span class="detail-line__icon" aria-hidden="true"><?= $icon('home', 'ico ico--sm') ?></span>
            <span class="detail-line__label">Your household</span>
            <span class="detail-line__value">No household linked yet</span>
          </div>
        <?php endif; ?>

        <?php foreach ($gates as $gate): ?>
          <?php
          if (!is_array($gate)) {
              continue;
          }
          $gateName = trim((string) ($gate['name'] ?? ''));
          $gateStatus = trim((string) ($gate['status'] ?? 'inactive'));
          ?>
          <div class="detail-line">
            <span class="detail-line__icon" aria-hidden="true"><?= $icon('gate', 'ico ico--sm') ?></span>
            <span class="detail-line__label">Gate</span>
            <span class="detail-line__value">
              <?= e($gateName !== '' ? $gateName : 'Gate') ?>
              <span class="status <?= $gateStatus === 'active' ? 'status--active' : 'status--inactive' ?>">
                <?= e($humanise($gateStatus) !== '' ? $humanise($gateStatus) : 'Unknown') ?>
              </span>
            </span>
          </div>
        <?php endforeach; ?>

        <?php if ($preview): ?>
          <p class="detail-card__note">Demo estate details shown for layout review.</p>
        <?php endif; ?>

        <?php if (count($memberships) > 1): ?>
          <div class="detail-line">
            <span class="detail-line__icon" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
            <span class="detail-line__label">Estates</span>
            <span class="detail-line__value"><?= e((string) count($memberships)) ?> memberships available below</span>
          </div>
        <?php endif; ?>

        <?php if ($planName !== ''): ?>
          <div class="detail-line">
            <span class="detail-line__icon" aria-hidden="true"><?= $icon('sparkle', 'ico ico--sm') ?></span>
            <span class="detail-line__label">Plan</span>
            <span class="detail-line__value"><?= e($planName) ?></span>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="empty">
          <span class="empty__icon" aria-hidden="true"><?= $icon('building') ?></span>
          <p class="empty__title">No current estate</p>
          <p class="empty__text">Pick one of your estates below to view its household and gate details.</p>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="stack" aria-labelledby="membership-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="membership-title">Your estates</h2>
      </div>
    </div>
    <p class="resident-section__hint">Memberships are managed by your estate administrator.</p>

    <p class="page-lede">
      <?php if (count($memberships) > 1): ?>
        You belong to <?= e((string) count($memberships)) ?> estates. Switching changes the estate
        your dashboard shows — it never changes what you are allowed to access.
      <?php else: ?>
        You belong to one estate. When you are invited to another, it will appear here.
      <?php endif; ?>
    </p>

  <?php if ($memberships !== []): ?>
    <div class="estate-grid">
      <?php foreach ($memberships as $membership): ?>
        <?php
        if (!is_array($membership)) {
            continue;
        }
        $estateUuid = trim((string) ($membership['estate_uuid'] ?? ''));
        $estateName = trim((string) ($membership['estate_name'] ?? ''));
        $estateSlug = trim((string) ($membership['estate_slug'] ?? ''));
        $estateType = trim((string) ($membership['estate_type'] ?? ''));
        $roleName = trim((string) ($membership['role_name'] ?? ''));
        $estateTimezone = trim((string) ($membership['estate_timezone'] ?? ''));
        $membershipStatus = trim((string) ($membership['membership_status'] ?? 'active'));
        $isActive = $activeUuid !== '' && $estateUuid !== '' && hash_equals($activeUuid, $estateUuid);
        ?>
        <article class="estate-card<?= $isActive ? ' estate-card--active' : '' ?>">
          <div class="estate-card__head">
            <span class="estate-card__mark" aria-hidden="true"><?= $icon('building') ?></span>
            <div class="estate-card__body">
              <h3 class="estate-card__name"><?= e($estateName !== '' ? $estateName : 'Estate') ?></h3>
              <p class="estate-card__slug"><?= e($estateSlug !== '' ? $estateSlug : 'estate') ?></p>
            </div>
            <?php if ($isActive): ?>
              <span class="chip chip--on-dark">Current</span>
            <?php else: ?>
              <span class="status <?= $membershipStatus === 'active' ? 'status--active' : 'status--inactive' ?>">
                <?= e($humanise($membershipStatus)) ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="estate-card__meta">
            <div class="estate-card__meta-item">
              <span class="estate-card__meta-label">Role</span>
              <span class="estate-card__meta-value"><?= e($humanise($roleName) !== '' ? $humanise($roleName) : '—') ?></span>
            </div>
            <div class="estate-card__meta-item">
              <span class="estate-card__meta-label">Type</span>
              <span class="estate-card__meta-value"><?= e($humanise($estateType) !== '' ? $humanise($estateType) : '—') ?></span>
            </div>
            <div class="estate-card__meta-item">
              <span class="estate-card__meta-label">Timezone</span>
              <span class="estate-card__meta-value"><?= e($estateTimezone !== '' ? $estateTimezone : '—') ?></span>
            </div>
            <div class="estate-card__meta-item">
              <span class="estate-card__meta-label">Access</span>
              <span class="estate-card__meta-value"><?= $isActive ? 'Active' : 'Available' ?></span>
            </div>
          </div>

          <div class="estate-card__foot">
            <?php if ($isActive): ?>
              <span class="status status--active">Active estate</span>
              <a class="btn btn--primary btn--sm" href="<?= e('/app') ?>">
                Open dashboard
                <?= $icon('chevron', 'ico ico--sm') ?>
              </a>
            <?php else: ?>
              <span class="row__sub"><?= e($estateName !== '' ? 'Switch to ' . $estateName : 'Switch estate') ?></span>
              <form method="post"
                    action="/app/estates/<?= e(rawurlencode($estateUuid)) ?>/switch"
                    data-estate-switch>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary btn--sm">
                  Switch estate
                  <?= $icon('chevron', 'ico ico--sm') ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="empty">
        <span class="empty__icon" aria-hidden="true"><?= $icon('building') ?></span>
        <p class="empty__title">No estate memberships yet</p>
        <p class="empty__text">
          Your account is active, but no estate is linked to this phone number yet.
          Ask your estate administrator to add you to a household.
        </p>
        <a class="btn btn--outline" href="<?= e('/app/no-estate') ?>">Learn what happens next</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="alert alert--info" role="note">
    <?= $icon('shield') ?>
    <div class="alert__body">
      <p class="alert__title">Tenant isolation is enforced server-side</p>
      <p>
        Changing the estate in a link or request never grants access to another estate.
        <?= e((string) config('app.name', 'MtaaPass')) ?> re-checks your membership from the database on every request.
      </p>
    </div>
  </div>
  </section>
</div>
