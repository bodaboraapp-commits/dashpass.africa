<?php

declare(strict_types=1);

/**
 * Admin household detail: edit (incl. active/archived status), member list with
 * soft removal, and add-member flows for a new resident or an existing resident
 * by phone (contract §3, §7).
 *
 * Variables: $household {uuid,unit_number,building_uuid,building_name,
 * household_type,status,status_label,status_tone}, $members [{uuid,user_uuid,
 * full_name,phone_masked,relationship,is_primary,status,status_label,
 * status_tone}], $buildings, $household_types, $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$household = isset($household) && is_array($household) ? $household : [];
$members = isset($members) && is_array($members) ? $members : [];
$buildings = isset($buildings) && is_array($buildings) ? $buildings : [];
$householdTypes = isset($household_types) && is_array($household_types) ? $household_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'household.manage');
$canManageMembers = admin_can($permissions, 'household.member.manage');

$householdUuid = trim((string) ($household['uuid'] ?? ''));
$unit = trim((string) ($household['unit_number'] ?? ''));
$buildingUuid = trim((string) ($household['building_uuid'] ?? ''));
$buildingName = trim((string) ($household['building_name'] ?? ''));
$householdType = trim((string) ($household['household_type'] ?? ''));
$status = trim((string) ($household['status'] ?? 'active'));
$statusLabel = trim((string) ($household['status_label'] ?? '')) ?: admin_humanize($status);
$statusTone = trim((string) ($household['status_tone'] ?? ''));
$isArchived = $status === 'inactive';

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

$memberRows = [];
$primaryCount = 0;
foreach ($members as $member) {
    if (!is_array($member)) {
        continue;
    }
    $memberRows[] = $member;
    if ((bool) ($member['is_primary'] ?? false)) {
        $primaryCount++;
    }
}
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/households">Households</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($unit !== '' ? $unit : 'Household') ?></span>
    </nav>
    <h1 class="admin-page__title">Household <?= e($unit !== '' ? $unit : '') ?></h1>
    <p class="admin-page__lede">
      <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
      <?php if ($buildingName !== ''): ?>
        · <?= e($buildingName) ?>
      <?php endif; ?>
      · <?= e($typeLabel($householdType)) ?>
      · <?= e((string) count($memberRows)) ?> member<?= count($memberRows) === 1 ? '' : 's' ?>
    </p>
  </div>
</div>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="edit-household-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="edit-household-title">Household details</h2>
      <p class="admin-section__hint">Update the unit, building, type or status. Archiving keeps all records and can be reversed here.</p>
    </div>
  </div>

  <form method="post" action="/admin/households/<?= e(rawurlencode($householdUuid)) ?>" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'unit_number') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="unit_number">Unit number</label>
        <input class="input<?= admin_field_error($errors, 'unit_number') !== '' ? ' input--invalid' : '' ?>"
               id="unit_number" name="unit_number" type="text" maxlength="60" required
               value="<?= e(admin_old($old, 'unit_number', $unit)) ?>">
        <?php if (admin_field_error($errors, 'unit_number') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'unit_number')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'building_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="household_building">Building</label>
        <select class="select" id="household_building" name="building_uuid">
          <option value="">No building</option>
          <?php foreach ($buildings as $building): ?>
            <?php
            if (!is_array($building)) {
                continue;
            }
            $candidateUuid = trim((string) ($building['uuid'] ?? ''));
            if ($candidateUuid === '') {
                continue;
            }
            $selected = admin_old($old, 'building_uuid', $buildingUuid) === $candidateUuid;
            ?>
            <option value="<?= e($candidateUuid) ?>" <?= $selected ? 'selected' : '' ?>>
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
            <?php $selected = admin_old($old, 'household_type', $householdType) === (string) $typeValue; ?>
            <option value="<?= e((string) $typeValue) ?>" <?= $selected ? 'selected' : '' ?>><?= e($typeText) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (admin_field_error($errors, 'household_type') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'household_type')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'status') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="household_status">Status</label>
        <select class="select" id="household_status" name="status">
          <?php $selectedStatus = admin_old($old, 'status', $status); ?>
          <option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $selectedStatus === 'inactive' ? 'selected' : '' ?>>Archived</option>
        </select>
        <p class="admin-field__hint">Archived households cannot receive new members and are excluded from invite household selectors.</p>
        <?php if (admin_field_error($errors, 'status') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'status')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save household</button>
      <a class="btn btn--ghost" href="/admin/households">Back to households</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="members-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="members-title">Members</h2>
      <p class="admin-section__hint">
        Active members can invite visitors to this household.
        <?php if ($primaryCount > 0): ?>
          One member is the primary contact.
        <?php endif; ?>
      </p>
    </div>
    <span class="chip"><?= e((string) count($memberRows)) ?></span>
  </div>

  <?php if ($memberRows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--members">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Phone</th>
            <th scope="col">Relationship</th>
            <th scope="col">Primary</th>
            <th scope="col">Status</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($memberRows as $member): ?>
            <?php
            $memberUuid = trim((string) ($member['uuid'] ?? ''));
            $fullName = trim((string) ($member['full_name'] ?? 'Resident'));
            $phoneMasked = trim((string) ($member['phone_masked'] ?? ''));
            $relationship = trim((string) ($member['relationship'] ?? ''));
            $isPrimary = (bool) ($member['is_primary'] ?? false);
            $memberStatus = trim((string) ($member['status'] ?? 'active'));
            $memberStatusLabel = trim((string) ($member['status_label'] ?? '')) ?: admin_humanize($memberStatus);
            $memberStatusTone = trim((string) ($member['status_tone'] ?? ''));
            $canRemove = $memberStatus === 'active' && $memberUuid !== '';
            ?>
            <tr>
              <td data-label="Name"><?= e($fullName) ?></td>
              <td data-label="Phone"><?= e($phoneMasked !== '' ? $phoneMasked : '-') ?></td>
              <td data-label="Relationship"><?= e($relationship !== '' ? admin_humanize($relationship) : '-') ?></td>
              <td data-label="Primary">
                <?php if ($isPrimary): ?>
                  <span class="chip chip--sunshine">Primary</span>
                <?php else: ?>
                  <span class="muted">, </span>
                <?php endif; ?>
              </td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($memberStatusTone, $memberStatus)) ?>"><?= e($memberStatusLabel) ?></span></td>
              <td data-label="Actions" class="admin-table__actions">
                <?php if ($canManageMembers && $canRemove): ?>
                  <form method="post"
                        action="/admin/households/<?= e(rawurlencode($householdUuid)) ?>/members/<?= e(rawurlencode($memberUuid)) ?>/remove"
                        data-confirm="Remove this member from the household? Their account stays, but they lose household access.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--danger-ghost btn--sm"
                            aria-label="Remove <?= e($fullName) ?> from this household">Remove</button>
                  </form>
                <?php else: ?>
                  <span class="muted text-xs">Inactive</span>
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
      <p class="admin-empty__title">No members yet</p>
      <p class="admin-empty__text">Add a new resident below, or link an existing resident to this household by phone.</p>
    </div>
  <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="add-member-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-member-title">Add a member</h2>
      <p class="admin-section__hint">Only one member per household is the primary contact; setting a new primary demotes the current one.</p>
    </div>
  </div>

  <?php if (!$canManageMembers): ?>
    <div class="alert alert--warning" role="status">
      <?= admin_icon('shield') ?>
      <div class="alert__body">
        <p class="alert__title">Read-only members</p>
        <p>Your role can view household members but not change them. Ask an estate administrator for member management access.</p>
      </div>
    </div>
  <?php elseif ($isArchived): ?>
    <div class="alert alert--warning" role="status">
      <?= admin_icon('alert') ?>
      <div class="alert__body">
        <p class="alert__title">Household is archived</p>
        <p>Archived households cannot receive new members. Set the status to Active above, then add members.</p>
      </div>
    </div>
  <?php else: ?>
    <form method="post" action="/admin/households/<?= e(rawurlencode($householdUuid)) ?>/members" class="admin-form card">
      <?= csrf_field() ?>
      <h3 class="admin-form__title"><?= admin_icon('plus', 'ico ico--sm') ?> Add a member</h3>
      <?= admin_summary($errors) ?>

      <?php $modeValue = admin_old($old, 'mode', 'new'); ?>
      <fieldset class="admin-mode">
        <legend class="admin-field__label">Resident type</legend>
        <div class="admin-mode__options">
          <label class="admin-check">
            <input type="radio" name="mode" value="new" <?= $modeValue !== 'existing' ? 'checked' : '' ?>>
            <span>New resident <span class="muted text-xs">, create an account from their name and phone</span></span>
          </label>
          <label class="admin-check">
            <input type="radio" name="mode" value="existing" <?= $modeValue === 'existing' ? 'checked' : '' ?>>
            <span>Existing resident <span class="muted text-xs">, link the account that already uses this phone</span></span>
          </label>
        </div>
      </fieldset>

      <div class="admin-form__grid admin-form__grid--single">
        <div class="admin-field<?= admin_field_error($errors, 'full_name') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="member_full_name">
            Full name
            <span class="muted text-xs">Required for a new resident</span>
          </label>
          <input class="input<?= admin_field_error($errors, 'full_name') !== '' ? ' input--invalid' : '' ?>"
                 id="member_full_name" name="full_name" type="text" maxlength="180"
                 value="<?= e(admin_old($old, 'full_name')) ?>" placeholder="Resident full name">
          <?php if (admin_field_error($errors, 'full_name') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'full_name')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field<?= admin_field_error($errors, 'phone') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="member_phone">Phone</label>
          <input class="input<?= admin_field_error($errors, 'phone') !== '' ? ' input--invalid' : '' ?>"
                 id="member_phone" name="phone" type="tel" inputmode="tel"
                 value="<?= e(admin_old($old, 'phone')) ?>" placeholder="+254712345678">
          <p class="admin-field__hint">Residents sign in with this phone number.</p>
          <?php if (admin_field_error($errors, 'phone') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'phone')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="member_relationship">
            Relationship
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input" id="member_relationship" name="relationship" type="text" maxlength="60"
                 value="<?= e(admin_old($old, 'relationship')) ?>" placeholder="Owner, tenant, family…">
        </div>
      </div>

      <label class="admin-check">
        <input type="checkbox" name="is_primary" value="1" <?= admin_old($old, 'is_primary') === '1' ? 'checked' : '' ?>>
        <span>Make this member the primary household contact</span>
      </label>

      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add member</button>
      </div>
    </form>
  <?php endif; ?>
</section>
