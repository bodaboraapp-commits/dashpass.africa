<?php

declare(strict_types=1);

/**
 * Estate switcher (app layout, shell data only).
 * Shows only the estates the authenticated user actually belongs to.
 */

$memberships = isset($memberships) && is_array($memberships) ? $memberships : [];
$shellEstate = isset($estate) && is_array($estate) ? $estate : [];
$activeUuid = trim((string) ($shellEstate['estate_uuid'] ?? ''));
$activeName = trim((string) ($shellEstate['estate_name'] ?? ''));

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

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

  <section class="stack" aria-label="Estate memberships">
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
