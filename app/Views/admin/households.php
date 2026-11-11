<?php

declare(strict_types=1);

/**
 * Admin households list + status filter + add form (contract §3, §7).
 *
 * Variables: $households [{uuid,unit_number,building_name,household_type,status,
 * status_label,status_tone,member_count}], $buildings [{uuid,name}],
 * $household_types [{value,label}], $status_filter ('active'|'inactive'|'all'),
 * $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$households = isset($households) && is_array($households) ? $households : [];
$buildings = isset($buildings) && is_array($buildings) ? $buildings : [];
$householdTypes = isset($household_types) && is_array($household_types) ? $household_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'household.manage');
$statusFilter = isset($status_filter) && in_array($status_filter, ['active', 'inactive', 'all'], true)
    ? $status_filter
    : 'active';

$typeLabels = [];
foreach ($householdTypes as $type) {
    if (is_array($type) && isset($type['value'])) {
        $typeLabels[(string) $type['value']] = (string) ($type['label'] ?? admin_humanize((string) $type['value']));
    }
}
if ($typeLabels === []) {
    $typeLabels = ['apartment' => 'Apartment', 'maisonette' => 'Maisonette', 'other' => 'Other'];
}
$typeLabel = static fn (string $value): string => $typeLabels[$value] ?? admin_humanize($value);

$filters = [
    'active' => ['label' => 'Active', 'href' => '/admin/households?status=active'],
    'inactive' => ['label' => 'Archived', 'href' => '/admin/households?status=inactive'],
    'all' => ['label' => 'All', 'href' => '/admin/households?status=all'],
];

$emptyTitle = match ($statusFilter) {
    'inactive' => 'No archived households',
    'all' => 'No households yet',
    default => 'No active households yet',
};
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Households</h1>
    <p class="admin-page__lede">Units on the estate, their status and the number of members linked to each.</p>
  </div>
</div>

<section class="admin-section" aria-labelledby="households-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="households-list-title">Estate households</h2>
    <?php if ($households !== []): ?>
      <span class="chip"><?= e((string) count($households)) ?></span>
    <?php endif; ?>
  </div>

  <div class="admin-filter" role="group" aria-label="Filter households by status">
    <?php foreach ($filters as $filterKey => $filter): ?>
      <a class="admin-filter__item<?= $statusFilter === $filterKey ? ' admin-filter__item--active' : '' ?>"
         href="<?= e($filter['href']) ?>"
         <?= $statusFilter === $filterKey ? 'aria-current="page"' : '' ?>>
        <?= e($filter['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($households !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Unit</th>
            <th scope="col">Building</th>
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
            $buildingName = trim((string) ($household['building_name'] ?? ''));
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
              <td data-label="Building"><?= e($buildingName !== '' ? $buildingName : '—') ?></td>
              <td data-label="Type"><?= e($typeLabel($type)) ?></td>
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
      <p class="admin-empty__title"><?= e($emptyTitle) ?></p>
      <p class="admin-empty__text">
        <?php if ($statusFilter === 'inactive'): ?>
          Archived households appear here after you set their status to Archived.
        <?php else: ?>
          Add a household below, or switch the filter if you are looking for archived units.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>

  <p class="admin-note">
    <?= admin_icon('info', 'ico ico--sm') ?>
    Archived households are hidden from visitor invitation household selectors and cannot receive new members.
    Use the household detail screen to archive or restore a household.
  </p>
</section>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="add-household-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-household-title">Add a household</h2>
      <p class="admin-section__hint">Unit numbers are unique per estate.</p>
    </div>
  </div>

  <form method="post" action="/admin/households" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'unit_number') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="unit_number">
          Unit number
          <span class="muted text-xs">Required</span>
        </label>
        <input class="input<?= admin_field_error($errors, 'unit_number') !== '' ? ' input--invalid' : '' ?>"
               id="unit_number" name="unit_number" type="text" maxlength="60" required
               value="<?= e(admin_old($old, 'unit_number')) ?>" placeholder="A-001">
        <?php if (admin_field_error($errors, 'unit_number') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'unit_number')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'building_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="household_building">Building <span class="muted text-xs">Optional</span></label>
        <select class="select" id="household_building" name="building_uuid">
          <option value="">No building</option>
          <?php foreach ($buildings as $building): ?>
            <?php
            if (!is_array($building)) {
                continue;
            }
            $buildingUuid = trim((string) ($building['uuid'] ?? ''));
            if ($buildingUuid === '') {
                continue;
            }
            $selected = admin_old($old, 'building_uuid') === $buildingUuid;
            ?>
            <option value="<?= e($buildingUuid) ?>" <?= $selected ? 'selected' : '' ?>>
              <?= e(trim((string) ($building['name'] ?? 'Building'))) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'building_uuid') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'building_uuid')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'household_type') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="household_type">Household type</label>
        <select class="select" id="household_type" name="household_type" required>
          <?php foreach ($typeLabels as $typeValue => $typeText): ?>
            <?php $selected = admin_old($old, 'household_type', 'apartment') === (string) $typeValue; ?>
            <option value="<?= e((string) $typeValue) ?>" <?= $selected ? 'selected' : '' ?>><?= e($typeText) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'household_type') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'household_type')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add household</button>
    </div>
  </form>
</section>
<?php endif; ?>
