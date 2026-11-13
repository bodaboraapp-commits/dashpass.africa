<?php

declare(strict_types=1);

/**
 * Platform estate detail (platform-admin contract §3).
 *
 * Estate overview, current plan/subscription summary, estate administrators and
 * a status form (active/suspended/archived). No hard delete, status only.
 *
 * Variables (T64 frozen keys, defensive): $estate detail, $subscription|null,
 * $admins [], $status_options [], $errors [], $old [] + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$shellEstate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($shellEstate);
// The detail record may arrive as $estate_record / $record, or as $estate when
// the controller renders this screen (the shell estate has no uuid).
$detail = [];
if (isset($estate_record) && is_array($estate_record)) {
    $detail = $estate_record;
} elseif (isset($record) && is_array($record)) {
    $detail = $record;
} elseif (isset($estate) && is_array($estate) && trim((string) ($estate['uuid'] ?? '')) !== '') {
    $detail = $estate;
}
$subscription = isset($subscription) && is_array($subscription) ? $subscription : [];
$admins = isset($admins) && is_array($admins) ? $admins : [];
// T64 detail payload exposes a single `account` and `admin` record.
$account = isset($account) && is_array($account) ? $account : [];
$adminRecord = isset($admin) && is_array($admin) ? $admin : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$uuid = platform_text($detail, ['uuid']);
$name = platform_text($detail, ['name', 'estate_name'], 'Estate');
$slug = platform_text($detail, ['slug']);
$status = platform_text($detail, ['status'], 'active');
$statusLabel = platform_text($detail, ['status_label']) ?: platform_humanize($status);
$statusTone = platform_text($detail, ['status_tone']);
$accountName = platform_text($detail, ['account_name', 'account_label']);
if ($accountName === '') {
    $accountName = platform_text($account, ['name', 'account_name', 'label']);
}
$accountStatus = platform_text($account, ['status']);
$planName = platform_text($detail, ['plan_name']);
$estateTimezone = platform_text($detail, ['timezone', 'estate_timezone']);
$estateType = platform_text($detail, ['estate_type', 'type']);
$estateTypeLabel = platform_text($detail, ['estate_type_label']) ?: platform_humanize($estateType);
$createdAt = platform_text($detail, ['created_at', 'provisioned_at']);
$householdsCount = platform_stat($detail, ['households_count', 'household_count']);
$residentsCount = platform_stat($detail, ['residents_count', 'resident_count']);
$adminName = platform_text($detail, ['admin_name', 'estate_admin_name']);
$adminPhone = platform_text($detail, ['admin_phone_masked', 'admin_phone']);
if ($adminName === '') {
    $adminName = platform_text($adminRecord, ['full_name', 'name']);
}
if ($adminPhone === '') {
    $adminPhone = platform_text($adminRecord, ['phone_masked', 'phone']);
}

$statusMap = platform_option_map($statusOptions, [
    'active' => 'Active',
    'suspended' => 'Suspended',
    'archived' => 'Archived',
]);
$selectedStatus = trim((string) ($old['status'] ?? $status));
$subscriptionStatus = platform_text($subscription, ['status', 'status_label']);
$subscriptionPlan = platform_text($subscription, ['plan_name', 'plan'], $planName);
?>
<div class="platform-page__head">
  <div>
    <nav class="platform-breadcrumb" aria-label="Breadcrumb">
      <a href="/platform/estates">Estates</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($name) ?></span>
    </nav>
    <h1 class="platform-page__title"><?= e($name) ?></h1>
    <p class="platform-page__lede">
      <?php if ($slug !== ''): ?><span class="mono"><?= e($slug) ?></span> · <?php endif; ?>
      <?= e($accountName !== '' ? $accountName : 'No account linked') ?>
    </p>
  </div>
  <div class="platform-page__actions">
    <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
    <a class="btn btn--ghost btn--sm" href="/platform/estates">All estates</a>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="platform-section" aria-labelledby="estate-overview-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="estate-overview-title">Estate overview</h2>
  </div>

  <?php if ($uuid === '' && $name === 'Estate'): ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('building') ?></span>
      <p class="platform-empty__title">Estate unavailable</p>
      <p class="platform-empty__text">This estate could not be loaded. Return to the estates list and try again.</p>
      <a class="btn btn--outline" href="/platform/estates">Back to estates</a>
    </div>
  <?php else: ?>
    <div class="platform-card">
      <dl class="platform-detail-grid">
        <div class="platform-detail-grid__item">
          <dt>Account</dt>
          <dd><?= e($accountName !== '' ? $accountName : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Plan</dt>
          <dd><?= e($planName !== '' ? $planName : ($subscriptionPlan !== '' ? $subscriptionPlan : 'No plan')) ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Timezone</dt>
          <dd><?= e($estateTimezone !== '' ? $estateTimezone : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Estate type</dt>
          <dd><?= e($estateTypeLabel !== '' ? $estateTypeLabel : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Households</dt>
          <dd><?= e($householdsCount !== null ? (string) $householdsCount : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Residents</dt>
          <dd><?= e($residentsCount !== null ? (string) $residentsCount : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Provisioned</dt>
          <dd><?= e($createdAt !== '' ? admin_datetime($createdAt, $timezone) : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Estate admin</dt>
          <dd>
            <?= e($adminName !== '' ? $adminName : '-') ?>
            <?php if ($adminPhone !== ''): ?>
              <span class="platform-table__sub mono"><?= e($adminPhone) ?></span>
            <?php endif; ?>
          </dd>
        </div>
      </dl>
    </div>
  <?php endif; ?>
</section>

<section class="platform-section" aria-labelledby="estate-subscription-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="estate-subscription-title">Subscription</h2>
    <a class="platform-section__link" href="/platform/subscriptions">Manage subscriptions</a>
  </div>

  <?php if ($subscription === [] && $planName === ''): ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('activity') ?></span>
      <p class="platform-empty__title">No subscription yet</p>
      <p class="platform-empty__text">Assign a plan from the subscriptions screen when this estate is ready to go live.</p>
    </div>
  <?php else: ?>
    <div class="platform-card">
      <dl class="platform-detail-grid">
        <div class="platform-detail-grid__item">
          <dt>Plan</dt>
          <dd><?= e($subscriptionPlan !== '' ? $subscriptionPlan : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Status</dt>
          <dd><?= e($subscriptionStatus !== '' ? platform_humanize($subscriptionStatus) : '-') ?></dd>
        </div>
        <?php
        $startsAt = platform_text($subscription, ['starts_at', 'started_at']);
        $endsAt = platform_text($subscription, ['ends_at', 'current_period_end']);
        ?>
        <div class="platform-detail-grid__item">
          <dt>Started</dt>
          <dd><?= e($startsAt !== '' ? admin_datetime($startsAt, $timezone) : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Renews / ends</dt>
          <dd><?= e($endsAt !== '' ? admin_datetime($endsAt, $timezone) : '-') ?></dd>
        </div>
      </dl>
    </div>
  <?php endif; ?>
</section>

<section class="platform-section" aria-labelledby="estate-admins-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="estate-admins-title">Estate administrators</h2>
  </div>

  <?php if ($admins !== []): ?>
    <ul class="platform-list">
      <?php foreach ($admins as $admin): ?>
        <?php
        if (!is_array($admin)) {
            continue;
        }
        $adminLabel = platform_text($admin, ['full_name', 'name'], 'Administrator');
        $adminPhoneMasked = platform_text($admin, ['phone_masked', 'phone']);
        ?>
        <li class="platform-row">
          <span class="platform-row__icon" aria-hidden="true"><?= admin_icon('account') ?></span>
          <span class="platform-row__body">
            <span class="platform-row__title"><?= e($adminLabel) ?></span>
            <?php if ($adminPhoneMasked !== ''): ?>
              <span class="platform-row__sub mono"><?= e($adminPhoneMasked) ?></span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php elseif ($adminName !== ''): ?>
    <div class="platform-card">
      <p class="platform-row__title"><?= e($adminName) ?></p>
      <?php if ($adminPhone !== ''): ?>
        <p class="platform-row__sub mono"><?= e($adminPhone) ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('users') ?></span>
      <p class="platform-empty__title">No administrator listed</p>
      <p class="platform-empty__text">Provisioning creates the first estate admin; additional admins are managed inside the estate.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($uuid !== ''): ?>
  <section class="platform-section" aria-labelledby="estate-status-title">
    <div class="platform-section__head">
      <div>
        <h2 class="platform-section__title" id="estate-status-title">Estate status</h2>
        <p class="platform-section__hint">Archived estates disappear from estate switchers but their data is retained, nothing is deleted.</p>
      </div>
    </div>

    <form method="post" action="/platform/estates/<?= e(rawurlencode($uuid)) ?>/status" class="platform-form card">
      <?= csrf_field() ?>
      <div class="platform-form__grid platform-form__grid--inline">
        <div class="platform-field">
          <label class="platform-field__label" for="estate_status">Status</label>
          <select class="select" id="estate_status" name="status" required>
            <?php foreach ($statusMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="platform-form__actions">
          <button type="submit" class="btn btn--primary btn--sm"><?= admin_icon('check', 'ico ico--sm') ?> Update status</button>
        </div>
      </div>
    </form>
  </section>
<?php endif; ?>
