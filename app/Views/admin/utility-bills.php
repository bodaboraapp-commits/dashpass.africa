<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/_helpers.php';
$schedules = isset($schedules) && is_array($schedules) ? array_values(array_filter($schedules, 'is_array')) : [];
$invoices = isset($invoices) && is_array($invoices) ? array_values(array_filter($invoices, 'is_array')) : [];
$timezone = admin_timezone(isset($estate) && is_array($estate) ? $estate : []);
?>
<div class="admin-page">
  <div class="admin-page__head"><div><h1 class="admin-page__title">Utility bills</h1><p class="admin-page__lede">Own-estate utility schedules and generated bills. Generate/preview actions arrive with the estate utility route slice.</p></div></div>
  <section class="admin-section" aria-labelledby="utility-schedules-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="utility-schedules-title">Schedules</h2><span class="chip"><?= e((string) count($schedules)) ?></span></div>
    <?php if ($schedules === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No utility schedules yet</p><p class="admin-empty__text">Utility schedules will appear here once your estate team creates them.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Schedule</th><th scope="col">Utility</th><th scope="col">Status</th><th scope="col">Next run</th><th scope="col">Last period</th></tr></thead><tbody>
      <?php foreach ($schedules as $schedule): ?>
        <tr><td data-label="Schedule"><?= e((string) ($schedule['name'] ?? 'Utility schedule')) ?></td><td data-label="Utility"><?= e((string) ($schedule['utility_type'] ?? '-')) ?></td><td data-label="Status"><span class="status <?= (string) ($schedule['status'] ?? '') === 'active' ? 'status--active' : 'status--inactive' ?>"><?= e((string) ($schedule['status'] ?? '-')) ?></span></td><td data-label="Next run"><?= e((string) ($schedule['next_run_at'] ?? '-')) ?></td><td data-label="Last period"><?= e((string) ($schedule['last_period_key'] ?? '-')) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="admin-section" aria-labelledby="utility-bills-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="utility-bills-title">Generated bills</h2><span class="chip"><?= e((string) count($invoices)) ?></span></div>
    <?php if ($invoices === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No utility bills generated yet</p><p class="admin-empty__text">Generated utility invoices will appear here.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Bill</th><th scope="col">Unit</th><th scope="col">Period</th><th scope="col">Amount</th><th scope="col">Status</th></tr></thead><tbody>
      <?php foreach ($invoices as $invoice): ?>
        <tr><td data-label="Bill"><?= e((string) ($invoice['invoice_number'] ?? '-')) ?></td><td data-label="Unit"><?= e((string) ($invoice['unit_number'] ?? '-')) ?></td><td data-label="Period"><?= e((string) ($invoice['period_key'] ?? '-')) ?></td><td data-label="Amount"><?= e(number_format(((int) ($invoice['amount_cents'] ?? 0)) / 100, 2) . ' ' . (string) ($invoice['currency'] ?? 'KES')) ?></td><td data-label="Status"><span class="status <?= (string) ($invoice['status'] ?? '') === 'paid' ? 'status--active' : 'status--pending' ?>"><?= e(ucfirst((string) ($invoice['status'] ?? 'unknown'))) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
