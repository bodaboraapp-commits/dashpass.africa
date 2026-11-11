<?php

declare(strict_types=1);

/**
 * Platform billing dashboard (billing-invoices contract §4).
 *
 * Finance overview: outstanding/open/past-due/paid-this-month money stats, the
 * estate invoice table with status/estate/month filters, and a New-invoice CTA.
 * All figures come from the backend stats (integer cents) — no invented data.
 *
 * Variables (T77 frozen keys, defensive): $stats {open_cents, past_due_cents,
 * paid_this_month_cents, outstanding_cents, open_count, past_due_count},
 * $invoices [], $filters {status,estate,month}, $status_options [],
 * $estate_options [] + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$stats = isset($stats) && is_array($stats) ? $stats : [];
$invoices = isset($invoices) && is_array($invoices) ? $invoices : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$estateOptions = isset($estate_options) && is_array($estate_options) ? $estate_options : [];
$monthOptions = isset($month_options) && is_array($month_options) ? $month_options : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
// T102: platform shell carries platform.* only, so billing capability is derived
// from platform roles; the route still enforces the exact billing.* permission.
$platformRoles = isset($platform_roles) && is_array($platform_roles) ? array_map('strval', $platform_roles) : [];
$canBillingRole = in_array('platform_super_admin', $platformRoles, true)
    || in_array('platform_finance', $platformRoles, true);
$canView = in_array('billing.invoice.view', array_map('strval', $permissions), true) || $canBillingRole;
$canManage = in_array('billing.payment.manage', array_map('strval', $permissions), true) || $canBillingRole;

$money = static function (string $key) use ($stats): string {
    $value = $stats[$key] ?? null;

    return platform_money($value, 'KES');
};
$count = static function (string $key) use ($stats): ?int {
    $value = $stats[$key] ?? null;

    return is_numeric($value) ? (int) $value : null;
};

$statusMap = platform_option_map($statusOptions, [
    'draft' => 'Draft',
    'open' => 'Open',
    'past_due' => 'Past due',
    'paid' => 'Paid',
    'void' => 'Void',
]);
$statusConfig = [
    'draft' => 'status--inactive',
    'open' => 'status--pending',
    'past_due' => 'status--danger',
    'paid' => 'status--active',
    'void' => 'status--inactive',
];
$estateFilterMap = platform_option_map($estateOptions, []);
$currentStatus = trim((string) ($filters['status'] ?? ''));
$currentEstate = trim((string) ($filters['estate'] ?? ($filters['estate_uuid'] ?? '')));
$currentMonth = trim((string) ($filters['month'] ?? ''));
$hasFilters = $currentStatus !== '' || $currentEstate !== '' || $currentMonth !== '';
$rows = array_values(array_filter($invoices, 'is_array'));
$hasStats = false;
foreach (['outstanding_cents', 'open_cents', 'past_due_cents', 'paid_this_month_cents', 'open_count', 'past_due_count'] as $statKey) {
    if (array_key_exists($statKey, $stats)) {
        $hasStats = true;
        break;
    }
}
$balanceOf = static function (array $invoice): string {
    $currency = (string) ($invoice['currency'] ?? 'KES');
    if (isset($invoice['balance_cents']) && is_numeric($invoice['balance_cents'])) {
        return platform_money($invoice['balance_cents'], $currency);
    }
    $amount = $invoice['amount_cents'] ?? null;
    if (!is_numeric($amount)) {
        return '';
    }
    // T77 invoice shape: confirmed_cents drives balance; older fallbacks retained.
    $paid = $invoice['confirmed_cents'] ?? ($invoice['paid_cents'] ?? ($invoice['confirmed_payments_cents'] ?? 0));

    return platform_money(max(0, (int) $amount - (is_numeric($paid) ? (int) $paid : 0)), $currency);
};
$monthMap = [];
foreach ($monthOptions as $monthOption) {
    if (is_string($monthOption) && trim($monthOption) !== '') {
        $monthMap[trim($monthOption)] = admin_datetime(trim($monthOption) . '-01', $timezone, 'M Y');
        continue;
    }
    if (is_array($monthOption)) {
        $monthValue = platform_text($monthOption, ['value', 'month']);
        if ($monthValue !== '') {
            $monthMap[$monthValue] = platform_text($monthOption, ['label'], admin_datetime($monthValue . '-01', $timezone, 'M Y'));
        }
    }
}
?>
<div class="platform-page__head">
  <div>
    <h1 class="platform-page__title">Billing</h1>
    <p class="platform-page__lede">
      Estate invoices, payment records and outstanding balances across the platform.
    </p>
  </div>
  <?php if ($canManage): ?>
    <div class="platform-page__actions">
      <a class="btn btn--primary" href="/platform/billing/invoices/new"><?= admin_icon('plus', 'ico ico--sm') ?> New invoice</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$canView): ?>
  <div class="alert alert--error" role="alert">
    <?= admin_icon('lock') ?>
    <div class="alert__body">
      <p class="alert__title">Billing access required</p>
      <p>Your account does not have permission to view platform billing.</p>
    </div>
  </div>
<?php endif; ?>

<?php if ($hasStats): ?>
  <div class="platform-stats platform-stats--money">
    <div class="platform-stat platform-stat--money">
      <span class="platform-stat__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
      <span class="platform-stat__value"><?= e($money('outstanding_cents')) ?></span>
      <span class="platform-stat__label">Outstanding</span>
    </div>
    <div class="platform-stat platform-stat--money">
      <span class="platform-stat__icon" aria-hidden="true"><?= admin_icon('activity') ?></span>
      <span class="platform-stat__value"><?= e($money('open_cents')) ?></span>
      <span class="platform-stat__label">
        Open<?php if ($count('open_count') !== null): ?> · <?= e((string) $count('open_count')) ?> invoice<?= $count('open_count') === 1 ? '' : 's' ?><?php endif; ?>
      </span>
    </div>
    <div class="platform-stat platform-stat--money">
      <span class="platform-stat__icon" aria-hidden="true"><?= admin_icon('alert') ?></span>
      <span class="platform-stat__value"><?= e($money('past_due_cents')) ?></span>
      <span class="platform-stat__label">
        Past due<?php if ($count('past_due_count') !== null): ?> · <?= e((string) $count('past_due_count')) ?> invoice<?= $count('past_due_count') === 1 ? '' : 's' ?><?php endif; ?>
      </span>
    </div>
    <div class="platform-stat platform-stat--money">
      <span class="platform-stat__icon" aria-hidden="true"><?= admin_icon('check') ?></span>
      <span class="platform-stat__value"><?= e($money('paid_this_month_cents')) ?></span>
      <span class="platform-stat__label">Paid this month</span>
    </div>
  </div>
<?php else: ?>
  <div class="platform-empty">
    <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
    <p class="platform-empty__title">No billing statistics available yet</p>
    <p class="platform-empty__text">Money totals appear once invoices exist for the platform.</p>
  </div>
<?php endif; ?>

<section class="platform-section" aria-labelledby="billing-invoices-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="billing-invoices-title">Invoices</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/platform/billing" class="platform-form platform-filters">
    <div class="platform-form__grid platform-form__grid--inline">
      <div class="platform-field">
        <label class="platform-field__label" for="invoice_filter_status">Status</label>
        <select class="select" id="invoice_filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="platform-field">
        <label class="platform-field__label" for="invoice_filter_estate">Estate</label>
        <select class="select" id="invoice_filter_estate" name="estate">
          <option value="">All estates</option>
          <?php foreach ($estateFilterMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentEstate === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="platform-field">
        <label class="platform-field__label" for="invoice_filter_month">Month</label>
        <?php if ($monthMap !== []): ?>
          <select class="select" id="invoice_filter_month" name="month">
            <option value="">All months</option>
            <?php foreach ($monthMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $currentMonth === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input class="input" id="invoice_filter_month" name="month" type="month" value="<?= e($currentMonth) ?>">
        <?php endif; ?>
      </div>
      <div class="platform-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/platform/billing">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="platform-table-wrap">
      <table class="platform-table">
        <thead>
          <tr>
            <th scope="col">Invoice</th>
            <th scope="col">Estate</th>
            <th scope="col">Plan / period</th>
            <th scope="col">Amount</th>
            <th scope="col">Balance</th>
            <th scope="col">Status</th>
            <th scope="col">Due</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $invoice): ?>
            <?php
            $uuid = platform_text($invoice, ['uuid']);
            $number = platform_text($invoice, ['invoice_number', 'number'], 'Invoice');
            $estateName = platform_text($invoice, ['estate_name', 'estate'], 'Estate');
            $planName = platform_text($invoice, ['plan_name', 'subscription_plan_name', 'plan']);
            $periodStart = platform_text($invoice, ['period_start']);
            $periodEnd = platform_text($invoice, ['period_end']);
            $amount = platform_money($invoice['amount_cents'] ?? null, (string) ($invoice['currency'] ?? 'KES'));
            $balance = $balanceOf($invoice);
            $status = platform_text($invoice, ['status'], 'open');
            $statusLabel = platform_text($invoice, ['status_label']) ?: ($statusMap[$status] ?? platform_humanize($status));
            $statusTone = platform_text($invoice, ['status_tone']);
            $dueAt = platform_text($invoice, ['due_at', 'due_date']);
            $isOverdue = $status === 'past_due';
            ?>
            <tr>
              <td data-label="Invoice">
                <?php if ($uuid !== ''): ?>
                  <a class="platform-table__link mono" href="/platform/billing/invoices/<?= e(rawurlencode($uuid)) ?>"><?= e($number) ?></a>
                <?php else: ?>
                  <span class="platform-table__strong mono"><?= e($number) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Estate"><?= e($estateName) ?></td>
              <td data-label="Plan / period">
                <?php if ($planName !== ''): ?>
                  <span class="platform-table__sub"><?= e($planName) ?></span>
                <?php endif; ?>
                <?php if ($periodStart !== '' || $periodEnd !== ''): ?>
                  <span class="platform-table__sub">
                    <?= e($periodStart !== '' ? admin_datetime($periodStart, $timezone, 'd M Y') : '—') ?>
                    →
                    <?= e($periodEnd !== '' ? admin_datetime($periodEnd, $timezone, 'd M Y') : '—') ?>
                  </span>
                <?php endif; ?>
                <?php if ($planName === '' && $periodStart === '' && $periodEnd === ''): ?>—<?php endif; ?>
              </td>
              <td data-label="Amount"><?= e($amount !== '' ? $amount : '—') ?></td>
              <td data-label="Balance">
                <span class="platform-balance <?= $isOverdue ? 'platform-balance--due' : '' ?>"><?= e($balance !== '' ? $balance : '—') ?></span>
              </td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Due">
                <?= e($dueAt !== '' ? admin_datetime($dueAt, $timezone, 'd M Y') : '—') ?>
                <?php if ($isOverdue): ?>
                  <span class="platform-table__sub platform-table__sub--danger">Past due</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
      <p class="platform-empty__title"><?= $hasFilters ? 'No invoices match these filters' : 'No invoices yet' ?></p>
      <p class="platform-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see every platform invoice.
        <?php else: ?>
          Create the first estate invoice to start tracking payments and balances.
        <?php endif; ?>
      </p>
      <?php if ($canManage && !$hasFilters): ?>
        <a class="btn btn--primary" href="/platform/billing/invoices/new"><?= admin_icon('plus', 'ico ico--sm') ?> New invoice</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
