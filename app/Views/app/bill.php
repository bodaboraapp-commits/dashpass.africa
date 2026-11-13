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

// House details for the expanded bill (unit + building, household type, estate).
$householdUnit = trim((string) ($invoice['unit_number'] ?? ''));
$buildingName = trim((string) ($invoice['building_name'] ?? ''));
$estateName = trim((string) ($invoice['estate_name'] ?? ''));
$householdTypeLabel = match (trim((string) ($invoice['household_type'] ?? ''))) {
    'apartment' => 'Apartment',
    'maisonette' => 'Maisonette',
    'other' => 'Other',
    default => '',
};
$householdParts = array_values(array_filter(
    [$householdUnit, $buildingName],
    static fn (string $part): bool => $part !== ''
));
$householdLabel = implode(' · ', $householdParts);
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/bills" aria-label="Back to bills" title="Back to bills">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title"><?= e((string) ($invoice['invoice_number'] ?? 'Bill')) ?></h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>
  <section class="card bill-detail">
    <p class="bill-row__meta"><?= e((string) ($invoice['unit_number'] ?? 'Household')) ?> · Period <?= e((string) ($invoice['period_key'] ?? '-')) ?></p>
    <p class="bill-detail__amount"><?= e($money((int) ($invoice['amount_cents'] ?? 0), (string) ($invoice['currency'] ?? 'KES'))) ?></p>
    <p><span class="status <?= e($tone) ?>"><?= e(ucfirst(str_replace('_', ' ', $status !== '' ? $status : 'unknown'))) ?></span></p>
    <dl class="detail-list">
      <div><dt>Due</dt><dd><?= e(format_datetime((string) ($invoice['due_at'] ?? ''), (string) config('app.timezone', 'Africa/Nairobi'), 'd M Y')) ?></dd></div>
      <div><dt>Issued</dt><dd><?= e(format_datetime((string) ($invoice['created_at'] ?? ''), (string) config('app.timezone', 'Africa/Nairobi'), 'd M Y')) ?></dd></div>
    </dl>
    <div class="bill-detail__pay">
      <h2 class="card__title">Pay this bill</h2>
      <p class="text-muted">Payments are processed by your estate office. Use the bill number as the payment reference.</p>
      <form method="post" action="/app/bills/<?= e(rawurlencode((string) ($invoice['uuid'] ?? ''))) ?>/pay-link">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary">Pay now</button>
      </form>
      <p class="admin-field__hint">If payment links are unavailable, contact the estate office and quote this bill number.</p>
    </div>
  </section>

  <?php if ($householdLabel !== '' || $estateName !== ''): ?>
    <section class="card bill-house" aria-labelledby="bill-house-title">
      <h2 class="card__title bill-house__title" id="bill-house-title"><?= $icon('home', 'ico ico--sm') ?> House details</h2>
      <dl class="detail-list">
        <?php if ($householdLabel !== ''): ?>
          <div><dt>Household</dt><dd><?= e($householdLabel) ?></dd></div>
        <?php endif; ?>
        <?php if ($householdTypeLabel !== ''): ?>
          <div><dt>Type</dt><dd><?= e($householdTypeLabel) ?></dd></div>
        <?php endif; ?>
        <?php if ($estateName !== ''): ?>
          <div><dt>Estate</dt><dd><?= e($estateName) ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>
  <?php endif; ?>
</div>
