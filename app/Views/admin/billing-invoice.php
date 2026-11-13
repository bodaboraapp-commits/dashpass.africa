<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';
$invoice = isset($invoice) && is_array($invoice) ? $invoice : [];
$payments = isset($payments) && is_array($payments) ? array_values(array_filter($payments, 'is_array')) : [];
$money = static fn (mixed $cents, string $currency = 'KES'): string => number_format(((int) $cents) / 100, 2) . ' ' . $currency;
$status = (string) ($invoice['status'] ?? '');
?>
<div class="admin-page">
  <div class="admin-page__head">
    <div><nav class="admin-breadcrumb" aria-label="Breadcrumb"><a href="/admin/billing">Billing</a><span aria-hidden="true">/</span><span aria-current="page"><?= e((string) ($invoice['invoice_number'] ?? 'Invoice')) ?></span></nav><h1 class="admin-page__title"><?= e((string) ($invoice['invoice_number'] ?? 'Invoice')) ?></h1><p class="admin-page__lede">Read-only invoice detail.</p></div>
    <a class="btn btn--ghost btn--sm" href="/admin/billing">Back to billing</a>
  </div>
  <?php if ($invoice === []): ?>
    <div class="admin-empty"><p class="admin-empty__title">Invoice unavailable</p><p class="admin-empty__text">Return to billing and try again.</p></div>
  <?php else: ?>
    <section class="card admin-detail">
      <dl class="admin-detail-grid">
        <div class="admin-detail-grid__item"><dt>Status</dt><dd><span class="status <?= $status === 'paid' ? 'status--active' : ($status === 'past_due' ? 'status--danger' : 'status--pending') ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span></dd></div>
        <div class="admin-detail-grid__item"><dt>Amount</dt><dd><?= e($money($invoice['amount_cents'] ?? 0, (string) ($invoice['currency'] ?? 'KES'))) ?></dd></div>
        <div class="admin-detail-grid__item"><dt>Period</dt><dd><?= e((string) ($invoice['period_key'] ?? '-')) ?></dd></div>
        <div class="admin-detail-grid__item"><dt>Due</dt><dd><?= e((string) ($invoice['due_at'] ?? '-')) ?></dd></div>
        <div class="admin-detail-grid__item"><dt>Unit</dt><dd><?= e((string) ($invoice['unit_number'] ?? '-')) ?></dd></div>
      </dl>
    </section>
    <section class="admin-section" aria-labelledby="invoice-payments-title"><div class="admin-section__head"><h2 class="admin-section__title" id="invoice-payments-title">Payments</h2></div>
      <?php if ($payments === []): ?><div class="admin-empty"><p class="admin-empty__title">No payments yet</p></div>
      <?php else: ?><div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Method</th><th scope="col">Reference</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Paid</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?><tr><td data-label="Method"><?= e(strtoupper((string) ($payment['method'] ?? '-'))) ?></td><td data-label="Reference"><?= e((string) ($payment['reference'] ?? '-')) ?></td><td data-label="Amount"><?= e($money($payment['amount_cents'] ?? 0, (string) ($payment['currency'] ?? 'KES'))) ?></td><td data-label="Status"><span class="status <?= (string) ($payment['status'] ?? '') === 'confirmed' ? 'status--active' : 'status--pending' ?>"><?= e(ucfirst((string) ($payment['status'] ?? 'unknown'))) ?></span></td><td data-label="Paid"><?= e((string) ($payment['paid_at'] ?? '-')) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>
  <?php endif; ?>
</div>
