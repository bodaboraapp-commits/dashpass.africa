<?php

declare(strict_types=1);

/**
 * Tenant move-out module (owner request 2026-11-13).
 *
 * Variables: $candidates [{uuid,unit_number,building_name,label,tenant_name,
 * tenant_phone,members_count,staff_count,vehicles_count,vacant}],
 * $history [{uuid,household_uuid,unit_number,building_name,tenant_name,
 * tenant_phone,members_removed,invitations_revoked,vehicles_deactivated,
 * moved_out_at,reason,notes,actor_name}], $today, $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$candidates = isset($candidates) && is_array($candidates) ? $candidates : [];
$history = isset($history) && is_array($history) ? $history : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'estate.user.manage');
$today = isset($today) && is_string($today) && $today !== '' ? $today : date('Y-m-d');
$schemaReady = (bool) ($schema_ready ?? true);
$vacantCount = count(array_filter($candidates, static fn (array $candidate): bool => (bool) ($candidate['vacant'] ?? false)));
$occupied = array_values(array_filter($candidates, static fn (array $candidate): bool => !(bool) ($candidate['vacant'] ?? false)));

$dateLabel = static function (string $value) use ($timezone): string {
    if ($value === '') {
        return '-';
    }

    try {
        return format_datetime($value, $timezone, 'd M Y');
    } catch (\Throwable) {
        return $value;
    }
};
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Tenant move-outs</h1>
    <p class="admin-page__lede">
      Remove a tenant and close the household. The house becomes vacant and ready for the next tenant;
      staff, visitor passes and vehicles for the household are deactivated and a move-out record is kept.
    </p>
  </div>
</div>

<section class="admin-section" aria-labelledby="move-out-form-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="move-out-form-title">Move out a tenant</h2>
      <p class="admin-section__hint">Only active households with an active tenant are listed. Archived units are managed on the Households screen.</p>
    </div>
    <?php if ($candidates !== []): ?>
      <span class="chip"><?= e((string) count($occupied)) ?> occupied</span>
    <?php endif; ?>
  </div>

  <?php if (!$schemaReady): ?>
    <div class="alert alert--warning" role="status">
      <?= admin_icon('alert', 'ico ico--sm') ?>
      <div class="alert__body">
        <p class="alert__title">Database update required</p>
        <p>The move-out ledger is not installed on this deployment yet. Run <code>php scripts/migrate.php</code> once, then reload this page. The Vacant houses list keeps working.</p>
      </div>
    </div>
  <?php elseif (!$canManage): ?>
    <p class="admin-field__hint">Only an Estate Admin can move a tenant out.</p>
  <?php else: ?>
    <?php if ($occupied === []): ?>
      <div class="admin-empty">
        <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('home') ?></span>
        <p class="admin-empty__title">No occupied households</p>
        <p class="admin-empty__text">Every active house is already vacant. Add the next tenant from the Households screen.</p>
      </div>
    <?php else: ?>
      <form method="post" action="/admin/residents" class="admin-form card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="move_out">

        <div class="admin-form__grid">
          <div class="admin-field<?= isset($errors['household_uuid']) ? ' admin-field--invalid' : '' ?>">
            <label class="admin-field__label" for="move_out_household">Household / tenant</label>
            <select class="select" id="move_out_household" name="household_uuid" required>
              <option value="">Select a household</option>
              <?php foreach ($occupied as $candidate): ?>
                <?php
                $value = (string) ($candidate['uuid'] ?? '');
                $label = (string) ($candidate['label'] ?? '');
                $tenantName = trim((string) ($candidate['tenant_name'] ?? ''));
                $membersCount = (int) ($candidate['members_count'] ?? 0);
                ?>
                <option value="<?= e($value) ?>" <?= ($old['household_uuid'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= e($label . ($tenantName !== '' ? ' - ' . $tenantName : '') . ' (' . $membersCount . ' member' . ($membersCount === 1 ? '' : 's') . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['household_uuid'])): ?><p class="admin-field__error"><?= e(implode(' ', (array) $errors['household_uuid'])) ?></p><?php endif; ?>
          </div>

          <div class="admin-field<?= isset($errors['moved_out_at']) ? ' admin-field--invalid' : '' ?>">
            <label class="admin-field__label" for="move_out_date">Move-out date</label>
            <input class="input" id="move_out_date" name="moved_out_at" type="date" max="<?= e($today) ?>"
                   value="<?= e((string) ($old['moved_out_at'] ?? $today)) ?>" required>
            <?php if (isset($errors['moved_out_at'])): ?><p class="admin-field__error"><?= e(implode(' ', (array) $errors['moved_out_at'])) ?></p><?php endif; ?>
          </div>

          <div class="admin-field">
            <label class="admin-field__label" for="move_out_reason">Reason (optional)</label>
            <input class="input" id="move_out_reason" name="reason" type="text" maxlength="500"
                   value="<?= e((string) ($old['reason'] ?? '')) ?>" placeholder="e.g. Lease ended, relocation">
          </div>
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="move_out_notes">Notes (optional)</label>
          <textarea class="input" id="move_out_notes" name="notes" rows="3" maxlength="1000" placeholder="Handover / meter reading / deposit notes"><?= e((string) ($old['notes'] ?? '')) ?></textarea>
        </div>

        <div class="alert alert--warning" role="note">
          <?= admin_icon('alert', 'ico ico--sm') ?>
          <div class="alert__body">
            <p class="alert__title">This closes the household</p>
            <p>The tenant and every household member lose estate access, the household staff and vehicles are
            deactivated and pending/approved visitor passes are revoked. The house stays available and can be
            re-occupied by adding the next tenant.</p>
          </div>
        </div>

        <label class="admin-check">
          <input type="checkbox" name="confirm" value="1" <?= ($old['confirm'] ?? '') === '1' ? 'checked' : '' ?> required>
          <span>I confirm the tenant is moving out and the house will be marked vacant.</span>
        </label>
        <?php if (isset($errors['confirm'])): ?><p class="admin-field__error"><?= e(implode(' ', (array) $errors['confirm'])) ?></p><?php endif; ?>

        <div class="admin-form__actions">
          <button type="submit" class="btn btn--primary"><?= admin_icon('logout', 'ico ico--sm') ?> Move out tenant</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="vacant-units-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="vacant-units-title">Vacant houses</h2>
      <p class="admin-section__hint">Active houses with no household members. Open a household to add the next tenant.</p>
    </div>
    <span class="chip"><?= e((string) $vacantCount) ?></span>
  </div>

  <?php if ($vacantCount === 0): ?>
    <p class="admin-field__hint">No vacant houses right now.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Unit</th>
            <th scope="col">Building</th>
            <th scope="col">Next step</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($candidates as $candidate): ?>
            <?php if (!(bool) ($candidate['vacant'] ?? false)) { continue; } ?>
            <tr>
              <td data-label="Unit">
                <a class="admin-table__link" href="/admin/households/<?= e(rawurlencode((string) ($candidate['uuid'] ?? ''))) ?>">
                  <?= e((string) ($candidate['unit_number'] ?? '')) ?>
                </a>
              </td>
              <td data-label="Building"><?= e(trim((string) ($candidate['building_name'] ?? '')) ?: '-') ?></td>
              <td data-label="Next step">Ready for a new tenant</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="move-out-history-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="move-out-history-title">Move-out history</h2>
      <p class="admin-section__hint">Snapshot records of every tenant moved out of this estate.</p>
    </div>
    <?php if ($history !== []): ?><span class="chip"><?= e((string) count($history)) ?></span><?php endif; ?>
  </div>

  <?php if ($history === []): ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('logout') ?></span>
      <p class="admin-empty__title">No move-outs recorded yet</p>
      <p class="admin-empty__text">Completed move-outs appear here with the tenant, unit, date and who processed it.</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Moved out</th>
            <th scope="col">Unit</th>
            <th scope="col">Tenant</th>
            <th scope="col">Closed</th>
            <th scope="col">Reason</th>
            <th scope="col">Processed by</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $entry): ?>
            <?php
            $unit = (string) ($entry['unit_number'] ?? '');
            $building = trim((string) ($entry['building_name'] ?? ''));
            $tenantName = (string) ($entry['tenant_name'] ?? '');
            ?>
            <tr>
              <td data-label="Moved out"><?= e($dateLabel((string) ($entry['moved_out_at'] ?? ''))) ?></td>
              <td data-label="Unit"><?= e($unit . ($building !== '' ? ' - ' . $building : '')) ?></td>
              <td data-label="Tenant"><?= e($tenantName !== '' ? $tenantName : '-') ?></td>
              <td data-label="Closed">
                <?= e((string) ((int) ($entry['members_removed'] ?? 0))) ?> members,
                <?= e((string) ((int) ($entry['invitations_revoked'] ?? 0))) ?> passes,
                <?= e((string) ((int) ($entry['vehicles_deactivated'] ?? 0))) ?> vehicles
              </td>
              <td data-label="Reason"><?= e(trim((string) ($entry['reason'] ?? '')) ?: '-') ?></td>
              <td data-label="Processed by"><?= e(trim((string) ($entry['actor_name'] ?? '')) ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
