<?php
declare(strict_types=1);
$invoice = isset($invoice) && is_array($invoice) ? $invoice : [];
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2) . ' ' . ($currency !== '' ? $currency : 'KES');
$status = (string) ($invoice['status'] ?? '');
$tone = match ($status) {
    'paid' => 'status--active', 'past_due' => 'status--danger', 'open' => 'status--pending', default => 'status--inactive',
};
$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

// Timezone used for every date on the document (estate timezone when known).
$timezone = trim((string) ($invoice['estate_timezone'] ?? ''));
if ($timezone === '') {
    $timezone = (string) config('app.timezone', 'Africa/Nairobi');
}

// House details for the invoice header ("billed to").
$householdUnit = trim((string) ($invoice['unit_number'] ?? ''));
$buildingName = trim((string) ($invoice['building_name'] ?? ''));
$estateName = trim((string) ($invoice['estate_name'] ?? ''));
$householdTypeLabel = match (trim((string) ($invoice['household_type'] ?? ''))) {
    'apartment' => 'Apartment',
    'maisonette' => 'Maisonette',
    'other' => 'Other',
    default => '',
};
$householdLabel = implode(' · ', array_values(array_filter(
    [$householdUnit, $buildingName],
    static fn (string $part): bool => $part !== ''
)));

// Billing period label, from the actual period dates first, then the period key.
$periodLabel = '';
$periodStart = trim((string) ($invoice['period_start'] ?? ''));
$periodEnd = trim((string) ($invoice['period_end'] ?? ''));
if ($periodStart !== '' && $periodEnd !== '') {
    $periodStartLabel = format_datetime($periodStart . ' 00:00:00', $timezone, 'j M Y');
    $periodEndLabel = format_datetime($periodEnd . ' 00:00:00', $timezone, 'j M Y');
    $periodLabel = $periodStartLabel !== '-' && $periodEndLabel !== '-'
        ? $periodStartLabel . ' to ' . $periodEndLabel
        : '';
}
if ($periodLabel === '') {
    $periodKey = trim((string) ($invoice['period_key'] ?? ''));
    if (preg_match('/^(\d{4})-?(\d{2})$/', $periodKey, $periodMatch) === 1) {
        $periodLabel = date('M Y', mktime(0, 0, 0, (int) $periodMatch[2], 1, (int) $periodMatch[1]));
    } elseif ($periodKey !== '') {
        $periodLabel = $periodKey;
    }
}

$kindLabel = match (trim((string) ($invoice['kind'] ?? ''))) {
    'utility' => 'Utility bill',
    'subscription' => 'Subscription invoice',
    default => 'Bill',
};
$lineDescription = $kindLabel;
$lineNote = implode(' · ', array_values(array_filter(
    [$periodLabel, $householdLabel],
    static fn (string $part): bool => $part !== ''
)));
$invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
$amountLabel = $money((int) ($invoice['amount_cents'] ?? 0), (string) ($invoice['currency'] ?? 'KES'));
$dueLabel = format_datetime((string) ($invoice['due_at'] ?? ''), $timezone, 'd M Y');
$issuedLabel = format_datetime((string) ($invoice['created_at'] ?? ''), $timezone, 'd M Y');
$billedToMuted = implode(' · ', array_values(array_filter(
    [$householdTypeLabel, $estateName],
    static fn (string $part): bool => $part !== ''
)));
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/bills" aria-label="Back to bills" title="Back to bills">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title"><?= e($invoiceNumber !== '' ? $invoiceNumber : 'Bill') ?></h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <section class="card bill-invoice" aria-labelledby="bill-invoice-title">
    <header class="bill-invoice__head">
      <div>
        <p class="bill-invoice__eyebrow">Invoice</p>
        <h2 class="bill-invoice__number" id="bill-invoice-title"><?= e($invoiceNumber !== '' ? $invoiceNumber : 'Utility bill') ?></h2>
      </div>
      <span class="status <?= e($tone) ?>"><?= e(ucfirst(str_replace('_', ' ', $status !== '' ? $status : 'unknown'))) ?></span>
    </header>

    <div class="bill-invoice__parties">
      <div>
        <p class="bill-invoice__label">Billed to</p>
        <p class="bill-invoice__strong"><?= e($householdLabel !== '' ? $householdLabel : 'Household') ?></p>
        <?php if ($billedToMuted !== ''): ?>
          <p class="bill-invoice__muted"><?= e($billedToMuted) ?></p>
        <?php endif; ?>
      </div>
      <dl class="bill-invoice__meta">
        <?php if ($periodLabel !== ''): ?>
          <div><dt>Billing period</dt><dd><?= e($periodLabel) ?></dd></div>
        <?php endif; ?>
        <div><dt>Issued</dt><dd><?= e($issuedLabel) ?></dd></div>
        <div><dt>Due</dt><dd><?= e($dueLabel) ?></dd></div>
      </dl>
    </div>

    <table class="bill-invoice__table">
      <caption class="visually-hidden">Invoice lines</caption>
      <thead>
        <tr>
          <th scope="col">Description</th>
          <th scope="col">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <th scope="row" class="bill-invoice__item">
            <span class="bill-invoice__item-title"><?= e($lineDescription) ?></span>
            <span class="bill-invoice__item-note"><?= e($lineNote !== '' ? $lineNote : 'For this household') ?></span>
          </th>
          <td><?= e($amountLabel) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <th scope="row">Total due</th>
          <td><?= e($amountLabel) ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="bill-invoice__pay">
      <h3 class="bill-invoice__pay-title"><?= $icon('wallet', 'ico ico--sm') ?> Pay this bill</h3>
      <p class="bill-invoice__pay-hint">Payments are processed by your estate office. Use the bill number as the payment reference, then quote it if payment links are unavailable.</p>
      <form method="post" action="/app/bills/<?= e(rawurlencode((string) ($invoice['uuid'] ?? ''))) ?>/pay-link">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary"><?= $icon('payments', 'ico ico--sm') ?> Pay <?= e($amountLabel) ?></button>
      </form>
    </div>
  </section>
</div>
