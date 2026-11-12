<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$schedules = isset($schedules) && is_array($schedules) ? array_values(array_filter($schedules, 'is_array')) : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$status = trim((string) ($filters['status'] ?? ''));
$scope = trim((string) ($filters['scope'] ?? ''));
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2) . ' ' . ($currency !== '' ? $currency : 'KES');
$queryLink = static function (array $add) use ($query): string {
    parse_str($query, $q);
    foreach ($add as $k => $v) {
        if ($v === '') { unset($q[$k]); } else { $q[$k] = $v; }
    }
    $qs = http_build_query($q);
    return '/platform/billing/schedules' . ($qs !== '' ? '?' . $qs : '');
};
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform billing</p><h1 class="platform-page__title">Billing schedules</h1><p class="platform-page__lede">Subscription and utility schedules, next runs, pause/resume and dry-run previews.</p></div>
    <a class="btn btn--primary btn--sm" href="/platform/billing/schedules/new">New schedule</a>
  </header>

  <?php if ($errors !== []): ?><div class="alert alert--danger" role="alert"><div class="alert__body"><p class="alert__title">Check the schedule</p><p><?= e((string) (platform_error_list($errors)[0] ?? 'Some values could not be saved.')) ?></p></div></div><?php endif; ?>

  <nav class="admin-subnav" aria-label="Schedule filters">
    <a class="admin-subnav__link<?= $status === '' ? ' is-active' : '' ?>" href="<?= e($queryLink(['status' => ''])) ?>">All</a>
    <?php foreach (['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'] as $value => $label): ?>
      <a class="admin-subnav__link<?= $status === $value ? ' is-active' : '' ?>" href="<?= e($queryLink(['status' => $value])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="muted text-xs">Scope:</span>
    <a class="admin-subnav__link<?= $scope === '' ? ' is-active' : '' ?>" href="<?= e($queryLink(['scope' => ''])) ?>">All</a>
    <a class="admin-subnav__link<?= $scope === 'estate_subscription' ? ' is-active' : '' ?>" href="<?= e($queryLink(['scope' => 'estate_subscription'])) ?>">Subscription</a>
    <a class="admin-subnav__link<?= $scope === 'utility_bill' ? ' is-active' : '' ?>" href="<?= e($queryLink(['scope' => 'utility_bill'])) ?>">Utility</a>
  </nav>

  <?php if ($schedules === []): ?>
    <div class="admin-empty"><span class="admin-empty__icon" aria-hidden="true"><?= \App\Core\View::capture('partials/icon', ['name' => 'calendar', 'class' => 'ico']) ?></span><p class="admin-empty__title">No matching schedules</p><p class="admin-empty__text">Create a subscription or utility schedule to automate billing.</p><p><a class="btn btn--outline" href="/platform/billing/schedules/new">New schedule</a></p></div>
  <?php else: ?>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Schedule</th><th scope="col">Estate</th><th scope="col">Plan</th><th scope="col">Cadence</th><th scope="col">Scope</th><th scope="col">Status</th><th scope="col">Next run</th><th scope="col">Last invoice</th><th scope="col">Amount</th></tr></thead><tbody>
      <?php foreach ($schedules as $schedule): ?>
        <?php
        $uuid = trim((string) ($schedule['uuid'] ?? ''));
        $label = trim((string) ($schedule['name'] ?? '')) ?: (string) ($schedule['scope'] ?? 'Schedule');
        $statusValue = (string) ($schedule['status'] ?? '');
        $tone = $statusValue === 'active' ? 'status--active' : ($statusValue === 'paused' ? 'status--pending' : 'status--inactive');
        $amount = (int) ($schedule['flat_amount_cents'] ?? 0) > 0 ? (int) $schedule['flat_amount_cents'] : (int) ($schedule['tariff_cents_per_unit'] ?? 0);
        ?>
        <tr>
          <td data-label="Schedule"><?php if ($uuid !== ''): ?><a class="admin-table__link" href="/platform/billing/schedules/<?= e(rawurlencode($uuid)) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></td>
          <td data-label="Estate"><?= e((string) ($schedule['estate_name'] ?? '—')) ?></td>
          <td data-label="Plan"><?= e((string) ($schedule['plan_name'] ?? '—')) ?></td>
          <td data-label="Cadence"><?= e(ucfirst((string) ($schedule['period_unit'] ?? '—'))) ?></td>
          <td data-label="Scope"><?= e(ucwords(str_replace('_', ' ', (string) ($schedule['scope'] ?? '—')))) ?></td>
          <td data-label="Status"><span class="status <?= e($tone) ?>"><?= e(ucfirst($statusValue)) ?></span></td>
          <td data-label="Next run"><?= e((string) ($schedule['next_run_at'] ?? '—')) ?></td>
          <td data-label="Last invoice"><?= e((string) ($schedule['last_invoice_number'] ?? '—')) ?></td>
          <td data-label="Amount"><?= e($money($amount, (string) ($schedule['currency'] ?? 'KES'))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
