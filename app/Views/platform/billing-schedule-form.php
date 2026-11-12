<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$schedule = isset($schedule) && is_array($schedule) ? $schedule : [];
$estates = isset($estates) && is_array($estates) ? $estates : [];
$subscriptions = isset($subscriptions) && is_array($subscriptions) ? $subscriptions : [];
$households = isset($households) && is_array($households) ? $households : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$isEdit = trim((string) ($schedule['uuid'] ?? '')) !== '';
$value = static function (string $key, string $fallback = '') use ($old, $schedule): string {
    if (array_key_exists($key, $old) && is_scalar($old[$key])) { return trim((string) $old[$key]); }
    $v = $schedule[$key] ?? $fallback;
    return is_scalar($v) ? trim((string) $v) : $fallback;
};
$err = static function (string $key) use ($errors): string {
    $m = $errors[$key] ?? [];
    return is_array($m) && $m !== [] ? (string) reset($m) : '';
};
$uuid = trim((string) ($schedule['uuid'] ?? ''));
$action = $isEdit ? '/platform/billing/schedules/' . rawurlencode($uuid) . '/edit' : '/platform/billing/schedules';
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform billing</p><h1 class="platform-page__title"><?= $isEdit ? 'Edit billing schedule' : 'New billing schedule' ?></h1><p class="platform-page__lede">Subscription or utility schedule. Next run is calculated by the backend from the timing defaults.</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform/billing/schedules">Back to schedules</a>
  </header>
  <?php if ($errors !== []): ?><div class="alert alert--danger" role="alert"><div class="alert__body"><p class="alert__title">Check the schedule</p><p><?= e((string) (platform_error_list($errors)[0] ?? 'Some values could not be saved.')) ?></p></div></div><?php endif; ?>

  <form method="post" action="<?= e($action) ?>" class="card admin-form">
    <?= csrf_field() ?>
    <div class="admin-form__grid">
      <div class="admin-field<?= $err('scope') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="schedule_scope">Scope</label>
        <select class="select" id="schedule_scope" name="scope">
          <?php foreach (['estate_subscription' => 'Estate subscription', 'utility_bill' => 'Utility bill'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $value('scope', 'estate_subscription') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('scope') !== ''): ?><p class="admin-field__error"><?= e($err('scope')) ?></p><?php endif; ?>
      </div>
      <div class="admin-field<?= $err('estate_uuid') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="schedule_estate">Estate</label>
        <select class="select" id="schedule_estate" name="estate_uuid">
          <option value="">Choose estate</option>
          <?php foreach ($estates as $estate): ?><?php $eu = (string) ($estate['uuid'] ?? ''); ?>
            <option value="<?= e($eu) ?>" <?= $value('estate_uuid', (string) ($schedule['estate_uuid'] ?? '')) === $eu ? 'selected' : '' ?>><?= e((string) ($estate['name'] ?? $eu)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('estate_uuid') !== ''): ?><p class="admin-field__error"><?= e($err('estate_uuid')) ?></p><?php endif; ?>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_name">Name</label>
        <input class="input" id="schedule_name" name="name" type="text" maxlength="120" value="<?= e($value('name')) ?>">
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_subscription">Subscription (optional)</label>
        <select class="select" id="schedule_subscription" name="subscription_id">
          <option value="">None</option>
          <?php foreach ($subscriptions as $sub): ?><?php $sid = (string) ($sub['id'] ?? ''); ?>
            <option value="<?= e($sid) ?>" <?= $value('subscription_id') === $sid ? 'selected' : '' ?>><?= e((string) ($sub['estate_name'] ?? '')) ?> · <?= e((string) ($sub['plan_name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_household">Household (utility, optional)</label>
        <select class="select" id="schedule_household" name="household_id">
          <option value="">Estate-wide</option>
          <?php foreach ($households as $home): ?><?php $hid = (string) ($home['id'] ?? ''); ?>
            <option value="<?= e($hid) ?>" <?= $value('household_id') === $hid ? 'selected' : '' ?>><?= e((string) ($home['unit_number'] ?? $hid)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_utility">Utility type</label>
        <select class="select" id="schedule_utility" name="utility_type">
          <option value="">None</option>
          <?php foreach (['water' => 'Water', 'electricity' => 'Electricity', 'gas' => 'Gas', 'other' => 'Other'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $value('utility_type') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_flat">Flat amount (cents)</label>
        <input class="input" id="schedule_flat" name="flat_amount_cents" type="number" min="0" value="<?= e($value('flat_amount_cents', '0')) ?>">
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_tariff">Unit rate (cents per unit)</label>
        <input class="input" id="schedule_tariff" name="tariff_cents_per_unit" type="number" min="0" value="<?= e($value('tariff_cents_per_unit', '0')) ?>">
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="schedule_period">Period</label>
        <select class="select" id="schedule_period" name="period_unit">
          <?php foreach (['month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Yearly', 'week' => 'Weekly', 'once' => 'Once'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $value('period_unit', 'month') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field"><label class="admin-field__label" for="schedule_lead">Generate lead days</label><input class="input" id="schedule_lead" name="generate_lead_days" type="number" min="0" max="30" value="<?= e($value('generate_lead_days', '0')) ?>"></div>
      <div class="admin-field"><label class="admin-field__label" for="schedule_due">Due days</label><input class="input" id="schedule_due" name="due_days" type="number" min="0" max="60" value="<?= e($value('due_days', '14')) ?>"></div>
      <div class="admin-field"><label class="admin-field__label" for="schedule_reminder">Reminder before due (days)</label><input class="input" id="schedule_reminder" name="reminder_before_days" type="number" min="0" max="30" value="<?= e($value('reminder_before_days', '3')) ?>"></div>
      <div class="admin-field"><label class="admin-check"><input type="checkbox" name="reminder_on_due" value="1" <?= $value('reminder_on_due', '1') === '1' ? 'checked' : '' ?>> Reminder on due date</label></div>
    </div>
    <div class="admin-form__actions"><button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save changes' : 'Create schedule' ?></button><a class="btn btn--ghost" href="/platform/billing/schedules">Cancel</a></div>
  </form>

  <?php if ($isEdit): ?>
    <div class="admin-form__grid">
      <form method="post" action="/platform/billing/schedules/<?= e(rawurlencode($uuid)) ?>/toggle" class="card admin-form"><?= csrf_field() ?><div class="admin-form__actions"><button class="btn btn--outline" type="submit"><?= (string) ($schedule['status'] ?? '') === 'active' ? 'Pause schedule' : 'Resume schedule' ?></button></div></form>
      <form method="post" action="/platform/billing/schedules/<?= e(rawurlencode($uuid)) ?>/run-now" class="card admin-form"><?= csrf_field() ?><p class="admin-field__hint">Dry-run preview only; no invoices are generated.</p><div class="admin-form__actions"><button class="btn btn--ghost" type="submit">Run now (dry-run)</button></div></form>
    </div>
  <?php endif; ?>
</div>
