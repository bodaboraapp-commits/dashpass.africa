<?php

declare(strict_types=1);

/**
 * Admin parking zones (parking-administration contract §3, phase 1).
 *
 * List + create + inline edit for estate parking zones. Zones have no archive
 * semantics in this increment (no status column), documented in the page note.
 *
 * Variables (T60 frozen keys, defensive): $zones [], $zone_types [], $errors [],
 * $old [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$zones = isset($zones) && is_array($zones) ? $zones : [];
$zoneTypes = isset($zone_types) && is_array($zone_types) ? $zone_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $permission): bool => in_array($permission, array_map('strval', $permissions), true);
$canManage = $can('parking.bay.manage');

$optionMap = static function (array $items, array $fallback): array {
    $out = [];
    foreach ($items as $item) {
        if (is_string($item) && trim($item) !== '') {
            $out[trim($item)] = admin_humanize(trim($item));
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item['value'] ?? $item['uuid'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item['label'] ?? ''));
        $out[$value] = $label !== '' ? $label : admin_humanize($value);
    }

    return $out !== [] ? $out : $fallback;
};
$zoneTypeMap = $optionMap($zoneTypes, [
    'resident' => 'Resident',
    'visitor' => 'Visitor',
    'mixed' => 'Mixed',
    'staff' => 'Staff',
]);
$oldValue = static function (string $field) use ($old): string {
    $candidate = $old[$field] ?? '';

    return is_scalar($candidate) ? trim((string) $candidate) : '';
};
$fieldError = static fn (string $field): string => admin_field_error($errors, $field);
$rows = array_values(array_filter($zones, 'is_array'));
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/parking">Parking</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Zones</span>
    </nav>
    <h1 class="admin-page__title">Parking zones</h1>
    <p class="admin-page__lede">Group the estate's parking bays into zones for residents, visitors and staff.</p>
  </div>
</div>

<nav class="admin-subnav" aria-label="Parking sections">
  <a class="admin-subnav__link" href="/admin/parking">Overview</a>
  <a class="admin-subnav__link is-active" href="/admin/parking/zones" aria-current="page">Zones</a>
  <?php if ($can('parking.view')): ?>
    <a class="admin-subnav__link" href="/admin/parking/bays">Bays</a>
    <a class="admin-subnav__link" href="/admin/parking/allocations">Allocations</a>
  <?php endif; ?>
  <?php if ($can('parking.violation.manage')): ?>
    <a class="admin-subnav__link" href="/admin/parking/violations">Violations</a>
  <?php endif; ?>
</nav>

<?= admin_summary($errors) ?>

<?php if ($canManage): ?>
  <section class="admin-section" aria-labelledby="zone-add-title">
    <div class="admin-section__head">
      <h2 class="admin-section__title" id="zone-add-title">Add a zone</h2>
    </div>

    <form method="post" action="/admin/parking/zones" class="admin-form card admin-form--inline">
      <?= csrf_field() ?>
      <div class="admin-form__grid admin-form__grid--inline">
        <div class="admin-field<?= $fieldError('name') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="zone_name">Zone name</label>
          <input class="input<?= $fieldError('name') !== '' ? ' input--invalid' : '' ?>" id="zone_name" name="name"
                 type="text" maxlength="80" required value="<?= e($oldValue('name')) ?>" placeholder="e.g. Block A basement">
          <?php if ($fieldError('name') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('name')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('zone_type') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="zone_type">Zone type</label>
          <select class="select" id="zone_type" name="zone_type" required>
            <?php $selectedType = $oldValue('zone_type'); ?>
            <?php foreach ($zoneTypeMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('zone_type') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('zone_type')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-form__actions">
          <button type="submit" class="btn btn--primary btn--sm"><?= admin_icon('plus', 'ico ico--sm') ?> Add zone</button>
        </div>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="zones-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="zones-list-title">Zones</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Zone</th>
            <th scope="col">Type</th>
            <th scope="col">Bays</th>
            <?php if ($canManage): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $zone): ?>
            <?php
            $zoneUuid = trim((string) ($zone['uuid'] ?? ''));
            $name = trim((string) ($zone['name'] ?? 'Zone'));
            $zoneType = trim((string) ($zone['zone_type'] ?? ''));
            $zoneTypeLabel = trim((string) ($zone['zone_type_label'] ?? '')) ?: ($zoneTypeMap[$zoneType] ?? admin_humanize($zoneType));
            $baysCount = $zone['bays_count'] ?? ($zone['bay_count'] ?? null);
            $baysText = is_numeric($baysCount) ? (string) (int) $baysCount : '-';
            ?>
            <tr>
              <td data-label="Zone"><?= e($name) ?></td>
              <td data-label="Type"><?= e($zoneTypeLabel !== '' ? $zoneTypeLabel : '-') ?></td>
              <td data-label="Bays"><?= e($baysText) ?></td>
              <?php if ($canManage): ?>
                <td data-label="Actions">
                  <?php if ($zoneUuid !== ''): ?>
                    <details class="admin-inline-edit">
                      <summary class="btn btn--ghost btn--sm">Edit</summary>
                      <form method="post" action="/admin/parking/zones/<?= e(rawurlencode($zoneUuid)) ?>" class="admin-inline-edit__form">
                        <?= csrf_field() ?>
                        <div class="admin-field">
                          <label class="admin-field__label" for="zone_name_<?= e($zoneUuid) ?>">Name</label>
                          <input class="input" id="zone_name_<?= e($zoneUuid) ?>" name="name" type="text" maxlength="80" required value="<?= e($name) ?>">
                        </div>
                        <div class="admin-field">
                          <label class="admin-field__label" for="zone_type_<?= e($zoneUuid) ?>">Type</label>
                          <select class="select" id="zone_type_<?= e($zoneUuid) ?>" name="zone_type" required>
                            <?php foreach ($zoneTypeMap as $value => $label): ?>
                              <option value="<?= e((string) $value) ?>" <?= $zoneType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <button type="submit" class="btn btn--outline btn--sm">Save zone</button>
                      </form>
                    </details>
                  <?php else: ?>

                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('grid') ?></span>
      <p class="admin-empty__title">No parking zones yet</p>
      <p class="admin-empty__text">Add a zone first, then create bays inside it.</p>
      <?php if ($canManage): ?>
        <a class="btn btn--primary" href="#zone_name"><?= admin_icon('plus', 'ico ico--sm') ?> Add a zone</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <p class="admin-note">
    <?= admin_icon('info', 'ico ico--sm') ?>
    Zones cannot be archived in this release, bays can be moved between zones or archived individually.
  </p>
</section>
