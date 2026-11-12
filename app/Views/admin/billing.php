<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';
$invoices = isset($invoices) && is_array($invoices) ? array_values(array_filter($invoices, 'is_array')) : [];
$payments = isset($payments) && is_array($payments) ? array_values(array_filter($payments, 'is_array')) : [];
$totals = isset($totals) && is_array($totals) ? $totals : [];
$money = static fn (mixed $cents, string $currency = 'KES'): string => number_format(((int) $cents) / 100, 2) . ' ' . $currency;
$statusTone = static fn (string $status): string => match ($status) {
    'paid' => 'status--active', 'past_due' => 'status--danger', 'open' => 'status--pending', default => 'status--inactive',
};
?>
<div class="admin-page">
  <div class="admin-page__head">
    <div><h1 class="admin-page__title">Billing</h1><p class="admin-page__lede">Read-only estate billing overview: invoices, payments and reports. Use the platform finance tools to change billing records.</p></div>
    <div class="cluster"><a class="btn btn--ghost btn--sm" href="/admin/billing/reports">Reports</a><a class="btn btn--primary btn--sm" href="/admin/billing/export.csv?report=summary">Export CSV</a></div>
  </div>

  <div class="admin-stats">
    <?php foreach ([['Outstanding', 'outstanding_cents'], ['Open', 'open_cents'], ['Past due', 'past_due_cents'], ['Collected', 'paid_cents']] as [$label, $key]): ?>
      <div class="admin-stat"><span class="admin-stat__value"><?= e($money($totals[$key] ?? 0)) ?></span><span class="admin-stat__label"><?= e($label) ?></span></div>
    <?php endforeach; ?>
  </div>

  <section class="admin-section" aria-labelledby="billing-invoices-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="billing-invoices-title">Invoices</h2><span class="chip"><?= e((string) count($invoices)) ?></span></div>
    <?php if ($invoices === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No invoices yet</p><p class="admin-empty__text">Estate subscription and utility invoices will appear here.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Invoice</th><th scope="col">Unit</th><th scope="col">Period</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach ($invoices as $invoice): ?>
          <tr>
            <td data-label="Invoice"><a class="admin-table__link" href="/admin/billing/invoices/<?= e(rawurlencode((string) ($invoice['uuid'] ?? ''))) ?>"><?= e((string) ($invoice['invoice_number'] ?? 'Invoice')) ?></a><span class="admin-table__sub"><?= e(ucfirst((string) ($invoice['kind'] ?? 'subscription'))) ?></span></td>
            <td data-label="Unit"><?= e((string) ($invoice['unit_number'] ?? '—')) ?></td>
            <td data-label="Period"><?= e((string) ($invoice['period_key'] ?? '—')) ?></td>
            <td data-label="Amount"><?= e($money($invoice['amount_cents'] ?? 0, (string) ($invoice['currency'] ?? 'KES'))) ?></td>
            <td data-label="Paid"><?= e($money($invoice['paid_cents'] ?? 0, (string) ($invoice['currency'] ?? 'KES'))) ?></td>
            <td data-label="Due"><?= e((string) ($invoice['due_at'] ?? '—')) ?></td>
            <td data-label="Status"><span class="status <?= e($statusTone((string) ($invoice['status'] ?? ''))) ?>"><?= e(ucfirst(str_replace('_', ' ', (string) ($invoice['status'] ?? 'unknown')))) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="admin-section" aria-labelledby="billing-payments-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="billing-payments-title">Recent payments</h2></div>
    <?php if ($payments === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No payments recorded</p><p class="admin-empty__text">Confirmed M-Pesa/card and manual payments appear here.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Invoice</th><th scope="col">Method</th><th scope="col">Reference</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Paid</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?>
          <tr><td data-label="Invoice"><?= e((string) ($payment['invoice_number'] ?? '—')) ?></td><td data-label="Method"><?= e(strtoupper((string) ($payment['method'] ?? '—'))) ?></td><td data-label="Reference"><?= e((string) ($payment['reference'] ?? '—')) ?></td><td data-label="Amount"><?= e($money($payment['amount_cents'] ?? 0, (string) ($payment['currency'] ?? 'KES'))) ?></td><td data-label="Status"><span class="status <?= (string) ($payment['status'] ?? '') === 'confirmed' ? 'status--active' : 'status--pending' ?>"><?= e(ucfirst((string) ($payment['status'] ?? 'unknown'))) ?></span></td><td data-label="Paid"><?= e((string) ($payment['paid_at'] ?? '—')) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
