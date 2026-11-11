<?php

declare(strict_types=1);

/**
 * Admin overview (estate-admin-management-contract §3, §7).
 *
 * Real estate counters from the controller plus quick links, visitor
 * reservations and the pending-pass block. No fabricated numbers: when
 * `stats` is absent the page says so.
 *
 * Variables (§7): $stats {buildings, households_active, residents_active,
 * guards_active, gates_active, visitor_reservations_today}, $estate + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$timezone = admin_timezone($estate);

$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$stats = isset($stats) && is_array($stats) ? $stats : null;

$statCards = [
    ['key' => 'buildings', 'label' => 'Buildings', 'icon' => 'building'],
    ['key' => 'households_active', 'label' => 'Active households', 'icon' => 'home'],
    ['key' => 'residents_active', 'label' => 'Residents', 'icon' => 'users'],
    ['key' => 'guards_active', 'label' => 'Guards', 'icon' => 'shield'],
    ['key' => 'gates_active', 'label' => 'Gates', 'icon' => 'gate'],
    ['key' => 'visitor_reservations_today', 'label' => 'Visitor reservations today', 'icon' => 'calendar'],
];

$quickLinkMeta = [
    '/admin/buildings' => ['icon' => 'building', 'text' => 'Blocks and buildings on the estate.'],
    '/admin/households' => ['icon' => 'home', 'text' => 'Units, status and linked members.'],
    '/admin/residents' => ['icon' => 'users', 'text' => 'Estate memberships and access.'],
    '/admin/guards' => ['icon' => 'shield', 'text' => 'Gate staff and their gate assignments.'],
    '/admin/gates' => ['icon' => 'gate', 'text' => 'Entry points and their status.'],
    '/admin/settings' => ['icon' => 'lock', 'text' => 'Allowlisted estate defaults.'],
];

// Prefer the controller's permission-filtered quick links (canonical §7 extra);
// fall back to the static section list with a local permission filter.
$quickLinks = [];
if (isset($quick_links) && is_array($quick_links) && $quick_links !== []) {
    foreach ($quick_links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $href = trim((string) ($link['href'] ?? ''));
        $label = trim((string) ($link['label'] ?? ''));
        if ($href === '' || $label === '') {
            continue;
        }
        $meta = $quickLinkMeta[$href] ?? ['icon' => 'grid', 'text' => 'Estate management.'];
        $quickLinks[] = ['href' => $href, 'label' => $label, 'icon' => $meta['icon'], 'text' => $meta['text']];
    }
}
if ($quickLinks === []) {
    $fallbackLinks = [
        ['href' => '/admin/buildings', 'label' => 'Buildings', 'icon' => 'building', 'text' => 'Blocks and buildings on the estate.', 'can' => 'household.view'],
        ['href' => '/admin/households', 'label' => 'Households', 'icon' => 'home', 'text' => 'Units, status and linked members.', 'can' => 'household.view'],
        ['href' => '/admin/residents', 'label' => 'Residents', 'icon' => 'users', 'text' => 'Estate memberships and access.', 'can' => 'estate.user.manage'],
        ['href' => '/admin/guards', 'label' => 'Guards', 'icon' => 'shield', 'text' => 'Gate staff and their gate assignments.', 'can' => 'guard.manage'],
        ['href' => '/admin/gates', 'label' => 'Gates', 'icon' => 'gate', 'text' => 'Entry points and their status.', 'can' => 'gate.view'],
        ['href' => '/admin/settings', 'label' => 'Settings', 'icon' => 'lock', 'text' => 'Allowlisted estate defaults.', 'can' => 'estate.settings.manage'],
    ];
    foreach ($fallbackLinks as $link) {
        if (admin_can($permissions, (string) $link['can'])) {
            $quickLinks[] = ['href' => $link['href'], 'label' => $link['label'], 'icon' => $link['icon'], 'text' => $link['text']];
        }
    }
}
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Overview</h1>
    <p class="admin-page__lede">
      <?php if ($estateName !== ''): ?>
        Estate operations for <?= e($estateName) ?>.
      <?php else: ?>
        Estate operations for your active estate.
      <?php endif; ?>
      Buildings, households, people and gate access in one place.
    </p>
  </div>
</div>

<?php if ($stats === null): ?>
  <div class="admin-empty">
    <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('grid') ?></span>
    <p class="admin-empty__title">Overview data is unavailable</p>
    <p class="admin-empty__text">Estate counters could not be loaded right now. Try again from the section links below.</p>
  </div>
<?php else: ?>
  <section class="admin-section" aria-labelledby="stats-title">
    <h2 class="admin-section__title" id="stats-title">Estate at a glance</h2>
    <ul class="admin-stats">
      <?php foreach ($statCards as $card): ?>
        <?php $value = isset($stats[$card['key']]) ? max(0, (int) $stats[$card['key']]) : 0; ?>
        <li class="admin-stat">
          <span class="admin-stat__icon" aria-hidden="true"><?= admin_icon((string) $card['icon']) ?></span>
          <span class="admin-stat__body">
            <span class="admin-stat__value"><?= e((string) $value) ?></span>
            <span class="admin-stat__label"><?= e((string) $card['label']) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="quick-links-title">
  <h2 class="admin-section__title" id="quick-links-title">Manage the estate</h2>
  <?php if ($quickLinks === []): ?>
    <div class="admin-empty admin-empty--inline">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('shield') ?></span>
      <div>
        <p class="admin-empty__title">No management sections available</p>
        <p class="admin-empty__text">Your role does not include any admin sections. Contact the estate administrator.</p>
      </div>
    </div>
  <?php else: ?>
  <ul class="admin-quick-grid">
    <?php foreach ($quickLinks as $link): ?>
      <li>
        <a class="admin-quick" href="<?= e($link['href']) ?>">
          <span class="admin-quick__icon" aria-hidden="true"><?= admin_icon((string) $link['icon']) ?></span>
          <span class="admin-quick__body">
            <span class="admin-quick__label"><?= e((string) $link['label']) ?></span>
            <span class="admin-quick__text"><?= e((string) $link['text']) ?></span>
          </span>
          <span class="admin-quick__chevron" aria-hidden="true"><?= admin_icon('chevron', 'ico ico--sm') ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

