<?php

declare(strict_types=1);

/**
 * Resident estate wayfinding page (Inc-13 Nav).
 *
 * Renders the module-provided navigation payload defensively:
 * $navigation.estate {name,type,type_label,timezone}
 * $navigation.gates [{name,status,status_label,location}]
 * $navigation.buildings [{name,type,type_label,households_count}]
 * $navigation.notes [string]
 */

$nav = is_array($navigation ?? null) ? $navigation : [];
$navEstate = is_array($nav['estate'] ?? null) ? $nav['estate'] : [];
$navGates = is_array($nav['gates'] ?? null) ? array_values(array_filter($nav['gates'], 'is_array')) : [];
$navBuildings = is_array($nav['buildings'] ?? null) ? array_values(array_filter($nav['buildings'], 'is_array')) : [];
$navNotes = is_array($nav['notes'] ?? null) ? array_values(array_filter($nav['notes'], 'is_string')) : [];
$shellEstate = is_array($estate ?? null) ? $estate : [];
$navEstateName = trim((string) ($navEstate['name'] ?? ($shellEstate['estate_name'] ?? '')));
if ($navEstateName === '') {
    $navEstateName = 'Your estate';
}
$navEstateType = trim((string) ($navEstate['type_label'] ?? ''));
$navEstateTimezone = trim((string) ($navEstate['timezone'] ?? ($shellEstate['estate_timezone'] ?? '')));
$navIcon = static fn (string $name, string $class = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $class]
);
$navGateStatusClass = static function (string $status): string {
    return match ($status) {
        'active' => 'status--active',
        'inactive', 'archived' => 'status--inactive',
        default => 'status--pending',
    };
};
?>
<div class="resident navigation-page">
  <header class="screen-bar navigation-head">
    <a class="screen-bar__action" href="<?= e('/app') ?>" aria-label="Back to home" title="Back to home">
      <?= $navIcon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title navigation-head__title">
      <span class="navigation-head__icon" aria-hidden="true"><?= $navIcon('nav', 'ico') ?></span>
      WayFinder
    </h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <p class="navigation-lede">
    Find gates, buildings and blocks around <?= e($navEstateName) ?>. Directions are indicative, 
    follow on-site signage and gate staff instructions.
  </p>

  <section class="card navigation-estate" aria-labelledby="navigation-estate-name">
    <span class="navigation-estate__icon" aria-hidden="true"><?= $navIcon('nav', 'ico') ?></span>
    <div class="navigation-estate__body">
      <p class="navigation-estate__eyebrow">Estate wayfinding</p>
      <h2 class="navigation-estate__name" id="navigation-estate-name"><?= e($navEstateName) ?></h2>
      <p class="navigation-estate__meta">
        <?php if ($navEstateType !== ''): ?><span><?= e($navEstateType) ?></span><?php endif; ?>
        <?php if ($navEstateTimezone !== ''): ?><span><?= e($navEstateTimezone) ?></span><?php endif; ?>
        <span><?= e((string) count($navGates)) ?> <?= count($navGates) === 1 ? 'gate' : 'gates' ?></span>
        <span><?= e((string) count($navBuildings)) ?> <?= count($navBuildings) === 1 ? 'building' : 'buildings' ?></span>
      </p>
    </div>
  </section>

  <section class="card navigation-section" aria-labelledby="navigation-gates-title">
    <div class="card__head">
      <h2 class="card__title" id="navigation-gates-title"><?= $navIcon('gate', 'ico ico--sm') ?> Gates &amp; entrances</h2>
      <?php if ($navGates !== []): ?><span class="chip"><?= e((string) count($navGates)) ?></span><?php endif; ?>
    </div>
    <?php if ($navGates !== []): ?>
      <ul class="navigation-list">
        <?php foreach ($navGates as $navGate): ?>
          <?php
          $navGateName = trim((string) ($navGate['name'] ?? 'Gate'));
          $navGateStatus = trim((string) ($navGate['status'] ?? ''));
          $navGateStatusLabel = trim((string) ($navGate['status_label'] ?? ''));
          if ($navGateStatusLabel === '') {
              $navGateStatusLabel = $navGateStatus === '' ? 'Status unknown' : ucfirst($navGateStatus);
          }
          $navGateLocation = trim((string) ($navGate['location'] ?? ''));
          ?>
          <li class="navigation-item">
            <span class="navigation-item__icon" aria-hidden="true"><?= $navIcon('gate', 'ico ico--sm') ?></span>
            <div class="navigation-item__body">
              <p class="navigation-item__title"><?= e($navGateName) ?></p>
              <p class="navigation-item__meta"><?= e($navGateLocation !== '' ? $navGateLocation : 'Follow on-site signs or ask gate staff for directions.') ?></p>
            </div>
            <span class="status <?= e($navGateStatusClass($navGateStatus)) ?>"><?= e($navGateStatusLabel) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty navigation-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $navIcon('gate') ?></span>
        <p class="resident-empty__title">No gates listed yet</p>
        <p class="resident-empty__text">Contact the estate office for gate locations and access directions.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="card navigation-section" aria-labelledby="navigation-buildings-title">
    <div class="card__head">
      <h2 class="card__title" id="navigation-buildings-title"><?= $navIcon('building', 'ico ico--sm') ?> Buildings &amp; blocks</h2>
      <?php if ($navBuildings !== []): ?><span class="chip"><?= e((string) count($navBuildings)) ?></span><?php endif; ?>
    </div>
    <?php if ($navBuildings !== []): ?>
      <ul class="navigation-list navigation-buildings">
        <?php foreach ($navBuildings as $navBuilding): ?>
          <?php
          $navBuildingName = trim((string) ($navBuilding['name'] ?? 'Building'));
          $navBuildingType = trim((string) ($navBuilding['type_label'] ?? ''));
          $navHouseholds = max(0, (int) ($navBuilding['households_count'] ?? 0));
          ?>
          <li class="navigation-item">
            <span class="navigation-item__icon" aria-hidden="true"><?= $navIcon('building', 'ico ico--sm') ?></span>
            <div class="navigation-item__body">
              <p class="navigation-item__title"><?= e($navBuildingName) ?></p>
              <p class="navigation-item__meta">
                <?php if ($navBuildingType !== ''): ?><?= e($navBuildingType) ?> · <?php endif; ?>
                <?= e((string) $navHouseholds) ?> active <?= $navHouseholds === 1 ? 'household' : 'households' ?>
              </p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty navigation-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $navIcon('building') ?></span>
        <p class="resident-empty__title">No buildings listed yet</p>
        <p class="resident-empty__text">Contact the estate office for building and block locations.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="card navigation-help" aria-labelledby="navigation-help-title">
    <div class="card__head">
      <h2 class="card__title" id="navigation-help-title"><?= $navIcon('nav', 'ico ico--sm') ?> Need detailed directions?</h2>
    </div>
    <p class="navigation-help__text">For detailed directions, contact the estate office.</p>
    <?php if ($navNotes !== []): ?>
      <ul class="navigation-notes">
        <?php foreach ($navNotes as $navNote): ?>
          <li><?= e($navNote) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="navigation-help__text">Follow on-site signage and gate staff instructions; the estate office can share directions for visitors and deliveries.</p>
    <?php endif; ?>
    <div class="navigation-help__actions">
      <a class="btn btn--ghost" href="/app"><?= $navIcon('nav', 'ico ico--sm') ?> Back to Home</a>
    </div>
  </section>
</div>
