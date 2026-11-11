<?php

declare(strict_types=1);

/**
 * Platform plans (platform-admin contract §3).
 *
 * List + create + retire subscription plans. Retiring sets status retired; plans
 * are never deleted and existing subscriptions keep their plan.
 *
 * Variables (T64 frozen keys, defensive): $plans [], $errors [], $old [] +
 * platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$plans = isset($plans) && is_array($plans) ? $plans : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = in_array('platform.plan.manage', array_map('strval', $permissions), true);

$oldValue = static function (string $field, string $default = '') use ($old): string {
    $candidate = $old[$field] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};
$rows = array_values(array_filter($plans, 'is_array'));
?>
<div class="platform-page__head">
  <div>
    <h1 class="platform-page__title">Plans</h1>
    <p class="platform-page__lede">
      Subscription plans available to estates. Retired plans stay on existing subscriptions but cannot be assigned to
      new estates.
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<?php if ($canManage): ?>
  <section class="platform-section" aria-labelledby="plan-add-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="plan-add-title">Create a plan</h2>
    </div>

    <form method="post" action="/platform/plans" class="platform-form card">
      <?= csrf_field() ?>
      <div class="platform-form__grid">
        <div class="platform-field<?= platform_field_error($errors, 'code') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_code">Plan code</label>
          <input class="input" id="plan_code" name="code" type="text" maxlength="40" required
                 value="<?= e($oldValue('code')) ?>" placeholder="e.g. GROWTH_2026">
          <p class="platform-field__hint">Uppercase letters, numbers and underscores (3–40).</p>
          <?php if (platform_field_error($errors, 'code') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'code')) ?></p>
          <?php endif; ?>
        </div>

        <div class="platform-field<?= platform_field_error($errors, 'name') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_name">Name</label>
          <input class="input" id="plan_name" name="name" type="text" maxlength="120" required
                 value="<?= e($oldValue('name')) ?>" placeholder="e.g. Growth">
          <?php if (platform_field_error($errors, 'name') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'name')) ?></p>
          <?php endif; ?>
        </div>

        <div class="platform-field<?= platform_field_error($errors, 'monthly_price_cents') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="monthly_price_cents">Monthly price (cents)</label>
          <input class="input" id="monthly_price_cents" name="monthly_price_cents" type="number" min="0" required
                 value="<?= e($oldValue('monthly_price_cents', '0')) ?>">
          <p class="platform-field__hint">e.g. 500000 = KES 5,000.00</p>
          <?php if (platform_field_error($errors, 'monthly_price_cents') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'monthly_price_cents')) ?></p>
          <?php endif; ?>
        </div>

        <div class="platform-field<?= platform_field_error($errors, 'currency') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_currency">Currency</label>
          <input class="input" id="plan_currency" name="currency" type="text" maxlength="3" required
                 value="<?= e($oldValue('currency', 'KES')) ?>" placeholder="KES">
          <?php if (platform_field_error($errors, 'currency') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'currency')) ?></p>
          <?php endif; ?>
        </div>

        <div class="platform-field<?= platform_field_error($errors, 'household_limit') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_household_limit">
            Household limit
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input" id="plan_household_limit" name="household_limit" type="number" min="0"
                 value="<?= e($oldValue('household_limit')) ?>" placeholder="Unlimited">
          <?php if (platform_field_error($errors, 'household_limit') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'household_limit')) ?></p>
          <?php endif; ?>
        </div>

        <div class="platform-field<?= platform_field_error($errors, 'gate_device_limit') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="plan_gate_device_limit">
            Gate device limit
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input" id="plan_gate_device_limit" name="gate_device_limit" type="number" min="0"
                 value="<?= e($oldValue('gate_device_limit')) ?>" placeholder="Unlimited">
          <?php if (platform_field_error($errors, 'gate_device_limit') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'gate_device_limit')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'features') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="plan_features">
          Features
          <span class="muted text-xs">Optional, one key:value per line</span>
        </label>
        <textarea class="input" id="plan_features" name="features" rows="3" placeholder="reports:true&#10;support_priority:true"><?= e($oldValue('features')) ?></textarea>
        <?php if (platform_field_error($errors, 'features') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'features')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Create plan</button>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="platform-section" aria-labelledby="plans-list-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="plans-list-title">Plans</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($rows !== []): ?>
    <div class="platform-table-wrap">
      <table class="platform-table">
        <thead>
          <tr>
            <th scope="col">Plan</th>
            <th scope="col">Price</th>
            <th scope="col">Limits</th>
            <th scope="col">Features</th>
            <th scope="col">Status</th>
            <th scope="col">Subscriptions</th>
            <?php if ($canManage): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $plan): ?>
            <?php
            $uuid = platform_text($plan, ['uuid']);
            $code = platform_text($plan, ['code']);
            $planName = platform_text($plan, ['name', 'plan_name'], $code !== '' ? $code : 'Plan');
            $priceCents = $plan['monthly_price_cents'] ?? null;
            $currency = platform_text($plan, ['currency'], 'KES');
            $price = platform_money($priceCents, $currency);
            $householdLimit = platform_stat($plan, ['household_limit']);
            $gateLimit = platform_stat($plan, ['gate_device_limit']);
            $features = $plan['features'] ?? [];
            $status = platform_text($plan, ['status'], 'active');
            $statusLabel = platform_text($plan, ['status_label']) ?: platform_humanize($status);
            $statusTone = platform_text($plan, ['status_tone']);
            $subscriptionsCount = platform_stat($plan, ['subscriber_count', 'subscriptions_count', 'active_subscriptions']);
            ?>
            <tr>
              <td data-label="Plan">
                <span class="platform-table__strong"><?= e($planName) ?></span>
                <?php if ($code !== ''): ?>
                  <span class="platform-table__sub mono"><?= e($code) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Price"><?= e($price !== '' ? $price : '—') ?></td>
              <td data-label="Limits">
                <span class="platform-table__sub">Households: <?= e($householdLimit !== null && $householdLimit > 0 ? (string) $householdLimit : 'Unlimited') ?></span>
                <span class="platform-table__sub">Gate devices: <?= e($gateLimit !== null && $gateLimit > 0 ? (string) $gateLimit : 'Unlimited') ?></span>
              </td>
              <td data-label="Features">
                <?php if (is_array($features) && $features !== []): ?>
                  <span class="platform-table__sub"><?= e((string) count($features)) ?> configured</span>
                <?php elseif (is_string($features) && trim($features) !== ''): ?>
                  <span class="platform-table__sub"><?= e(mb_strimwidth(trim($features), 0, 60, '…')) ?></span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Subscriptions"><?= e($subscriptionsCount !== null ? (string) $subscriptionsCount : '—') ?></td>
              <?php if ($canManage): ?>
                <td data-label="Actions">
                  <?php if ($uuid !== '' && $status === 'active'): ?>
                    <form method="post" action="/platform/plans/<?= e(rawurlencode($uuid)) ?>/retire" class="inline-form"
                          data-confirm="Retire this plan? Existing subscriptions keep it, but new estates cannot select it.">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn--ghost btn--sm">Retire plan</button>
                    </form>
                  <?php else: ?>
                    <span class="platform-field__hint"><?= $status === 'retired' ? 'Retired' : '—' ?></span>
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
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
      <p class="platform-empty__title">No plans yet</p>
      <p class="platform-empty__text">Create a subscription plan before provisioning estates with a plan.</p>
    </div>
  <?php endif; ?>
</section>
