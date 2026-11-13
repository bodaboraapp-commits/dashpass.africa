<?php

declare(strict_types=1);

/**
 * Platform estate provisioning form (platform-admin contract §2/§3).
 *
 * Creates the account (existing or new), estate, default settings, first estate
 * admin user/membership and optional plan subscription in one transaction
 * server-side. Passwordless: the admin signs in later with OTP, so no password
 * fields exist here.
 *
 * Variables (T64 frozen keys, defensive): $accounts [], $plans [],
 * $timezones [], $estate_types [], $errors [], $old [] + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$accounts = isset($accounts) && is_array($accounts) ? $accounts : [];
$plans = isset($plans) && is_array($plans) ? $plans : [];
$timezones = isset($timezones) && is_array($timezones) ? $timezones : [];
$estateTypes = isset($estate_types) && is_array($estate_types) ? $estate_types : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$accountOptions = [];
foreach ($accounts as $account) {
    if (!is_array($account)) {
        continue;
    }
    $uuid = platform_text($account, ['uuid']);
    if ($uuid !== '') {
        // T64 accountOptions use {uuid,label}; accept name/account_name aliases too.
        $accountOptions[$uuid] = platform_text($account, ['label', 'name', 'account_name'], 'Account');
    }
}
$planOptions = [];
foreach ($plans as $plan) {
    if (!is_array($plan)) {
        continue;
    }
    $uuid = platform_text($plan, ['uuid']);
    if ($uuid === '') {
        continue;
    }
    $planStatus = platform_text($plan, ['status']);
    if ($planStatus === 'retired') {
        continue;
    }
    $label = platform_text($plan, ['label', 'name', 'plan_name', 'code'], 'Plan');
    $code = platform_text($plan, ['code']);
    $planOptions[$uuid] = $code !== '' && $code !== $label ? $label . ' (' . $code . ')' : $label;
}
$timezoneMap = platform_option_map($timezones, [
    'Africa/Nairobi' => 'Africa/Nairobi',
    'Africa/Kampala' => 'Africa/Kampala',
    'Africa/Dar_es_Salaam' => 'Africa/Dar_es_Salaam',
    'Africa/Kigali' => 'Africa/Kigali',
    'Africa/Addis_Ababa' => 'Africa/Addis_Ababa',
    'UTC' => 'UTC',
]);
$estateTypeMap = platform_option_map($estateTypes, [
    'residential' => 'Residential',
    'mixed' => 'Mixed use',
    'commercial' => 'Commercial',
    'apartment' => 'Apartment block',
]);

$oldValue = static function (string $field, string $default = '') use ($old): string {
    $candidate = $old[$field] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};
$accountMode = $oldValue('account_mode', 'existing');
if (!in_array($accountMode, ['existing', 'new'], true)) {
    $accountMode = $accountOptions !== [] ? 'existing' : 'new';
}
?>
<div class="platform-page__head">
  <div>
    <nav class="platform-breadcrumb" aria-label="Breadcrumb">
      <a href="/platform/estates">Estates</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Provision</span>
    </nav>
    <h1 class="platform-page__title">Provision an estate</h1>
    <p class="platform-page__lede">
      Create the estate, its first administrator, default settings and optional subscription. The new admin signs in
      with their phone number via one-time code.
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<form method="post" action="/platform/estates" class="platform-form card">
  <?= csrf_field() ?>

  <section class="platform-section" aria-labelledby="provision-account-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="provision-account-title">Billing account</h2>
      <p class="platform-section__hint">Estates belong to a SaaS account. Link an existing one or create a new account.</p>
    </div>

    <fieldset class="platform-fieldset<?= platform_field_error($errors, 'account_uuid') !== '' || platform_field_error($errors, 'account_name') !== '' ? ' platform-fieldset--invalid' : '' ?>">
      <legend class="platform-field__label">Account</legend>
      <div class="platform-radio-row">
        <label class="platform-radio">
          <input type="radio" name="account_mode" value="existing" <?= $accountMode === 'existing' ? 'checked' : '' ?> <?= $accountOptions === [] ? 'disabled' : '' ?>>
          <span>Use an existing account</span>
        </label>
        <label class="platform-radio">
          <input type="radio" name="account_mode" value="new" <?= $accountMode === 'new' ? 'checked' : '' ?>>
          <span>Create a new account</span>
        </label>
      </div>

      <?php if ($accountOptions !== []): ?>
        <div class="platform-field<?= platform_field_error($errors, 'account_uuid') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="account_uuid">Existing account</label>
          <select class="select" id="account_uuid" name="account_uuid">
            <option value="">Choose an account</option>
            <?php foreach ($accountOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('account_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (platform_field_error($errors, 'account_uuid') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'account_uuid')) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="platform-field<?= platform_field_error($errors, 'account_name') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="account_name">
          New account name
          <span class="muted text-xs">Only when creating a new account</span>
        </label>
        <input class="input<?= platform_field_error($errors, 'account_name') !== '' ? ' input--invalid' : '' ?>" id="account_name"
               name="account_name" type="text" maxlength="160" value="<?= e($oldValue('account_name')) ?>"
               placeholder="e.g. Greenview Holdings">
        <?php if (platform_field_error($errors, 'account_name') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'account_name')) ?></p>
        <?php endif; ?>
      </div>
    </fieldset>
  </section>

  <section class="platform-section" aria-labelledby="provision-estate-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="provision-estate-title">Estate details</h2>
    </div>

    <div class="platform-form__grid">
      <div class="platform-field<?= platform_field_error($errors, 'estate_name') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="estate_name">Estate name</label>
        <input class="input<?= platform_field_error($errors, 'estate_name') !== '' ? ' input--invalid' : '' ?>" id="estate_name"
               name="estate_name" type="text" maxlength="180" required value="<?= e($oldValue('estate_name')) ?>"
               placeholder="e.g. Garden Court Apartments">
        <p class="platform-field__hint">A unique URL slug is generated from the name.</p>
        <?php if (platform_field_error($errors, 'estate_name') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'estate_name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'timezone') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="estate_timezone">Timezone</label>
        <select class="select" id="estate_timezone" name="timezone" required>
          <?php $selectedTimezone = $oldValue('timezone', 'Africa/Nairobi'); ?>
          <?php foreach ($timezoneMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $selectedTimezone === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (platform_field_error($errors, 'timezone') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'timezone')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'estate_type') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="estate_type">Estate type</label>
        <select class="select" id="estate_type" name="estate_type" required>
          <?php $selectedType = $oldValue('estate_type', 'residential'); ?>
          <?php foreach ($estateTypeMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $selectedType === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (platform_field_error($errors, 'estate_type') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'estate_type')) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($planOptions !== []): ?>
        <div class="platform-field<?= platform_field_error($errors, 'plan_uuid') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_uuid">
            Plan
            <span class="muted text-xs">Optional</span>
          </label>
          <select class="select" id="plan_uuid" name="plan_uuid">
            <option value="">No subscription yet</option>
            <?php foreach ($planOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('plan_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="platform-field__hint">Creates a trialing subscription for the estate.</p>
          <?php if (platform_field_error($errors, 'plan_uuid') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'plan_uuid')) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="platform-section" aria-labelledby="provision-admin-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="provision-admin-title">First estate administrator</h2>
      <p class="platform-section__hint">This person will manage the estate. No password, they sign in with an OTP sent to this phone.</p>
    </div>

    <div class="platform-form__grid">
      <div class="platform-field<?= platform_field_error($errors, 'admin_full_name') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="admin_full_name">Administrator full name</label>
        <input class="input<?= platform_field_error($errors, 'admin_full_name') !== '' ? ' input--invalid' : '' ?>" id="admin_full_name"
               name="admin_full_name" type="text" maxlength="180" required value="<?= e($oldValue('admin_full_name')) ?>"
               placeholder="Full name">
        <?php if (platform_field_error($errors, 'admin_full_name') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'admin_full_name')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'admin_phone') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="admin_phone">Administrator phone</label>
        <div class="input-group">
          <span class="input-group__prefix" aria-hidden="true">+254</span>
          <input class="input<?= platform_field_error($errors, 'admin_phone') !== '' ? ' input--invalid' : '' ?>" id="admin_phone"
                 name="admin_phone" type="tel" inputmode="tel" value="<?= e($oldValue('admin_phone')) ?>"
                 placeholder="712 345 678" autocomplete="off">
        </div>
        <?php if (platform_field_error($errors, 'admin_phone') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'admin_phone')) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="platform-section" aria-labelledby="provision-settings-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="provision-settings-title">Default estate settings</h2>
    </div>
    <div class="platform-form__grid">
      <div class="platform-field<?= platform_field_error($errors, 'visitor_validity_minutes') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="visitor_validity_minutes">Visitor pass validity (minutes)</label>
        <input class="input" id="visitor_validity_minutes" name="visitor_validity_minutes" type="number" min="5" max="1440"
               value="<?= e($oldValue('visitor_validity_minutes', '240')) ?>">
        <?php if (platform_field_error($errors, 'visitor_validity_minutes') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'visitor_validity_minutes')) ?></p>
        <?php endif; ?>
      </div>
      <div class="platform-field<?= platform_field_error($errors, 'parking_grace_minutes') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="parking_grace_minutes">Visitor parking grace (minutes)</label>
        <input class="input" id="parking_grace_minutes" name="parking_grace_minutes" type="number" min="0" max="120"
               value="<?= e($oldValue('parking_grace_minutes', '30')) ?>">
        <?php if (platform_field_error($errors, 'parking_grace_minutes') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'parking_grace_minutes')) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="platform-form__actions">
    <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Provision estate</button>
    <a class="btn btn--ghost" href="/platform/estates">Cancel</a>
  </div>
</form>
