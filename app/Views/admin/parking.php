<?php

declare(strict_types=1);

/**
 * Admin parking overview (parking-administration contract §3, phase 1).
 *
 * Real estate-wide parking stats (zones, bays by state, active allocations,
 * open violations) plus permission-filtered links into the management screens.
 * Absent stats render an honest empty state rather than invented numbers.
 *
 * Variables (T60 frozen keys, consumed defensively): $stats {zones, bays,
 * bays_free|free_bays, bays_occupied|occupied_bays, bays_maintenance,
 * allocations_active|active_allocations, violations_open|open_violations}
 * + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$stats = isset($stats) && is_array($stats) ? $stats : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $permission): bool => in_array($permission, array_map('strval', $permissions), true);

$statValue = static function (array $keys, array $stats): ?int {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $stats)) {
            continue;
        }
        $value = $stats[$key];
        if (is_array($value)) {
            return count($value);
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
    }

    return null;
};
$zonesCount = $statValue(['zones', 'zone_count', 'total_zones'], $stats);
$baysCount = $statValue(['bays', 'bay_count', 'total_bays'], $stats);
$freeBays = $statValue(['free_bays', 'bays_free', 'available_bays'], $stats);
$occupiedBays = $statValue(['occupied_bays', 'bays_occupied', 'allocated_bays'], $stats);
$maintenanceBays = $statValue(['maintenance_bays', 'bays_maintenance'], $stats);
$activeAllocations = $statValue(['active_allocations', 'allocations_active'], $stats);
$openViolations = $statValue(['open_violations', 'violations_open'], $stats);

$statCards = [
    ['label' => 'Zones', 'value' => $zonesCount, 'icon' => 'grid'],
    ['label' => 'Bays', 'value' => $baysCount, 'icon' => 'parking'],
    ['label' => 'Free bays', 'value' => $freeBays, 'icon' => 'check'],
    ['label' => 'Occupied bays', 'value' => $occupiedBays, 'icon' => 'car'],
    ['label' => 'Maintenance', 'value' => $maintenanceBays, 'icon' => 'info'],
    ['label' => 'Active allocations', 'value' => $activeAllocations, 'icon' => 'home'],
    ['label' => 'Open violations', 'value' => $openViolations, 'icon' => 'alert'],
];
$hasStats = false;
foreach ($statCards as $card) {
    if ($card['value'] !== null) {
        $hasStats = true;
        break;
    }
}

$links = [
    ['href' => '/admin/parking/zones', 'title' => 'Zones', 'icon' => 'grid', 'text' => 'Create and edit parking zones for the estate.', 'permission' => 'parking.view'],
    ['href' => '/admin/parking/bays', 'title' => 'Bays', 'icon' => 'parking', 'text' => 'Add bays, move them between zones and archive unavailable bays.', 'permission' => 'parking.view'],
    ['href' => '/admin/parking/allocations', 'title' => 'Allocations', 'icon' => 'home', 'text' => 'Assign bays to households and end allocations.', 'permission' => 'parking.view'],
    ['href' => '/admin/parking/violations', 'title' => 'Violations', 'icon' => 'alert', 'text' => 'Review parking violations and resolve or dismiss them.', 'permission' => 'parking.violation.manage'],
];
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Parking</h1>
    <p class="admin-page__lede">
      Estate parking administration: zones, bays, resident allocations and parking violations.
    </p>
  </div>
</div>

<nav class="admin-subnav" aria-label="Parking sections">
  <a class="admin-subnav__link is-active" href="/admin/parking" aria-current="page">Overview</a>
  <?php if ($can('parking.view')): ?>
    <a class="admin-subnav__link" href="/admin/parking/zones">Zones</a>
    <a class="admin-subnav__link" href="/admin/parking/bays">Bays</a>
    <a class="admin-subnav__link" href="/admin/parking/allocations">Allocations</a>
  <?php endif; ?>
  <?php if ($can('parking.violation.manage')): ?>
    <a class="admin-subnav__link" href="/admin/parking/violations">Violations</a>
  <?php endif; ?>
</nav>

<?php if ($hasStats): ?>
  <div class="admin-stats admin-stats--parking">
    <?php foreach ($statCards as $card): ?>
      <?php if ($card['value'] === null) {
          continue;
      } ?>
      <div class="admin-stat">
        <span class="admin-stat__icon admin-stat__icon--parking" aria-hidden="true"><?= admin_icon($card['icon']) ?></span>
        <span class="admin-stat__value"><?= e((string) $card['value']) ?></span>
        <span class="admin-stat__label"><?= e($card['label']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="admin-empty">
    <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('parking') ?></span>
    <p class="admin-empty__title">No parking data available yet</p>
    <p class="admin-empty__text">Parking stats appear once this estate has zones and bays set up.</p>
  </div>
<?php endif; ?>

<section class="admin-section" aria-labelledby="parking-manage-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="parking-manage-title">Manage parking</h2>
  </div>

  <div class="admin-links">
    <?php foreach ($links as $link): ?>
      <?php if (!$can($link['permission'])) {
          continue;
      } ?>
      <a class="admin-link-card" href="<?= e($link['href']) ?>">
        <span class="admin-link-card__icon" aria-hidden="true"><?= admin_icon($link['icon']) ?></span>
        <span class="admin-link-card__body">
          <span class="admin-link-card__title"><?= e($link['title']) ?></span>
          <span class="admin-link-card__text"><?= e($link['text']) ?></span>
        </span>
        <?= admin_icon('chevron', 'ico ico--sm') ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$can('parking.view')): ?>
    <p class="admin-note"><?= admin_icon('lock', 'ico ico--sm') ?> You do not have permission to view parking management screens.</p>
  <?php endif; ?>
</section>
