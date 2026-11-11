<?php

declare(strict_types=1);

/**
 * Admin parking bays (parking-administration contract §3, phase 1).
 *
 * List/filter + create + inline edit + archive for estate bays. Archive is
 * blocked while a bay is in use (active allocation/reservation/session) — the
 * row explains that instead of offering a dead action.
 *
 * Variables (T60 frozen keys, defensive): $bays [], $zones [], $bay_types [],
 * $filters {zone|zone_uuid,status}, $errors [], $old [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$bays = isset($bays) && is_array($bays) ? $bays : [];
$zones = isset($zones) && is_array($zones) ? $zones : [];
$bayTypes = isset($bay_types) && is_array($bay_types) ? $bay_types : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
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
        $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
        $out[$value] = $label !== '' ? $label : admin_humanize($value);
    }

    return $out !== [] ? $out : $fallback;
};
$bayTypeMap = $optionMap($bayTypes, [
    'resident' => 'Resident',
    'visitor' => 'Visitor',
    'shared' => 'Shared',
    'staff' => 'Staff',
]);
$zoneOptions = $optionMap($zones, []);
$statusMap = $optionMap(isset($status_options) && is_array($status_options) ? $status_options : [], [
    'available' => 'Available',
    'occupied' => 'Occupied',
    'maintenance' => 'Maintenance',
]);
$oldValue = static function (string $field) use ($old): string {
    $candidate = $old[$field] ?? '';

    return is_scalar($candidate) ? trim((string) $candidate) : '';
};
$fieldError = static fn (string $field): string => admin_field_error($errors, $field);
$currentZone = trim((string) ($filters['zone'] ?? $filters['zone_uuid'] ?? ''));
$currentStatus = trim((string) ($filters['status'] ?? ''));
$hasFilters = $currentZone !== '' || $currentStatus !== '';
$rows = array_values(array_filter($bays, 'is_array'));

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
        'available' => 'status--active',
        'occupied' => 'status--pending',
        'maintenance' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/parking">Parking</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Bays</span>
    </nav>
    <h1 class="admin-page__title">Parking bays</h1>
    <p class="admin-page__lede">Add bays, move them between zones, and archive bays that are no longer usable.</p>
  </div>
</div>

<nav class="admin-subnav" aria-label="Parking sections">
  <a class="admin-subnav__link" href="/admin/parking">Overview</a>
  <a class="admin-subnav__link" href="/admin/parking/zones">Zones</a>
  <a class="admin-subnav__link is-active" href="/admin/parking/bays" aria-current="page">Bays</a>
  <a class="admin-subnav__link" href="/admin/parking/allocations">Allocations</a>
  <?php if ($can('parking.violation.manage')): ?>
    <a class="admin-subnav__link" href="/admin/parking/violations">Violations</a>
  <?php endif; ?>
</nav>

<?= admin_summary($errors) ?>

<?php if ($canManage): ?>
  <section class="admin-section" aria-labelledby="bay-add-title">
    <div class="admin-section__head">
      <h2 class="admin-section__title" id="bay-add-title">Add a bay</h2>
    </div>
    <form method="post" action="/admin/parking/bays" class="admin-form card admin-form--inline">
      <?= csrf_field() ?>
      <div class="admin-form__grid admin-form__grid--inline">
        <div class="admin-field<?= $fieldError('bay_code') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="bay_code">Bay code</label>
          <input class="input<?= $fieldError('bay_code') !== '' ? ' input--invalid' : '' ?>" id="bay_code" name="bay_code"
                 type="text" maxlength="20" required value="<?= e($oldValue('bay_code')) ?>" placeholder="e.g. A-01">
          <?php if ($fieldError('bay_code') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('bay_code')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('zone_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="bay_zone">Zone</label>
          <select class="select" id="bay_zone" name="zone_uuid" required>
            <option value="">Choose a zone</option>
            <?php foreach ($zoneOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('zone_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('zone_uuid') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('zone_uuid')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('bay_type') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="bay_type">Bay type</label>
          <select class="select" id="bay_type" name="bay_type" required>
            <?php $selectedBayType = $oldValue('bay_type'); ?>
            <?php foreach ($bayTypeMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedBayType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('bay_type') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('bay_type')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-form__actions">
          <button type="submit" class="btn btn--primary btn--sm"><?= admin_icon('plus', 'ico ico--sm') ?> Add bay</button>
        </div>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="bays-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="bays-list-title">Bays</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/admin/parking/bays" class="admin-form card admin-filter-form">
    <div class="admin-form__grid admin-form__grid--inline">
      <div class="admin-field">
        <label class="admin-field__label" for="bay_filter_zone">Zone</label>
        <select class="select" id="bay_filter_zone" name="zone">
          <option value="">All zones</option>
          <?php foreach ($zoneOptions as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentZone === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="bay_filter_status">Status</label>
        <select class="select" id="bay_filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/admin/parking/bays">Clear filters</a>
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
            <th scope="col">Zone</th>
            <th scope="col">Type</th>
            <th scope="col">Status</th>
            <th scope="col">Allocated to</th>
            <?php if ($canManage): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $bay): ?>
            <?php
            $bayUuid = trim((string) ($bay['uuid'] ?? ''));
            $bayCode = trim((string) ($bay['bay_code'] ?? ($bay['code'] ?? 'Bay')));
            $zoneName = trim((string) ($bay['zone_name'] ?? ($bay['zone_label'] ?? '')));
            $bayType = trim((string) ($bay['bay_type'] ?? ''));
            $bayTypeLabel = trim((string) ($bay['bay_type_label'] ?? '')) ?: ($bayTypeMap[$bayType] ?? admin_humanize($bayType));
            $status = trim((string) ($bay['status'] ?? 'available'));
            $statusLabel = trim((string) ($bay['status_label'] ?? '')) ?: ($statusMap[$status] ?? admin_humanize($status));
            $statusTone = trim((string) ($bay['status_tone'] ?? ''));
            // T60 presenter exposes the active allocation label as allocated/
            // allocation_label (there is no explicit in_use flag): a bay with an
            // active allocation is in use and its archive action must be blocked.
            $householdLabel = trim((string) ($bay['allocated'] ?? ($bay['allocation_label'] ?? ($bay['household_label'] ?? ''))));
            $inUse = $householdLabel !== '' || (bool) ($bay['in_use'] ?? ($bay['active_allocation'] ?? false));
            $isArchived = in_array($status, ['archived', 'maintenance'], true);
            ?>
            <tr>
              <td data-label="Bay" class="mono"><?= e($bayCode) ?></td>
              <td data-label="Zone"><?= e($zoneName !== '' ? $zoneName : '—') ?></td>
              <td data-label="Type"><?= e($bayTypeLabel !== '' ? $bayTypeLabel : '—') ?></td>
              <td data-label="Status"><span class="status <?= e($statusClass($status, $statusTone)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Allocated to"><?= e($householdLabel !== '' ? $householdLabel : '—') ?></td>
              <?php if ($canManage): ?>
                <td data-label="Actions">
                  <div class="admin-row-actions">
                    <?php if ($bayUuid !== ''): ?>
                      <details class="admin-inline-edit">
                        <summary class="btn btn--ghost btn--sm">Edit</summary>
                        <form method="post" action="/admin/parking/bays/<?= e(rawurlencode($bayUuid)) ?>" class="admin-inline-edit__form">
                          <?= csrf_field() ?>
                          <div class="admin-field">
                            <label class="admin-field__label" for="bay_zone_<?= e($bayUuid) ?>">Zone</label>
                            <select class="select" id="bay_zone_<?= e($bayUuid) ?>" name="zone_uuid" required>
                              <?php foreach ($zoneOptions as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= (string) ($bay['zone_uuid'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="admin-field">
                            <label class="admin-field__label" for="bay_type_<?= e($bayUuid) ?>">Type</label>
                            <select class="select" id="bay_type_<?= e($bayUuid) ?>" name="bay_type" required>
                              <?php foreach ($bayTypeMap as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= $bayType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <button type="submit" class="btn btn--outline btn--sm">Save bay</button>
                        </form>
                      </details>
                      <?php if ($inUse): ?>
                        <span class="admin-field__hint"><?= admin_icon('lock', 'ico ico--xs') ?> In use — end allocation/reservation first</span>
                      <?php elseif (!$isArchived): ?>
                        <form method="post" action="/admin/parking/bays/<?= e(rawurlencode($bayUuid)) ?>/archive" class="inline-form"
                              data-confirm="Archive this bay? It will be marked as maintenance and excluded from availability.">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn--ghost btn--sm">Archive</button>
                        </form>
                      <?php else: ?>
                        <span class="admin-field__hint">Archived</span>
                      <?php endif; ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('parking') ?></span>
      <p class="admin-empty__title"><?= $hasFilters ? 'No bays match these filters' : 'No parking bays yet' ?></p>
      <p class="admin-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see every bay in the estate.
        <?php else: ?>
          Add a bay to start assigning parking to households.
        <?php endif; ?>
      </p>
      <?php if ($hasFilters): ?>
        <a class="btn btn--outline" href="/admin/parking/bays">Clear filters</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
