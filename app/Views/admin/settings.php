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
$appearanceSetting = ['ui.appearance_default' => 'default'];
foreach ($settings as $setting) {
    if (!is_array($setting) || !isset($setting['key'])) {
        continue;
    }
    if ((string) $setting['key'] === 'ui.appearance_default') {
        $appearanceSetting = $setting;
        continue;
    }
    $settingsRows[] = $setting;
}
$appearanceValue = (string) ($old['ui.appearance_default'] ?? $appearanceSetting['value'] ?? 'default');
$appearanceValue = in_array($appearanceValue, ['default', 'light', 'dark'], true) ? $appearanceValue : 'default';
$appearanceError = admin_field_error($errors, 'ui.appearance_default');
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


<section class="admin-section" aria-labelledby="estate-appearance-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="estate-appearance-title">Appearance</h2>
      <p class="admin-section__hint">Estate default app appearance. Residents with a device preference keep it; otherwise the estate app follows this default. <strong>Estate default</strong>.</p>
    </div>
  </div>
  <?php if ($canManage): ?>
    <form method="post" action="/admin/settings" class="admin-form card">
      <?= csrf_field() ?>
      <?php if ($appearanceError !== ''): ?><p class="admin-field__error" role="alert"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($appearanceError) ?></p><?php endif; ?>
      <div class="admin-form__grid">
        <div class="admin-field<?= $appearanceError !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="estate_appearance_default">Default app appearance <span class="muted text-xs">Estate default</span></label>
          <select class="select" id="estate_appearance_default" name="ui.appearance_default">
            <?php foreach (['default' => 'Default (follow device preference)', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $appearanceValue === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="admin-field__hint">Reduced movement: <strong>DashPASS respects the device motion preference</strong>; no separate motion setting is stored.</p>
        </div>
      </div>
      <div class="admin-form__actions"><button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save appearance</button></div>
    </form>
  <?php else: ?>
    <p class="admin-field__hint">Current estate default: <strong><?= e($appearanceValue) ?></strong>. Reduced movement respects the device motion preference.</p>
  <?php endif; ?>
</section>
<?php
/* ---------------------------------------------------------------------------
   Billing settings (Inc-13): utility basis/tariff per utility, channel and
   payment-method subsets (only globally available), schedule defaults and
   past-due escalation. The controller/service supplies $billing; defaults here
   are deliberately conservative and labelled inherited.
   --------------------------------------------------------------------------- */
$billing = isset($billing) && is_array($billing) ? $billing : [];
$billingUtilities = isset($billing['utilities']) && is_array($billing['utilities']) ? $billing['utilities'] : [];
$billingChannels = isset($old['billing.notification_channels']) && is_array($old['billing.notification_channels']) ? array_map('strval', $old['billing.notification_channels']) : (isset($billing['billing.notification_channels']) && is_array($billing['billing.notification_channels']) ? array_map('strval', $billing['billing.notification_channels']) : (isset($billing['channels']) && is_array($billing['channels']) ? array_map('strval', $billing['channels']) : ['email', 'in_app']));
$billingMethods = isset($old['billing.payment_methods']) && is_array($old['billing.payment_methods']) ? array_map('strval', $old['billing.payment_methods']) : (isset($billing['billing.payment_methods']) && is_array($billing['billing.payment_methods']) ? array_map('strval', $billing['billing.payment_methods']) : (isset($billing['payment_methods']) && is_array($billing['payment_methods']) ? array_map('strval', $billing['payment_methods']) : ['mpesa', 'card']));
$billingAvailableChannels = isset($billing['available_channels']) && is_array($billing['available_channels']) ? array_map('strval', $billing['available_channels']) : ['email', 'in_app'];
$billingAvailableMethods = isset($billing['available_payment_methods']) && is_array($billing['available_payment_methods']) ? array_map('strval', $billing['available_payment_methods']) : ['mpesa', 'card'];
$billingSchedule = isset($old['billing.schedule_defaults']) && is_array($old['billing.schedule_defaults']) ? $old['billing.schedule_defaults'] : (isset($billing['billing.schedule_defaults']) && is_array($billing['billing.schedule_defaults']) ? $billing['billing.schedule_defaults'] : (isset($billing['schedule']) && is_array($billing['schedule']) ? $billing['schedule'] : []));
$billingOverrides = isset($billing['overrides']) && is_array($billing['overrides']) ? array_map('strval', $billing['overrides']) : [];
$billingErrors = $errors;
$billingError = static fn (string $path): string => admin_field_error($billingErrors, $path);
$billingValue = static function (string $path, string $fallback = '') use ($billing, $billingUtilities, $billingSchedule, $old): string {
    if (array_key_exists($path, $old) && is_scalar($old[$path])) {
        return trim((string) $old[$path]);
    }
    if (array_key_exists($path, $billing) && is_scalar($billing[$path])) {
        return trim((string) $billing[$path]);
    }
    $segments = explode('.', $path);
    if (isset($segments[0]) && $segments[0] === 'utilities' && isset($segments[1], $segments[2])) {
        $row = $billingUtilities[$segments[1]] ?? [];
        $value = is_array($row) ? ($row[$segments[2]] ?? null) : null;
    } elseif (isset($segments[0]) && in_array($segments[0], ['schedule_defaults', 'schedule'], true)) {
        $value = $billingSchedule[$segments[1] ?? ''] ?? null;
    } else {
        $value = $billingSchedule[$path] ?? ($billing[$path] ?? null);
    }
    return is_scalar($value) ? (string) $value : $fallback;
};
$billingOverrideLabel = static function (string $path) use ($billingOverrides): string {
    return in_array($path, $billingOverrides, true) ? 'Override' : 'Inherited default';
};
$utilityRows = [
    'water' => ['Water', 'm³'],
    'electricity' => ['Electricity', 'kWh'],
    'gas' => ['Gas', 'unit'],
    'other' => ['Other utility', 'unit'],
];
$billingScheduleDefaults = [
    'anchor_day' => ['Anchor day', '1', '1–28; billing period starts on this day.'],
    'due_days' => ['Due in (days)', '14', 'Days after period start before a bill is due.'],
    'reminder_before_days' => ['Reminder before due (days)', '3', 'Send a reminder this many days before the due date.'],
    ];
?>
<section class="admin-section" aria-labelledby="estate-billing-settings-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="estate-billing-settings-title">Billing</h2>
      <p class="admin-section__hint">Estate overrides for utility billing, delivery channels and schedule defaults. Unset values are labelled “Inherited default”.</p>
    </div>
  </div>

  <?php if ($canManage): ?>
    <form method="post" action="/admin/settings" class="admin-form card">
      <?= csrf_field() ?>
      <?= admin_summary($billingErrors) ?>
      <?php if ($billingError('billing') !== ''): ?><p class="admin-field__error" role="alert"><?= e($billingError('billing')) ?></p><?php endif; ?>

      <div class="admin-section__head"><h3 class="admin-section__title">Utility basis &amp; tariff</h3></div>
      <div class="admin-form__grid">
        <?php foreach ($utilityRows as $utility => [$utilityLabel, $unitHint]): ?>
          <?php $basis = $billingValue('billing.utility_basis.' . $utility, 'flat'); ?>
          <fieldset class="admin-field">
            <legend class="admin-field__label"><?= e($utilityLabel) ?> <span class="muted text-xs"><?= e($billingOverrideLabel('billing.utility_basis.' . $utility)) ?></span></legend>
            <label class="admin-field__label" for="billing_<?= e($utility) ?>_basis">Basis</label>
            <select class="select" id="billing_<?= e($utility) ?>_basis" name="billing.utility_basis.<?= e($utility) ?>">
              <?php foreach (['flat' => 'Flat amount', 'metered' => 'Metered', 'both' => 'Flat + metered'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $basis === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="admin-field__label" for="billing_<?= e($utility) ?>_flat">Flat amount (cents)</label>
            <input class="input" id="billing_<?= e($utility) ?>_flat" name="billing.utility_flat_cents.<?= e($utility) ?>" type="number" min="0" step="0.01" value="<?= e($billingValue('billing.utility_flat_cents.' . $utility)) ?>" inputmode="decimal">
            <label class="admin-field__label" for="billing_<?= e($utility) ?>_rate">Unit rate (cents per <?= e($unitHint) ?>)</label>
            <input class="input" id="billing_<?= e($utility) ?>_rate" name="billing.utility_unit_rate_cents.<?= e($utility) ?>" type="number" min="0" step="0.0001" value="<?= e($billingValue('billing.utility_unit_rate_cents.' . $utility)) ?>" inputmode="decimal">
            <?php if ($billingError('billing.utility_basis.' . $utility) !== ''): ?><p class="admin-field__error"><?= e($billingError('billing.utility_basis.' . $utility)) ?></p><?php endif; ?>
          </fieldset>
        <?php endforeach; ?>
      </div>

      <div class="admin-form__grid">
        <fieldset class="admin-field">
          <legend class="admin-field__label">Notification channels <span class="muted text-xs"><?= e($billingOverrideLabel('billing.notification_channels')) ?></span></legend>
          <?php foreach (['email' => 'Email', 'in_app' => 'In-app', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $key => $label): ?>
            <?php if (!in_array($key, $billingAvailableChannels, true)) { continue; } ?>
            <label class="admin-check" for="billing_channel_<?= e($key) ?>">
              <input type="checkbox" id="billing_channel_<?= e($key) ?>" name="billing.notification_channels[]" value="<?= e($key) ?>"
                     <?= in_array($key, $billingChannels, true) || in_array($key, ['email', 'in_app'], true) ? 'checked' : '' ?>
                     <?= in_array($key, ['email', 'in_app'], true) ? 'disabled' : '' ?>>
              <?= e($label) ?><?= in_array($key, ['email', 'in_app'], true) ? ' (always on)' : '' ?>
            </label>
          <?php endforeach; ?>
          <p class="admin-field__hint">Only globally configured channels are shown. SMS/WhatsApp require a platform gateway.</p>
        </fieldset>

        <fieldset class="admin-field">
          <legend class="admin-field__label">Payment methods <span class="muted text-xs"><?= e($billingOverrideLabel('billing.payment_methods')) ?></span></legend>
          <?php foreach (['mpesa' => 'M-Pesa', 'card' => 'Card'] as $key => $label): ?>
            <?php if (!in_array($key, $billingAvailableMethods, true)) { continue; } ?>
            <label class="admin-check" for="billing_method_<?= e($key) ?>">
              <input type="checkbox" id="billing_method_<?= e($key) ?>" name="billing.payment_methods[]" value="<?= e($key) ?>" <?= in_array($key, $billingMethods, true) ? 'checked' : '' ?>>
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
          <p class="admin-field__hint">Only methods enabled globally by the platform appear here.</p>
        </fieldset>
      </div>

      <div class="admin-section__head"><h3 class="admin-section__title">Schedule defaults</h3></div>
      <div class="admin-form__grid">
        <?php foreach ($billingScheduleDefaults as $key => [$label, $default, $hint]): ?>
          <div class="admin-field">
            <label class="admin-field__label" for="billing_schedule_<?= e($key) ?>"><?= e($label) ?> <span class="muted text-xs"><?= e($billingOverrideLabel('billing.schedule_defaults.' . $key)) ?></span></label>
            <input class="input" id="billing_schedule_<?= e($key) ?>" name="billing.schedule_defaults[<?= e($key) ?>]" type="number" min="0" max="90" value="<?= e($billingValue($key, $default)) ?>" inputmode="numeric">
            <p class="admin-field__hint"><?= e($hint) ?></p>
          </div>
        <?php endforeach; ?>
        <div class="admin-field">
          <label class="admin-field__label" for="billing_reminder_on_due">Reminder on due date</label>
          <label class="admin-check"><input type="checkbox" id="billing_reminder_on_due" name="billing.schedule_defaults[remind_on_due]" value="1" <?= ($billingValue('reminder_on_due', '1') === '1') ? 'checked' : '' ?>> Send on the due date</label>
        </div>
        <div class="admin-field">
          <label class="admin-field__label" for="billing_past_due_escalation">Past-due escalation days (JSON list)</label>
          <input class="input" id="billing_past_due_escalation" name="billing.schedule_defaults[past_due_escalation]" type="text" value="<?= e($billingValue('billing.schedule_defaults.past_due_escalation', '[7,14,30]')) ?>">
          <p class="admin-field__hint">Days after the due date at which reminders/escalations fire; JSON list, e.g. [7,14,30].</p>
        </div>
      </div>

      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save billing settings</button>
      </div>
    </form>
  <?php else: ?>
    <p class="admin-field__hint">You have read-only access to billing settings.</p>
  <?php endif; ?>
</section>
