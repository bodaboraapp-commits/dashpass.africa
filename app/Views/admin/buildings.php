<?php

declare(strict_types=1);

/**
 * Admin buildings list + add form (contract §3, §7).
 *
 * Variables: $buildings [{uuid,name,building_type,household_count,created_at}],
 * $building_types [{value,label}], $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$buildings = isset($buildings) && is_array($buildings) ? $buildings : [];
$buildingTypes = isset($building_types) && is_array($building_types) ? $building_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'household.manage');

$typeLabels = [];
foreach ($buildingTypes as $type) {
    if (is_array($type) && isset($type['value'])) {
        $typeLabels[(string) $type['value']] = (string) ($type['label'] ?? admin_humanize((string) $type['value']));
    }
}
if ($typeLabels === []) {
    $typeLabels = ['apartment' => 'Apartment', 'maisonette' => 'Maisonette', 'block' => 'Block', 'other' => 'Other'];
}
$typeLabel = static fn (string $value): string => $typeLabels[$value] ?? admin_humanize($value);
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Buildings</h1>
    <p class="admin-page__lede">Blocks and buildings on the estate, with the number of households attached to each.</p>
  </div>
</div>

<section class="admin-section" aria-labelledby="buildings-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="buildings-list-title">Estate buildings</h2>
    <?php if ($buildings !== []): ?>
      <span class="chip"><?= e((string) count($buildings)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($buildings !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Type</th>
            <th scope="col">Households</th>
            <th scope="col">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($buildings as $building): ?>
            <?php
            if (!is_array($building)) {
                continue;
            }
            $buildingUuid = trim((string) ($building['uuid'] ?? ''));
            $name = trim((string) ($building['name'] ?? ''));
            $buildingType = trim((string) ($building['building_type'] ?? ''));
            $householdCount = isset($building['household_count']) ? max(0, (int) $building['household_count']) : 0;
            $createdAt = isset($building['created_at']) ? (string) $building['created_at'] : '';
            ?>
            <tr>
              <td data-label="Name">
                <?php if ($buildingUuid !== ''): ?>
                  <a class="admin-table__link" href="/admin/buildings/<?= e(rawurlencode($buildingUuid)) ?>"><?= e($name !== '' ? $name : 'Building') ?></a>
                <?php else: ?>
                  <?= e($name !== '' ? $name : 'Building') ?>
                <?php endif; ?>
              </td>
              <td data-label="Type"><?= e($typeLabel($buildingType)) ?></td>
              <td data-label="Households"><?= e((string) $householdCount) ?></td>
              <td data-label="Created"><?= e(admin_datetime($createdAt, $timezone, 'd M Y')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('building') ?></span>
      <p class="admin-empty__title">No buildings yet</p>
      <p class="admin-empty__text">Add the estate&rsquo;s first building below. Households can be assigned to it afterwards.</p>
    </div>
  <?php endif; ?>

  <p class="admin-note">
    <?= admin_icon('info', 'ico ico--sm') ?>
    Buildings can be renamed and retyped, but cannot be archived in this release (the data model has no building
    status). Remove or re-assign all households first if a building should no longer be used.
  </p>
</section>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="add-building-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-building-title">Add a building</h2>
      <p class="admin-section__hint">Names are unique per estate.</p>
    </div>
  </div>

  <form method="post" action="/admin/buildings" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'name') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="building_name">
          Building name
          <span class="muted text-xs">Required</span>
        </label>
        <input class="input<?= admin_field_error($errors, 'name') !== '' ? ' input--invalid' : '' ?>"
               id="building_name" name="name" type="text" maxlength="120" required
               value="<?= e(admin_old($old, 'name')) ?>" placeholder="Block A">
        <?php if (admin_field_error($errors, 'name') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'building_type') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="building_type">Building type</label>
        <select class="select<?= admin_field_error($errors, 'building_type') !== '' ? ' input--invalid' : '' ?>"
                id="building_type" name="building_type" required>
          <?php foreach ($typeLabels as $typeValue => $typeText): ?>
            <option value="<?= e((string) $typeValue) ?>" <?= admin_old($old, 'building_type') === (string) $typeValue ? 'selected' : '' ?>>
              <?= e($typeText) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'building_type') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'building_type')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add building</button>
    </div>
  </form>
</section>
<?php endif; ?>
