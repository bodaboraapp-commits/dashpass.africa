<?php

declare(strict_types=1);

/**
 * Admin residents list + add form + suspend/reactivate (contract §3, §7).
 *
 * Variables: $residents [{uuid,full_name,phone_masked,role_slugs,household_unit,
 * status,status_label,status_tone,created_at}], $roles [{value,label}],
 * $households [{uuid,unit_number,label}], $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$residents = isset($residents) && is_array($residents) ? $residents : [];
$roles = isset($roles) && is_array($roles) ? $roles : [];
$households = isset($households) && is_array($households) ? $households : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'estate.user.manage');

$roleLabels = [];
foreach ($roles as $role) {
    if (is_array($role) && isset($role['value'])) {
        $roleLabels[(string) $role['value']] = (string) ($role['label'] ?? admin_humanize((string) $role['value']));
    }
}
if ($roleLabels === []) {
    $roleLabels = ['resident' => 'Resident', 'tenant' => 'Tenant', 'household_member' => 'Household member'];
}

$canSuspend = static fn (string $status): bool => $status === 'active';
$canReactivate = static fn (string $status): bool => in_array($status, ['suspended', 'inactive'], true);
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Residents</h1>
    <p class="admin-page__lede">Everyone with an estate membership, their roles, household link and account status.</p>
  </div>
</div>

<section class="admin-section" aria-labelledby="residents-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="residents-list-title">Estate residents</h2>
    <?php if ($residents !== []): ?>
      <span class="chip"><?= e((string) count($residents)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($residents !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Resident</th>
            <th scope="col">Phone</th>
            <th scope="col">Roles</th>
            <th scope="col">Household</th>
            <th scope="col">Status</th>
            <th scope="col">Added</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($residents as $resident): ?>
            <?php
            if (!is_array($resident)) {
                continue;
            }
            $residentUuid = trim((string) ($resident['uuid'] ?? ''));
            $fullName = trim((string) ($resident['full_name'] ?? 'Resident'));
            $phoneMasked = trim((string) ($resident['phone_masked'] ?? ''));
            $roleSlugs = isset($resident['role_slugs']) && is_array($resident['role_slugs']) ? $resident['role_slugs'] : [];
            $householdUnit = trim((string) ($resident['household_unit'] ?? ''));
            $status = trim((string) ($resident['status'] ?? 'active'));
            $statusLabel = trim((string) ($resident['status_label'] ?? '')) ?: admin_humanize($status);
            $statusTone = trim((string) ($resident['status_tone'] ?? ''));
            $createdAt = isset($resident['created_at']) ? (string) $resident['created_at'] : '';
            ?>
            <tr>
              <td data-label="Resident"><?= e($fullName) ?></td>
              <td data-label="Phone"><?= e($phoneMasked !== '' ? $phoneMasked : '—') ?></td>
              <td data-label="Roles">
                <?php if ($roleSlugs !== []): ?>
                  <span class="admin-chip-row">
                    <?php foreach ($roleSlugs as $roleSlug): ?>
                      <?php if (is_scalar($roleSlug) && trim((string) $roleSlug) !== ''): ?>
                        <span class="chip"><?= e($roleLabels[(string) $roleSlug] ?? admin_humanize((string) $roleSlug)) ?></span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td data-label="Household"><?= e($householdUnit !== '' ? $householdUnit : '—') ?></td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Added"><?= e(admin_datetime($createdAt, $timezone, 'd M Y')) ?></td>
              <td data-label="Actions" class="admin-table__actions">
                <?php if ($canManage && $residentUuid !== '' && $canSuspend($status)): ?>
                  <form method="post" action="/admin/residents/<?= e(rawurlencode($residentUuid)) ?>/suspend"
                        data-confirm="Suspend this resident? They lose access to the estate immediately.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--danger-ghost btn--sm"
                            aria-label="Suspend <?= e($fullName) ?>">Suspend</button>
                  </form>
                <?php elseif ($canManage && $residentUuid !== '' && $canReactivate($status)): ?>
                  <form method="post" action="/admin/residents/<?= e(rawurlencode($residentUuid)) ?>/reactivate">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--outline btn--sm"
                            aria-label="Reactivate <?= e($fullName) ?>">Reactivate</button>
                  </form>
                <?php else: ?>
                  <span class="muted text-xs">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('users') ?></span>
      <p class="admin-empty__title">No residents yet</p>
      <p class="admin-empty__text">Add the first resident below, or link an existing account by phone number.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="add-resident-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-resident-title">Add a resident</h2>
      <p class="admin-section__hint">Existing phone numbers are linked to a membership instead of creating a duplicate account.</p>
    </div>
  </div>

  <form method="post" action="/admin/residents" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'full_name') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="resident_full_name">Full name</label>
        <input class="input<?= admin_field_error($errors, 'full_name') !== '' ? ' input--invalid' : '' ?>"
               id="resident_full_name" name="full_name" type="text" maxlength="180"
               value="<?= e(admin_old($old, 'full_name')) ?>" placeholder="Grace Njoroge">
        <?php if (admin_field_error($errors, 'full_name') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'full_name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'phone') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="resident_phone">Phone</label>
        <input class="input<?= admin_field_error($errors, 'phone') !== '' ? ' input--invalid' : '' ?>"
               id="resident_phone" name="phone" type="tel" inputmode="tel"
               value="<?= e(admin_old($old, 'phone')) ?>" placeholder="+254712345678">
        <p class="admin-field__hint">The resident signs in with a one-time code sent to this number.</p>
        <?php if (admin_field_error($errors, 'phone') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'phone')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'role_slug') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="resident_role">Role</label>
        <select class="select" id="resident_role" name="role_slug" required>
          <?php foreach ($roleLabels as $roleValue => $roleText): ?>
            <?php $selected = admin_old($old, 'role_slug', 'resident') === (string) $roleValue; ?>
            <option value="<?= e((string) $roleValue) ?>" <?= $selected ? 'selected' : '' ?>><?= e($roleText) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'role_slug') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'role_slug')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field">
        <label class="admin-field__label" for="resident_household">Household <span class="muted text-xs">Optional</span></label>
        <select class="select" id="resident_household" name="household_uuid">
          <option value="">No household link</option>
          <?php foreach ($households as $household): ?>
            <?php
            if (!is_array($household)) {
                continue;
            }
            $householdUuid = trim((string) ($household['uuid'] ?? ''));
            if ($householdUuid === '') {
                continue;
            }
            $unit = trim((string) ($household['unit_number'] ?? ''));
            $label = trim((string) ($household['label'] ?? ''));
            $selected = admin_old($old, 'household_uuid') === $householdUuid;
            ?>
            <option value="<?= e($householdUuid) ?>" <?= $selected ? 'selected' : '' ?>>
              <?= e($label !== '' ? $label : ($unit !== '' ? $unit : 'Household')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field">
        <label class="admin-field__label" for="resident_relationship">Relationship <span class="muted text-xs">Optional</span></label>
        <input class="input" id="resident_relationship" name="relationship" type="text" maxlength="60"
               value="<?= e(admin_old($old, 'relationship')) ?>" placeholder="Owner, tenant, family…">
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add resident</button>
    </div>
  </form>
</section>
<?php endif; ?>
