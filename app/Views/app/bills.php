<?php
declare(strict_types=1);
$invoices = isset($invoices) && is_array($invoices) ? array_values(array_filter($invoices, 'is_array')) : [];
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2) . ' ' . ($currency !== '' ? $currency : 'KES');
$statusTone = static fn (string $status): string => match ($status) {
    'paid' => 'status--active', 'past_due' => 'status--danger', 'open' => 'status--pending', default => 'status--inactive',
};
$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
$open = 0;
$outstanding = 0;
foreach ($invoices as $invoice) {
    $status = (string) ($invoice['status'] ?? '');
    if (in_array($status, ['open', 'past_due'], true)) {
        $open++;
        $outstanding += (int) ($invoice['amount_cents'] ?? 0);
    }
}
$currency = (string) ($invoices[0]['currency'] ?? 'KES');
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">My bills</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>
  <p class="text-muted">Utility bills for your household. Payments are confirmed by your estate office.</p>
  <div class="stats stats--compact">
    <div class="stat"><p class="stat__label">Open bills</p><p class="stat__value"><?= e((string) $open) ?></p></div>
    <div class="stat"><p class="stat__label">Outstanding</p><p class="stat__value"><?= e($money($outstanding, $currency)) ?></p></div>
  </div>
  <?php if ($invoices === []): ?>
    <div class="resident-empty"><p class="resident-empty__title">No bills yet</p><p class="resident-empty__text">When your estate issues a utility bill it will appear here.</p></div>
  <?php else: ?>
    <ul class="bill-list">
      <?php foreach ($invoices as $invoice): ?>
        <?php $status = (string) ($invoice['status'] ?? ''); ?>
        <li class="card bill-row">
          <div class="bill-row__body">
            <p class="bill-row__title"><?= e((string) ($invoice['invoice_number'] ?? 'Utility bill')) ?></p>
            <p class="bill-row__meta"><?= e((string) ($invoice['unit_number'] ?? 'Household')) ?> · Period <?= e((string) ($invoice['period_key'] ?? '-')) ?> · Due <?= e(format_datetime((string) ($invoice['due_at'] ?? ''), (string) config('app.timezone', 'Africa/Nairobi'), 'd M Y')) ?></p>
          </div>
          <div class="bill-row__side">
            <p class="bill-row__amount"><?= e($money((int) ($invoice['amount_cents'] ?? 0), (string) ($invoice['currency'] ?? 'KES'))) ?></p>
            <span class="status <?= e($statusTone($status)) ?>"><?= e(ucfirst(str_replace('_', ' ', $status !== '' ? $status : 'unknown'))) ?></span>
            <a class="btn btn--ghost btn--sm" href="/app/bills/<?= e(rawurlencode((string) ($invoice['uuid'] ?? ''))) ?>">View bill</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
