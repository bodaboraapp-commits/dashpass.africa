<?php

declare(strict_types=1);

/**
 * Admin guards list + add form + per-guard gate assignment + archive
 * (contract §3, §7).
 *
 * Variables: $guards [{uuid,full_name,phone_masked,gate_uuids,gate_names,status,
 * status_label,status_tone}], $gates [{uuid,name,status}], $errors, $old.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$guards = isset($guards) && is_array($guards) ? $guards : [];
$gates = isset($gates) && is_array($gates) ? $gates : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'guard.manage');

$activeGates = [];
foreach ($gates as $gate) {
    if (!is_array($gate)) {
        continue;
    }
    if (trim((string) ($gate['status'] ?? 'active')) === 'active') {
        $activeGates[] = $gate;
    }
}

$selectedGateUuids = [];
if (isset($old['gate_uuids']) && is_array($old['gate_uuids'])) {
    foreach ($old['gate_uuids'] as $gateUuid) {
        if (is_scalar($gateUuid)) {
            $selectedGateUuids[] = (string) $gateUuid;
        }
    }
}
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Guards</h1>
    <p class="admin-page__lede">Gate staff accounts and the gates each guard is assigned to. Archived guards lose gate access.</p>
  </div>
</div>

<section class="admin-section" aria-labelledby="guards-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="guards-list-title">Estate guards</h2>
    <?php if ($guards !== []): ?>
      <span class="chip"><?= e((string) count($guards)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($guards !== []): ?>
    <ul class="admin-card-list">
      <?php foreach ($guards as $guard): ?>
        <?php
        if (!is_array($guard)) {
            continue;
        }
        $guardUuid = trim((string) ($guard['uuid'] ?? ''));
        $fullName = trim((string) ($guard['full_name'] ?? 'Guard'));
        $phoneMasked = trim((string) ($guard['phone_masked'] ?? ''));
        $gateUuids = isset($guard['gate_uuids']) && is_array($guard['gate_uuids']) ? $guard['gate_uuids'] : [];
        $gateNames = isset($guard['gate_names']) && is_array($guard['gate_names']) ? $guard['gate_names'] : [];
        $status = trim((string) ($guard['status'] ?? 'active'));
        $statusLabel = trim((string) ($guard['status_label'] ?? '')) ?: admin_humanize($status);
        $statusTone = trim((string) ($guard['status_tone'] ?? ''));
        $activeGuard = $status === 'active';
        ?>
        <li class="admin-card admin-guard">
          <div class="admin-card__head">
            <div>
              <p class="admin-card__title"><?= e($fullName) ?></p>
              <p class="admin-card__meta"><?= e($phoneMasked !== '' ? $phoneMasked : 'Phone not available') ?></p>
            </div>
            <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
          </div>

          <p class="admin-card__meta admin-guard__gates">
            <?php if ($gateNames !== []): ?>
              <span class="admin-chip-row">
                <?php foreach ($gateNames as $gateName): ?>
                  <?php if (is_scalar($gateName) && trim((string) $gateName) !== ''): ?>
                    <span class="chip chip--outline"><?= admin_icon('gate', 'ico ico--xs') ?> <?= e((string) $gateName) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </span>
            <?php else: ?>
              No gates assigned
            <?php endif; ?>
          </p>

          <?php if ($canManage && $guardUuid !== '' && $activeGuard): ?>
            <form method="post" action="/admin/guards/<?= e(rawurlencode($guardUuid)) ?>/gates" class="admin-guard__assign">
              <?= csrf_field() ?>
              <p class="admin-field__label">Assigned gates</p>
              <?php if ($activeGates !== []): ?>
                <div class="admin-check-grid">
                  <?php foreach ($activeGates as $gate): ?>
                    <?php
                    $gateUuid = trim((string) ($gate['uuid'] ?? ''));
                    if ($gateUuid === '') {
                        continue;
                    }
                    $checked = admin_checkbox_checked($gateUuids, $gateUuid);
                    ?>
                    <label class="admin-check">
                      <input type="checkbox" name="gate_uuids[]" value="<?= e($gateUuid) ?>" <?= $checked ? 'checked' : '' ?>>
                      <span><?= e(trim((string) ($gate['name'] ?? 'Gate'))) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="admin-form__actions">
                  <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('check', 'ico ico--sm') ?> Save gate assignments</button>
                </div>
                <p class="admin-field__hint">Clearing every gate leaves the guard without a gate context on the guard app.</p>
              <?php else: ?>
                <p class="admin-field__hint">No active gates are available to assign. Add or reactivate a gate first.</p>
              <?php endif; ?>
            </form>

            <form method="post" action="/admin/guards/<?= e(rawurlencode($guardUuid)) ?>/archive" class="admin-guard__archive"
                  data-confirm="Archive this guard? Their membership is deactivated and gate access stops.">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn--danger-ghost btn--sm"
                      aria-label="Archive <?= e($fullName) ?>">Archive guard</button>
            </form>
          <?php elseif ($canManage && !$activeGuard): ?>
            <p class="admin-field__hint">Archived guards cannot be assigned gates.</p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('shield') ?></span>
      <p class="admin-empty__title">No guards yet</p>
      <p class="admin-empty__text">Add gate staff below and assign them to at least one gate.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="add-guard-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-guard-title">Add a guard</h2>
      <p class="admin-section__hint">Creates a guard account with a security-guard membership and gate assignments.</p>
    </div>
  </div>

  <form method="post" action="/admin/guards" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= admin_field_error($errors, 'full_name') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="guard_full_name">Full name</label>
        <input class="input<?= admin_field_error($errors, 'full_name') !== '' ? ' input--invalid' : '' ?>"
               id="guard_full_name" name="full_name" type="text" maxlength="180"
               value="<?= e(admin_old($old, 'full_name')) ?>" placeholder="John Mutiso">
        <?php if (admin_field_error($errors, 'full_name') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'full_name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'phone') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="guard_phone">Phone</label>
        <input class="input<?= admin_field_error($errors, 'phone') !== '' ? ' input--invalid' : '' ?>"
               id="guard_phone" name="phone" type="tel" inputmode="tel"
               value="<?= e(admin_old($old, 'phone')) ?>" placeholder="+254712345678">
        <?php if (admin_field_error($errors, 'phone') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'phone')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'gate_uuids') !== '' ? ' admin-field--invalid' : '' ?>">
        <p class="admin-field__label">Gates <span class="muted text-xs">At least one</span></p>
        <?php if ($activeGates !== []): ?>
          <div class="admin-check-grid">
            <?php foreach ($activeGates as $gate): ?>
              <?php
              $gateUuid = trim((string) ($gate['uuid'] ?? ''));
              if ($gateUuid === '') {
                  continue;
              }
              $checked = admin_checkbox_checked($selectedGateUuids, $gateUuid);
              ?>
              <label class="admin-check">
                <input type="checkbox" name="gate_uuids[]" value="<?= e($gateUuid) ?>" <?= $checked ? 'checked' : '' ?>>
                <span><?= e(trim((string) ($gate['name'] ?? 'Gate'))) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="admin-field__hint">No active gates are available. Add a gate before creating a guard.</p>
        <?php endif; ?>
        <?php if (admin_field_error($errors, 'gate_uuids') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'gate_uuids')) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add guard</button>
    </div>
  </form>
</section>
<?php endif; ?>
