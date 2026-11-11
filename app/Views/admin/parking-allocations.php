<?php

declare(strict_types=1);

/**
 * Admin parking allocations (parking-administration contract §3, phase 1).
 *
 * List + create + end resident bay allocations. One active allocation per bay
 * and household is enforced by the service; the screen exposes the options and
 * explains ended rows instead of offering dead actions.
 *
 * Variables (T60 frozen keys, defensive): $allocations [], $bays [], $households
 * [], $vehicles [], $filters {status}, $errors [], $old [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$allocations = isset($allocations) && is_array($allocations) ? $allocations : [];
$bays = isset($bays) && is_array($bays) ? $bays : [];
$households = isset($households) && is_array($households) ? $households : [];
$vehicles = isset($vehicles) && is_array($vehicles) ? $vehicles : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $permission): bool => in_array($permission, array_map('strval', $permissions), true);
$canManage = $can('parking.allocation.manage');

$labelMap = static function (array $items, string $valueKey, string $labelKey, string $fallbackLabel = ''): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item[$valueKey] ?? $item['uuid'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item[$labelKey] ?? $item['label'] ?? $item['name'] ?? $item['bay_code'] ?? $item['unit_number'] ?? ''));
        $out[$value] = $label !== '' ? $label : ($fallbackLabel !== '' ? $fallbackLabel : admin_humanize($value));
    }

    return $out;
};
$bayOptions = $labelMap($bays, 'uuid', 'bay_code');
$householdOptions = $labelMap($households, 'uuid', 'label');
$vehicleOptions = $labelMap($vehicles, 'uuid', 'label');
if ($vehicleOptions === []) {
    foreach ($vehicles as $vehicle) {
        if (!is_array($vehicle)) {
            continue;
        }
        $uuid = trim((string) ($vehicle['uuid'] ?? ''));
        $plate = trim((string) ($vehicle['plate_number'] ?? $vehicle['registration'] ?? ''));
        if ($uuid !== '' && $plate !== '') {
            $vehicleOptions[$uuid] = $plate;
        }
    }
}

$oldValue = static function (string $field) use ($old): string {
    $candidate = $old[$field] ?? '';

    return is_scalar($candidate) ? trim((string) $candidate) : '';
};
$fieldError = static fn (string $field): string => admin_field_error($errors, $field);

$statusMap = ['' => 'All statuses', 'active' => 'Active', 'ended' => 'Ended'];
$currentStatus = trim((string) ($filters['status'] ?? ''));
$rows = array_values(array_filter($allocations, 'is_array'));
$hasFilters = $currentStatus !== '';

$statusClass = static function (string $status, string $tone = ''): string {
    $mapped = match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        'neutral' => 'status--inactive',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return match ($status) {
        'active' => 'status--active',
        'ended' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/parking">Parking</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Allocations</span>
    </nav>
    <h1 class="admin-page__title">Parking allocations</h1>
    <p class="admin-page__lede">Assign estate bays to resident households for a period, and end an allocation when it no longer applies.</p>
  </div>
</div>

<nav class="admin-subnav" aria-label="Parking sections">
  <a class="admin-subnav__link" href="/admin/parking">Overview</a>
  <a class="admin-subnav__link" href="/admin/parking/zones">Zones</a>
  <a class="admin-subnav__link" href="/admin/parking/bays">Bays</a>
  <a class="admin-subnav__link is-active" href="/admin/parking/allocations" aria-current="page">Allocations</a>
  <?php if ($can('parking.violation.manage')): ?>
    <a class="admin-subnav__link" href="/admin/parking/violations">Violations</a>
  <?php endif; ?>
</nav>

<?= admin_summary($errors) ?>

<?php if ($canManage): ?>
  <section class="admin-section" aria-labelledby="allocation-add-title">
    <div class="admin-section__head">
      <h2 class="admin-section__title" id="allocation-add-title">New allocation</h2>
      <p class="admin-section__hint">A bay can have one active allocation, and a household one active bay at a time.</p>
    </div>

    <form method="post" action="/admin/parking/allocations" class="admin-form card">
      <?= csrf_field() ?>
      <div class="admin-form__grid">
        <div class="admin-field<?= $fieldError('bay_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="allocation_bay">Bay</label>
          <select class="select" id="allocation_bay" name="bay_uuid" required>
            <option value="">Choose a bay</option>
            <?php foreach ($bayOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('bay_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('bay_uuid') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('bay_uuid')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('household_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="allocation_household">Household</label>
          <select class="select" id="allocation_household" name="household_uuid" required>
            <option value="">Choose a household</option>
            <?php foreach ($householdOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('household_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('household_uuid') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('household_uuid')) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($vehicleOptions !== []): ?>
          <div class="admin-field<?= $fieldError('vehicle_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
            <label class="admin-field__label" for="allocation_vehicle">
              Vehicle
              <span class="muted text-xs">Optional</span>
            </label>
            <select class="select" id="allocation_vehicle" name="vehicle_uuid">
              <option value="">No specific vehicle</option>
              <?php foreach ($vehicleOptions as $value => $label): ?>
                <option value="<?= e((string) $value) ?>" <?= $oldValue('vehicle_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($fieldError('vehicle_uuid') !== ''): ?>
              <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('vehicle_uuid')) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="admin-field<?= $fieldError('ends_at') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="allocation_ends_at">
            Ends
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input<?= $fieldError('ends_at') !== '' ? ' input--invalid' : '' ?>" id="allocation_ends_at"
                 name="ends_at" type="datetime-local" value="<?= e($oldValue('ends_at')) ?>">
          <p class="admin-field__hint">Leave blank for an open-ended allocation.</p>
          <?php if ($fieldError('ends_at') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('ends_at')) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Allocate bay</button>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="allocations-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="allocations-list-title">Allocations</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/admin/parking/allocations" class="admin-form card admin-filter-form">
    <div class="admin-form__grid admin-form__grid--inline">
      <div class="admin-field">
        <label class="admin-field__label" for="allocation_filter_status">Status</label>
        <select class="select" id="allocation_filter_status" name="status">
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filter</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/admin/parking/allocations">Clear filter</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Bay</th>
            <th scope="col">Household</th>
            <th scope="col">Vehicle</th>
            <th scope="col">Started</th>
            <th scope="col">Ends</th>
            <th scope="col">Status</th>
            <?php if ($canManage): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $allocation): ?>
            <?php
            $allocationUuid = trim((string) ($allocation['uuid'] ?? ''));
            $bayLabel = trim((string) ($allocation['bay_code'] ?? ($allocation['bay_label'] ?? '')));
            $householdLabel = trim((string) ($allocation['household_label'] ?? ''));
            $vehicleLabel = trim((string) ($allocation['vehicle_label'] ?? ($allocation['vehicle_plate'] ?? '')));
            $startsAt = isset($allocation['starts_at']) ? (string) $allocation['starts_at'] : '';
            $endsAt = isset($allocation['ends_at']) ? (string) $allocation['ends_at'] : '';
            $status = trim((string) ($allocation['status'] ?? 'active'));
            $statusLabel = trim((string) ($allocation['status_label'] ?? '')) ?: ($status === 'ended' ? 'Ended' : 'Active');
            $statusTone = trim((string) ($allocation['status_tone'] ?? ''));
            $isActive = $status === 'active';
            ?>
            <tr>
              <td data-label="Bay" class="mono"><?= e($bayLabel !== '' ? $bayLabel : '—') ?></td>
              <td data-label="Household"><?= e($householdLabel !== '' ? $householdLabel : '—') ?></td>
              <td data-label="Vehicle"><?= e($vehicleLabel !== '' ? $vehicleLabel : '—') ?></td>
              <td data-label="Started"><?= e($startsAt !== '' ? admin_datetime($startsAt, $timezone, 'd M Y') : '—') ?></td>
              <td data-label="Ends"><?= e($endsAt !== '' ? admin_datetime($endsAt, $timezone, 'd M Y') : 'Open-ended') ?></td>
              <td data-label="Status"><span class="status <?= e($statusClass($status, $statusTone)) ?>"><?= e($statusLabel) ?></span></td>
              <?php if ($canManage): ?>
                <td data-label="Actions">
                  <?php if ($allocationUuid !== '' && $isActive): ?>
                    <form method="post" action="/admin/parking/allocations/<?= e(rawurlencode($allocationUuid)) ?>/end" class="inline-form"
                          data-confirm="End this parking allocation? The bay becomes available again.">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn--ghost btn--sm">End allocation</button>
                    </form>
                  <?php else: ?>
                    <span class="admin-field__hint">Ended</span>
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
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('home') ?></span>
      <p class="admin-empty__title"><?= $hasFilters ? 'No allocations match this filter' : 'No parking allocations yet' ?></p>
      <p class="admin-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filter to see every allocation.
        <?php else: ?>
          Allocate a bay to a household to reserve parking for them.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>
</section>
