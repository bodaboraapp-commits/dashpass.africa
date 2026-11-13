<?php

declare(strict_types=1);

/**
 * Admin estate staff directory (staff-incidents contract §4).
 *
 * Read-only estate-wide directory of registered household staff with household
 * labels and masked phones. No mutations in the admin surface this increment.
 *
 * Variables (T38 frozen keys): $staff [{uuid,full_name,phone_masked,staff_type,
 * status,status_label,status_tone,household_label,created_at}], $households,
 * $errors, $old + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$staff = isset($staff) && is_array($staff) ? $staff : [];
$households = isset($households) && is_array($households) ? $households : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];

$typeLabels = [
    'domestic' => 'Domestic',
    'driver' => 'Driver',
    'gardener' => 'Gardener',
    'nanny' => 'Nanny',
    'cook' => 'Cook',
    'security' => 'Security',
    'other' => 'Other',
];
$tableLabels = static function (array $items, string $valueKey, string $labelKey): array {
    $out = [];
    foreach ($items as $item) {
        if (is_array($item) && isset($item[$valueKey])) {
            $out[(string) $item[$valueKey]] = (string) ($item[$labelKey] ?? admin_humanize((string) $item[$valueKey]));
        }
    }

    return $out;
};
if (isset($staff_types) && is_array($staff_types)) {
    $override = $tableLabels($staff_types, 'value', 'label');
    if ($override !== []) {
        $typeLabels = $override;
    }
}

$rows = array_values(array_filter($staff, 'is_array'));
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Staff directory</h1>
    <p class="admin-page__lede">
      Household staff registered across the estate, grouped by household. Phone numbers are shown masked in this
      directory.
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="admin-section" aria-labelledby="admin-staff-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="admin-staff-title">Registered staff</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Staff member</th>
            <th scope="col">Phone</th>
            <th scope="col">Type</th>
            <th scope="col">Household</th>
            <th scope="col">Status</th>
            <th scope="col">Added</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $member): ?>
            <?php
            $fullName = trim((string) ($member['full_name'] ?? 'Staff member'));
            $phoneMasked = trim((string) ($member['phone_masked'] ?? ''));
            $staffType = trim((string) ($member['staff_type'] ?? ''));
            $householdLabel = trim((string) ($member['household_label'] ?? ''));
            $status = trim((string) ($member['status'] ?? 'active'));
            $statusLabel = trim((string) ($member['status_label'] ?? '')) ?: admin_humanize($status);
            $statusTone = trim((string) ($member['status_tone'] ?? ''));
            $createdAt = isset($member['created_at']) ? (string) $member['created_at'] : '';
            ?>
            <tr>
              <td data-label="Staff member"><?= e($fullName) ?></td>
              <td data-label="Phone"><?= e($phoneMasked !== '' ? $phoneMasked : '-') ?></td>
              <td data-label="Type"><?= e($typeLabels[$staffType] ?? admin_humanize($staffType)) ?></td>
              <td data-label="Household"><?= e($householdLabel !== '' ? $householdLabel : '-') ?></td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Added"><?= e(admin_datetime($createdAt, $timezone, 'd M Y')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('users') ?></span>
      <p class="admin-empty__title">No household staff registered yet</p>
      <p class="admin-empty__text">Residents register their household staff from the resident app; those records appear here.</p>
    </div>
  <?php endif; ?>

  <p class="admin-note">
    <?= admin_icon('info', 'ico ico--sm') ?>
    Admin staff changes (create/edit/deactivate) are managed by the resident household in this release; this view is
    read-only for auditing. Staff accounts and invitations are not part of this increment.
  </p>
</section>
