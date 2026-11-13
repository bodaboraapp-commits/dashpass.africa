<?php

declare(strict_types=1);

/**
 * Admin parking violations (parking-administration contract §3, phase 1).
 *
 * Estate-wide violations queue with status/type filters, a manual create form,
 * and resolve/dismiss actions (resolve requires notes; resolved/dismissed rows
 * are terminal and show their notes instead of actions).
 *
 * Variables (T60 frozen keys, defensive): $violations [], $violation_types [],
 * $bays [], $filters {status,type}, $status_options [], $errors [], $old [] +
 * admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$violations = isset($violations) && is_array($violations) ? $violations : [];
// T60 actual key is type_options; keep violation_types as a defensive alias.
$violationTypes = isset($violation_types) && is_array($violation_types) && $violation_types !== []
    ? $violation_types
    : (isset($type_options) && is_array($type_options) ? $type_options : []);
$bays = isset($bays) && is_array($bays) ? $bays : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$can = static fn (string $permission): bool => in_array($permission, array_map('strval', $permissions), true);
$canManage = $can('parking.violation.manage');

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
        $value = trim((string) ($item['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item['label'] ?? ''));
        $out[$value] = $label !== '' ? $label : admin_humanize($value);
    }

    return $out !== [] ? $out : $fallback;
};
$typeMap = $optionMap($violationTypes, [
    'overstay' => 'Overstay',
    'unauthorised_bay' => 'Unauthorised bay',
    'unregistered_vehicle' => 'Unregistered vehicle',
    'other' => 'Other',
]);
$statusMap = $optionMap($statusOptions, [
    'open' => 'Open',
    'resolved' => 'Resolved',
    'dismissed' => 'Dismissed',
]);
$bayOptions = [];
foreach ($bays as $bay) {
    if (!is_array($bay)) {
        continue;
    }
    $uuid = trim((string) ($bay['uuid'] ?? ''));
    $label = trim((string) ($bay['bay_code'] ?? ($bay['label'] ?? '')));
    if ($uuid !== '') {
        $bayOptions[$uuid] = $label !== '' ? $label : admin_humanize($uuid);
    }
}

$oldValue = static function (string $field) use ($old): string {
    $candidate = $old[$field] ?? '';

    return is_scalar($candidate) ? trim((string) $candidate) : '';
};
$fieldError = static fn (string $field): string => admin_field_error($errors, $field);
$currentStatus = trim((string) ($filters['status'] ?? ''));
$currentType = trim((string) ($filters['type'] ?? ($filters['violation_type'] ?? '')));
$hasFilters = $currentStatus !== '' || $currentType !== '';
$rows = array_values(array_filter($violations, 'is_array'));

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
        'open' => 'status--danger',
        'resolved' => 'status--active',
        'dismissed' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/parking">Parking</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Violations</span>
    </nav>
    <h1 class="admin-page__title">Parking violations</h1>
    <p class="admin-page__lede">Review parking violations reported at the gate, record manual violations, and resolve or dismiss them.</p>
  </div>
</div>

<nav class="admin-subnav" aria-label="Parking sections">
  <a class="admin-subnav__link" href="/admin/parking">Overview</a>
  <a class="admin-subnav__link" href="/admin/parking/zones">Zones</a>
  <a class="admin-subnav__link" href="/admin/parking/bays">Bays</a>
  <a class="admin-subnav__link" href="/admin/parking/allocations">Allocations</a>
  <a class="admin-subnav__link is-active" href="/admin/parking/violations" aria-current="page">Violations</a>
</nav>

<?= admin_summary($errors) ?>

<?php if ($canManage): ?>
  <section class="admin-section" aria-labelledby="violation-add-title">
    <div class="admin-section__head">
      <h2 class="admin-section__title" id="violation-add-title">Record a violation</h2>
    </div>

    <form method="post" action="/admin/parking/violations" class="admin-form card">
      <?= csrf_field() ?>
      <div class="admin-form__grid">
        <div class="admin-field<?= $fieldError('type') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="violation_type">Type</label>
          <select class="select" id="violation_type" name="type" required>
            <option value="">Choose a type</option>
            <?php foreach ($typeMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('type') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('type') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('type')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('bay_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="violation_bay">Bay</label>
          <select class="select" id="violation_bay" name="bay_uuid" required>
            <option value="">Choose a bay</option>
            <?php foreach ($bayOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('bay_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('bay_uuid') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('bay_uuid')) ?></p>
          <?php endif; ?>
          <?php if ($bayOptions === []): ?>
            <p class="admin-field__hint">
              <?= admin_icon('info', 'ico ico--xs') ?>
              No bay options are available yet, a bay is required to record a violation.
            </p>
          <?php endif; ?>
        </div>
        <div class="admin-field<?= $fieldError('plate_number') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="violation_plate">
            Plate number
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input<?= $fieldError('plate_number') !== '' ? ' input--invalid' : '' ?>" id="violation_plate"
                 name="plate_number" type="text" maxlength="20" value="<?= e($oldValue('plate_number', $oldValue('vehicle_plate'))) ?>" placeholder="e.g. KDA 123A">
          <?php if ($fieldError('plate_number') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('plate_number')) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="admin-field<?= $fieldError('notes') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="violation_notes">Notes</label>
        <textarea class="input<?= $fieldError('notes') !== '' ? ' input--invalid' : '' ?>" id="violation_notes" name="notes"
                  rows="3" maxlength="500" required placeholder="What was observed and when?"><?= e($oldValue('notes')) ?></textarea>
        <?php if ($fieldError('notes') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('notes')) ?></p>
        <?php endif; ?>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"<?= $bayOptions === [] ? ' disabled aria-disabled="true"' : '' ?>>
          <?= admin_icon('alert', 'ico ico--sm') ?> Record violation
        </button>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="violations-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="violations-list-title">Violations</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/admin/parking/violations" class="admin-form card admin-filter-form">
    <div class="admin-form__grid admin-form__grid--inline">
      <div class="admin-field">
        <label class="admin-field__label" for="violation_filter_status">Status</label>
        <select class="select" id="violation_filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="violation_filter_type">Type</label>
        <select class="select" id="violation_filter_type" name="type">
          <option value="">All types</option>
          <?php foreach ($typeMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/admin/parking/violations">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Type</th>
            <th scope="col">Bay / vehicle</th>
            <th scope="col">Reported</th>
            <th scope="col">Status</th>
            <th scope="col">Details</th>
            <?php if ($canManage): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $violation): ?>
            <?php
            $violationUuid = trim((string) ($violation['uuid'] ?? ''));
            $type = trim((string) ($violation['type'] ?? ($violation['violation_type'] ?? '')));
            $typeLabel = trim((string) ($violation['type_label'] ?? ($violation['violation_type_label'] ?? ''))) ?: ($typeMap[$type] ?? admin_humanize($type));
            $status = trim((string) ($violation['status'] ?? 'open'));
            $statusLabel = trim((string) ($violation['status_label'] ?? '')) ?: ($statusMap[$status] ?? admin_humanize($status));
            $statusTone = trim((string) ($violation['status_tone'] ?? ''));
            $bayLabel = trim((string) ($violation['bay_code'] ?? ($violation['bay_label'] ?? '')));
            $plate = trim((string) ($violation['vehicle_plate'] ?? ($violation['plate_number'] ?? '')));
            $notes = trim((string) ($violation['notes'] ?? ($violation['description'] ?? '')));
            $resolutionNotes = trim((string) ($violation['resolution_notes'] ?? ''));
            $reportedBy = trim((string) ($violation['reported_by'] ?? ($violation['reporter_name'] ?? '')));
            $createdAt = isset($violation['created_at']) ? (string) $violation['created_at'] : '';
            $isOpen = $status === 'open';
            ?>
            <tr>
              <td data-label="Type"><?= e($typeLabel !== '' ? $typeLabel : 'Violation') ?></td>
              <td data-label="Bay / vehicle">
                <?= e($bayLabel !== '' ? $bayLabel : '-') ?>
                <?php if ($plate !== ''): ?>
                  <span class="admin-table__sub mono"><?= e($plate) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Reported">
                <?= e($createdAt !== '' ? admin_datetime($createdAt, $timezone) : '-') ?>
                <?php if ($reportedBy !== ''): ?>
                  <span class="admin-table__sub"><?= e($reportedBy) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Status"><span class="status <?= e($statusClass($status, $statusTone)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Details">
                <?php if ($notes !== ''): ?>
                  <span class="admin-table__sub"><?= e($notes) ?></span>
                <?php endif; ?>
                <?php if ($resolutionNotes !== ''): ?>
                  <span class="admin-table__sub">Resolution: <?= e($resolutionNotes) ?></span>
                <?php endif; ?>
                <?php if ($notes === '' && $resolutionNotes === ''): ?>

                <?php endif; ?>
              </td>
              <?php if ($canManage): ?>
                <td data-label="Actions">
                  <?php if ($violationUuid !== '' && $isOpen): ?>
                    <div class="admin-row-actions">
                      <details class="admin-inline-edit">
                        <summary class="btn btn--outline btn--sm">Resolve</summary>
                        <form method="post" action="/admin/parking/violations/<?= e(rawurlencode($violationUuid)) ?>/resolve" class="admin-inline-edit__form">
                          <?= csrf_field() ?>
                          <input type="hidden" name="status" value="resolved">
                          <div class="admin-field">
                            <label class="admin-field__label" for="resolution_notes_<?= e($violationUuid) ?>">Resolution notes (required)</label>
                            <textarea class="input" id="resolution_notes_<?= e($violationUuid) ?>" name="notes" rows="2" maxlength="500" required></textarea>
                          </div>
                          <button type="submit" class="btn btn--primary btn--sm">Resolve violation</button>
                        </form>
                      </details>
                      <form method="post" action="/admin/parking/violations/<?= e(rawurlencode($violationUuid)) ?>/resolve" class="inline-form"
                            data-confirm="Dismiss this violation? Dismissed violations are closed and hidden from the open queue.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="dismissed">
                        <button type="submit" class="btn btn--ghost btn--sm">Dismiss</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <span class="admin-field__hint">Closed</span>
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
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('alert') ?></span>
      <p class="admin-empty__title"><?= $hasFilters ? 'No violations match these filters' : 'No parking violations recorded' ?></p>
      <p class="admin-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see the whole estate queue.
        <?php else: ?>
          Guard reports and manual violations will appear here for review.
        <?php endif; ?>
      </p>
      <?php if ($hasFilters): ?>
        <a class="btn btn--outline" href="/admin/parking/violations">Clear filters</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
