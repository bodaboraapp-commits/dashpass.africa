<?php

declare(strict_types=1);

/**
 * Admin estate settings: the allowlisted keys only (contract §2/§3, §7).
 *
 * Variables: $settings [{key,label,value,min,max,type}], $errors + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));

$settings = isset($settings) && is_array($settings) ? $settings : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'estate.settings.manage');

$impactCopy = [
    'visitor_validity_minutes' => 'Default validity window for new visitor invitations. Longer windows keep visitor PINs and pass links usable for longer.',
    'visitor_parking_grace_minutes' => 'Minutes of grace after a visitor parking window ends before the bay is considered overstaying.',
];

$settingsRows = [];
foreach ($settings as $setting) {
    if (is_array($setting) && isset($setting['key'])) {
        $settingsRows[] = $setting;
    }
}
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Settings</h1>
    <p class="admin-page__lede">
      A deliberately small, allowlisted set of estate defaults
      <?php if ($estateName !== ''): ?>
        for <?= e($estateName) ?>
      <?php endif; ?>.
      Other settings stay under platform control in this release.
    </p>
  </div>
</div>

<section class="admin-section" aria-labelledby="estate-settings-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="estate-settings-title">Estate defaults</h2>
      <p class="admin-section__hint">Changes apply to future invitations and parking sessions.</p>
    </div>
  </div>

  <?php if ($settingsRows !== []): ?>
    <?php if ($canManage): ?>
      <form method="post" action="/admin/settings" class="admin-form card">
        <?= csrf_field() ?>
        <?= admin_summary($errors) ?>

        <ul class="admin-settings">
          <?php foreach ($settingsRows as $setting): ?>
            <?php
            $key = trim((string) ($setting['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string) ($setting['label'] ?? '')) ?: admin_humanize($key);
            $value = $setting['value'] ?? '';
            $min = $setting['min'] ?? null;
            $max = $setting['max'] ?? null;
            $type = strtolower(trim((string) ($setting['type'] ?? 'number')));
            $isNumber = in_array($type, ['int', 'integer', 'number', 'numeric'], true);
            $impact = $impactCopy[$key] ?? 'Changing this value affects estate behaviour for new records only.';
            $fieldError = admin_field_error($errors, $key);
            ?>
            <li class="admin-settings__item">
              <div class="admin-settings__body">
                <label class="admin-field__label" for="setting_<?= e($key) ?>"><?= e($label) ?></label>
                <p class="admin-field__hint"><?= e($impact) ?></p>
                <p class="admin-settings__current">
                  Current value: <strong class="mono"><?= e(is_scalar($value) ? (string) $value : '—') ?></strong>
                </p>
                <p class="admin-settings__key"><span class="mono"><?= e($key) ?></span>
                  <?php if ($isNumber && ($min !== null || $max !== null)): ?>
                    <span class="muted text-xs">
                      <?php if ($min !== null): ?>Min <?= e((string) $min) ?><?php endif; ?>
                      <?php if ($min !== null && $max !== null): ?> · <?php endif; ?>
                      <?php if ($max !== null): ?>Max <?= e((string) $max) ?><?php endif; ?>
                    </span>
                  <?php endif; ?>
                </p>
                <?php if ($fieldError !== ''): ?>
                  <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError) ?></p>
                <?php endif; ?>
              </div>
              <div class="admin-settings__control">
                <input class="input<?= $fieldError !== '' ? ' input--invalid' : '' ?>"
                       id="setting_<?= e($key) ?>" name="<?= e($key) ?>"
                       type="<?= $isNumber ? 'number' : 'text' ?>"
                       <?php if ($isNumber && $min !== null): ?>min="<?= e((string) $min) ?>"<?php endif; ?>
                       <?php if ($isNumber && $max !== null): ?>max="<?= e((string) $max) ?>"<?php endif; ?>
                       <?php if ($isNumber): ?>step="1" inputmode="numeric"<?php endif; ?>
                       value="<?= e(is_scalar($value) ? (string) $value : '') ?>"
                       aria-describedby="setting_<?= e($key) ?>-hint">
                <p class="visually-hidden" id="setting_<?= e($key) ?>-hint"><?= e($impact) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="admin-form__actions">
          <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save settings</button>
        </div>
      </form>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Setting</th>
              <th scope="col">Value</th>
              <th scope="col">Impact</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($settingsRows as $setting): ?>
              <?php
              $key = trim((string) ($setting['key'] ?? ''));
              if ($key === '') {
                  continue;
              }
              $label = trim((string) ($setting['label'] ?? '')) ?: admin_humanize($key);
              $value = $setting['value'] ?? '';
              $impact = $impactCopy[$key] ?? 'Controlled by the estate administrator.';
              ?>
              <tr>
                <td data-label="Setting"><?= e($label) ?></td>
                <td data-label="Value"><?= e(is_scalar($value) ? (string) $value : '—') ?></td>
                <td data-label="Impact"><?= e($impact) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="admin-field__hint">You have read-only access to estate settings.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('lock') ?></span>
      <p class="admin-empty__title">No editable settings listed</p>
      <p class="admin-empty__text">Estate defaults are managed by the platform in this release.</p>
    </div>
  <?php endif; ?>
</section>
