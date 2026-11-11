<?php

declare(strict_types=1);

/**
 * Admin gates list + add/edit forms + archive (contract §3, §7).
 *
 * Variables: $gates [{uuid,name,location,status,status_label,status_tone,
 * guard_count}], $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);

$gates = isset($gates) && is_array($gates) ? $gates : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'gate.manage');

$error = static fn (string $field): string => admin_field_error($errors, $field);
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Gates</h1>
    <p class="admin-page__lede">Entry points on the estate. Archived gates disappear from guard assignment selectors.</p>
  </div>
</div>

<section class="admin-section" aria-labelledby="gates-list-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="gates-list-title">Estate gates</h2>
    <?php if ($gates !== []): ?>
      <span class="chip"><?= e((string) count($gates)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($gates !== []): ?>
    <ul class="admin-card-list">
      <?php foreach ($gates as $gate): ?>
        <?php
        if (!is_array($gate)) {
            continue;
        }
        $gateUuid = trim((string) ($gate['uuid'] ?? ''));
        $name = trim((string) ($gate['name'] ?? ''));
        $location = trim((string) ($gate['location'] ?? ''));
        $status = trim((string) ($gate['status'] ?? 'active'));
        $statusLabel = trim((string) ($gate['status_label'] ?? '')) ?: admin_humanize($status);
        $statusTone = trim((string) ($gate['status_tone'] ?? ''));
        $guardCount = isset($gate['guard_count']) ? max(0, (int) $gate['guard_count']) : 0;
        $activeGate = $status === 'active';
        ?>
        <li class="admin-card admin-gate">
          <div class="admin-card__head">
            <div>
              <p class="admin-card__title"><?= e($name !== '' ? $name : 'Gate') ?></p>
              <p class="admin-card__meta">
                <?= e($location !== '' ? $location : 'No location set') ?>
                · <?= e((string) $guardCount) ?> guard<?= $guardCount === 1 ? '' : 's' ?> assigned
              </p>
            </div>
            <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
          </div>

          <?php if ($canManage && $gateUuid !== ''): ?>
            <form method="post" action="/admin/gates/<?= e(rawurlencode($gateUuid)) ?>" class="admin-gate__edit">
              <?= csrf_field() ?>
              <?= admin_summary($errors) ?>
              <div class="admin-form__grid admin-form__grid--inline">
                <div class="admin-field<?= $error('name') !== '' ? ' admin-field--invalid' : '' ?>">
                  <label class="admin-field__label" for="gate_name_<?= e($gateUuid) ?>">Name</label>
                  <input class="input" id="gate_name_<?= e($gateUuid) ?>" name="name" type="text" maxlength="120" required
                         value="<?= e(admin_old($old, 'name', $name)) ?>">
                  <?php if ($error('name') !== ''): ?>
                    <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($error('name')) ?></p>
                  <?php endif; ?>
                </div>
                <div class="admin-field">
                  <label class="admin-field__label" for="gate_location_<?= e($gateUuid) ?>">Location <span class="muted text-xs">Optional</span></label>
                  <input class="input" id="gate_location_<?= e($gateUuid) ?>" name="location" type="text" maxlength="180"
                         value="<?= e(admin_old($old, 'location', $location)) ?>">
                </div>
              </div>
              <div class="admin-form__actions">
                <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('check', 'ico ico--sm') ?> Save changes</button>
              </div>
            </form>

            <?php if ($activeGate): ?>
              <form method="post" action="/admin/gates/<?= e(rawurlencode($gateUuid)) ?>/archive"
                    data-confirm="Archive this gate? It will no longer be offered for guard assignments.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--danger-ghost btn--sm"
                        aria-label="Archive <?= e($name !== '' ? $name : 'this gate') ?>">Archive gate</button>
              </form>
            <?php else: ?>
              <p class="admin-field__hint">Archived gates can be edited, but new guard assignments are blocked. Gate status is managed by estate operations.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="admin-field__hint">You have read-only access to gates.</p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('gate') ?></span>
      <p class="admin-empty__title">No gates yet</p>
      <p class="admin-empty__text">Add the estate&rsquo;s entry gates below so guards can be assigned to them.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="admin-section" aria-labelledby="add-gate-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="add-gate-title">Add a gate</h2>
      <p class="admin-section__hint">Gate names are unique per estate.</p>
    </div>
  </div>

  <form method="post" action="/admin/gates" class="admin-form card">
    <?= csrf_field() ?>
    <?= admin_summary($errors) ?>

    <div class="admin-form__grid">
      <div class="admin-field<?= $error('name') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="gate_name">Gate name</label>
        <input class="input<?= $error('name') !== '' ? ' input--invalid' : '' ?>"
               id="gate_name" name="name" type="text" maxlength="120" required
               value="<?= e(admin_old($old, 'name')) ?>" placeholder="Gate name">
        <?php if ($error('name') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($error('name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field">
        <label class="admin-field__label" for="gate_location">Location <span class="muted text-xs">Optional</span></label>
        <input class="input" id="gate_location" name="location" type="text" maxlength="180"
               value="<?= e(admin_old($old, 'location')) ?>" placeholder="North entrance, off Mombasa Road">
      </div>
    </div>

    <div class="admin-form__actions">
      <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Add gate</button>
    </div>
  </form>
</section>
<?php endif; ?>
