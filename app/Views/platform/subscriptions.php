<?php

declare(strict_types=1);

/**
 * Platform subscriptions (platform-admin contract §3).
 *
 * Estate subscriptions with plan/status filters and per-row plan change + status
 * transitions. Cancelled/expired subscriptions are terminal; closed rows show
 * their state instead of a form (no dead actions).
 *
 * Variables (T64 frozen keys, defensive): $subscriptions [], $plans [],
 * $status_options [], $filters {status,plan}, $errors [], $old [] + platform
 * shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$subscriptions = isset($subscriptions) && is_array($subscriptions) ? $subscriptions : [];
$plans = isset($plans) && is_array($plans) ? $plans : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = in_array('platform.subscription.manage', array_map('strval', $permissions), true);

$statusMap = platform_option_map($statusOptions, [
    'trialing' => 'Trialing',
    'active' => 'Active',
    'past_due' => 'Past due',
    'suspended' => 'Suspended',
    'cancelled' => 'Cancelled',
    'expired' => 'Expired',
]);
$planOptions = [];
foreach ($plans as $plan) {
    if (!is_array($plan)) {
        continue;
    }
    $planUuid = platform_text($plan, ['uuid']);
    if ($planUuid === '') {
        continue;
    }
    $planStatus = platform_text($plan, ['status']);
    if ($planStatus !== '' && $planStatus !== 'active') {
        continue; // only active plans can be newly assigned
    }
    $planName = platform_text($plan, ['name', 'plan_name', 'code'], 'Plan');
    $planOptions[$planUuid] = $planName;
}
$currentStatus = trim((string) ($filters['status'] ?? ''));
$currentPlan = trim((string) ($filters['plan'] ?? ($filters['plan_uuid'] ?? '')));
$hasFilters = $currentStatus !== '' || $currentPlan !== '';
$rows = array_values(array_filter($subscriptions, 'is_array'));
$terminalStatuses = ['cancelled', 'expired'];

$statusClass = static function (string $status, string $tone = ''): string {
    $mapped = match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        'neutral' => 'status--inactive',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return match ($status) {
        'active', 'trialing' => 'status--active',
        'past_due', 'suspended' => 'status--pending',
        'cancelled', 'expired' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="platform-page__head">
  <div>
    <h1 class="platform-page__title">Subscriptions</h1>
    <p class="platform-page__lede">
      Estate plans and subscription status. Changing a plan or status is audited; cancelled and expired subscriptions
      are terminal.
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="platform-section" aria-labelledby="subscriptions-list-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="subscriptions-list-title">Estate subscriptions</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/platform/subscriptions" class="platform-form platform-filters">
    <div class="platform-form__grid platform-form__grid--inline">
      <div class="platform-field">
        <label class="platform-field__label" for="subscription_filter_status">Status</label>
        <select class="select" id="subscription_filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="platform-field">
        <label class="platform-field__label" for="subscription_filter_plan">Plan</label>
        <select class="select" id="subscription_filter_plan" name="plan">
          <option value="">All plans</option>
          <?php foreach ($planOptions as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentPlan === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="platform-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/platform/subscriptions">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="platform-table-wrap">
      <table class="platform-table">
        <thead>
          <tr>
            <th scope="col">Estate</th>
            <th scope="col">Plan</th>
            <th scope="col">Status</th>
            <th scope="col">Started</th>
            <th scope="col">Ends</th>
            <?php if ($canManage): ?><th scope="col">Change plan / status</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $subscription): ?>
            <?php
            $uuid = platform_text($subscription, ['uuid']);
            $estateName = platform_text($subscription, ['estate_name', 'estate'], 'Estate');
            $planName = platform_text($subscription, ['plan_name', 'plan'], '—');
            $status = platform_text($subscription, ['status'], 'trialing');
            $statusLabel = platform_text($subscription, ['status_label']) ?: ($statusMap[$status] ?? platform_humanize($status));
            $statusTone = platform_text($subscription, ['status_tone']);
            $startsAt = platform_text($subscription, ['starts_at', 'started_at']);
            $endsAt = platform_text($subscription, ['ends_at', 'current_period_end']);
            $isTerminal = in_array($status, $terminalStatuses, true);
            ?>
            <tr>
              <td data-label="Estate"><?= e($estateName) ?></td>
              <td data-label="Plan"><?= e($planName) ?></td>
              <td data-label="Status"><span class="status <?= e($statusClass($status, $statusTone)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Started"><?= e($startsAt !== '' ? admin_datetime($startsAt, $timezone, 'd M Y') : '—') ?></td>
              <td data-label="Ends"><?= e($endsAt !== '' ? admin_datetime($endsAt, $timezone, 'd M Y') : '—') ?></td>
              <?php if ($canManage): ?>
                <td data-label="Change plan / status">
                  <?php if ($uuid === '' || $isTerminal): ?>
                    <span class="platform-field__hint"><?= $isTerminal ? 'Closed — terminal state' : '—' ?></span>
                  <?php else: ?>
                    <details class="platform-inline-edit">
                      <summary class="btn btn--ghost btn--sm">Update</summary>
                      <form method="post" action="/platform/subscriptions/<?= e(rawurlencode($uuid)) ?>" class="platform-inline-edit__form">
                        <?= csrf_field() ?>
                        <div class="platform-field">
                          <label class="platform-field__label" for="subscription_plan_<?= e($uuid) ?>">Plan</label>
                          <select class="select" id="subscription_plan_<?= e($uuid) ?>" name="plan_uuid">
                            <option value="">Keep current plan</option>
                            <?php foreach ($planOptions as $value => $label): ?>
                              <option value="<?= e((string) $value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="platform-field">
                          <label class="platform-field__label" for="subscription_status_<?= e($uuid) ?>">Status</label>
                          <select class="select" id="subscription_status_<?= e($uuid) ?>" name="status">
                            <?php foreach ($statusMap as $value => $label): ?>
                              <option value="<?= e((string) $value) ?>" <?= $status === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <button type="submit" class="btn btn--primary btn--sm">Save subscription</button>
                      </form>
                    </details>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('activity') ?></span>
      <p class="platform-empty__title"><?= $hasFilters ? 'No subscriptions match these filters' : 'No subscriptions yet' ?></p>
      <p class="platform-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see every estate subscription.
        <?php else: ?>
          Subscriptions appear when an estate is provisioned with a plan.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>
</section>
