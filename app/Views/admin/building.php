<?php

declare(strict_types=1);

/**
 * Admin building detail + edit (contract §3, §7).
 *
 * Variables: $building {uuid,name,building_type,created_at},
 * $households [{uuid,unit_number,household_type,status,status_label,status_tone,member_count}],
 * $building_types, $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$building = isset($building) && is_array($building) ? $building : [];
$households = isset($households) && is_array($households) ? $households : [];
$buildingTypes = isset($building_types) && is_array($building_types) ? $building_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'household.manage');

$buildingUuid = trim((string) ($building['uuid'] ?? ''));
$name = trim((string) ($building['name'] ?? ''));
$buildingType = trim((string) ($building['building_type'] ?? ''));
$createdAt = isset($building['created_at']) ? (string) $building['created_at'] : '';

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

$householdTypeLabels = [
    'apartment' => 'Apartment',
    'maisonette' => 'Maisonette',
    'other' => 'Other',
];
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/buildings">Buildings</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($name !== '' ? $name : 'Building') ?></span>
    </nav>
    <h1 class="admin-page__title"><?= e($name !== '' ? $name : 'Building') ?></h1>
    <p class="admin-page__lede">
      <?= e($typeLabel($buildingType)) ?>
      <?php if ($createdAt !== ''): ?>
        · added <?= e(admin_datetime($createdAt, $timezone, 'd M Y')) ?>
      <?php endif; ?>
      · <?= e((string) count($households)) ?> household<?= count($households) === 1 ? '' : 's' ?>
    </p>
  </div>
</div>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="edit-building-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="edit-building-title">Building details</h2>
      <p class="admin-section__hint">Rename the building or change its type.</p>
    </div>
  </div>

  <form method="post" action="/admin/buildings/<?= e(rawurlencode($buildingUuid)) ?>" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'name') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="building_name">Building name</label>
        <input class="input<?= admin_field_error($errors, 'name') !== '' ? ' input--invalid' : '' ?>"
               id="building_name" name="name" type="text" maxlength="120" required
               value="<?= e(admin_old($old, 'name', $name)) ?>">
        <?php if (admin_field_error($errors, 'name') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'building_type') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="building_type">Building type</label>
        <select class="select" id="building_type" name="building_type" required>
          <?php foreach ($typeLabels as $typeValue => $typeText): ?>
            <?php $selected = admin_old($old, 'building_type', $buildingType) === (string) $typeValue; ?>
            <option value="<?= e((string) $typeValue) ?>" <?= $selected ? 'selected' : '' ?>><?= e($typeText) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'building_type') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'building_type')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save changes</button>
      <a class="btn btn--ghost" href="/admin/buildings">Back to buildings</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="building-households-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="building-households-title">Households in this building</h2>
      <p class="admin-section__hint">Open a household to manage its details and members.</p>
    </div>
    <a class="btn btn--outline btn--sm" href="/admin/households"><?= admin_icon('home', 'ico ico--sm') ?> All households</a>
  </div>

  <?php if ($households !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Unit</th>
            <th scope="col">Type</th>
            <th scope="col">Status</th>
            <th scope="col">Members</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($households as $household): ?>
            <?php
            if (!is_array($household)) {
                continue;
            }
            $householdUuid = trim((string) ($household['uuid'] ?? ''));
            $unit = trim((string) ($household['unit_number'] ?? ''));
            $type = trim((string) ($household['household_type'] ?? ''));
            $status = trim((string) ($household['status'] ?? 'active'));
            $statusLabel = trim((string) ($household['status_label'] ?? '')) ?: admin_humanize($status);
            $statusTone = trim((string) ($household['status_tone'] ?? ''));
            $memberCount = isset($household['member_count']) ? max(0, (int) $household['member_count']) : 0;
            ?>
            <tr>
              <td data-label="Unit">
                <?php if ($householdUuid !== ''): ?>
                  <a class="admin-table__link" href="/admin/households/<?= e(rawurlencode($householdUuid)) ?>"><?= e($unit !== '' ? $unit : 'Household') ?></a>
                <?php else: ?>
                  <?= e($unit !== '' ? $unit : 'Household') ?>
                <?php endif; ?>
              </td>
              <td data-label="Type"><?= e($householdTypeLabels[$type] ?? admin_humanize($type)) ?></td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Members"><?= e((string) $memberCount) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('home') ?></span>
      <p class="admin-empty__title">No households in this building yet</p>
      <p class="admin-empty__text">Create a household and assign it to <?= e($name !== '' ? $name : 'this building') ?>.</p>
      <a class="btn btn--outline" href="/admin/households">Go to households</a>
    </div>
  <?php endif; ?>
</section>
