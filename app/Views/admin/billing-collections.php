<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/_helpers.php';

$collections = isset($collections) && is_array($collections) ? $collections : [];
$invoices = is_array($collections['invoices'] ?? null) ? array_values(array_filter($collections['invoices'], 'is_array')) : [];
$buckets = is_array($collections['buckets'] ?? null) ? $collections['buckets'] : [];
$totals = is_array($collections['totals'] ?? null) ? $collections['totals'] : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$timezone = admin_timezone(isset($estate) && is_array($estate) ? $estate : []);
$money = static fn (mixed $cents, string $currency = 'KES'): string => number_format(((int) $cents) / 100, 2) . ' ' . $currency;
$methods = ['mpesa' => 'M-Pesa', 'cash' => 'Cash', 'bank' => 'Bank', 'card' => 'Card', 'other' => 'Other'];
$bucketCopy = [
    'due_soon' => 'Due soon',
    'd1_7' => '1–7 days late',
    'd8_30' => '8–30 days late',
    'd31_plus' => '31+ days late',
];
?>
<div class="admin-page">
  <div class="admin-page__head">
    <div>
      <h1 class="admin-page__title">Collections</h1>
      <p class="admin-page__lede">Who still owes, how late they are, and the one-tap nudge. Record a cash or M-Pesa payment here and the household gets a receipt.</p>
    </div>
    <div class="cluster">
      <a class="btn btn--ghost btn--sm" href="/admin/utility-bills">Utilities</a>
      <a class="btn btn--ghost btn--sm" href="/admin/billing">Billing</a>
    </div>
  </div>

  <?= admin_summary($errors) ?>

  <div class="admin-stats">
    <div class="admin-stat"><span class="admin-stat__value"><?= e($money($totals['billed'] ?? 0)) ?></span><span class="admin-stat__label">Billed</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e($money($totals['collected'] ?? 0)) ?></span><span class="admin-stat__label">Collected</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e($money($totals['outstanding'] ?? 0)) ?></span><span class="admin-stat__label">Outstanding</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e((string) (int) ($totals['rate'] ?? 0)) ?>%</span><span class="admin-stat__label">Collected rate</span></div>
  </div>

  <div class="admin-stats admin-stats--buckets">
    <?php foreach ($bucketCopy as $key => $label): ?>
      <?php $bucket = is_array($buckets[$key] ?? null) ? $buckets[$key] : ['count' => 0, 'amount_cents' => 0]; ?>
      <div class="admin-stat admin-stat--bucket">
        <span class="admin-stat__value"><?= e((string) (int) ($bucket['count'] ?? 0)) ?></span>
        <span class="admin-stat__label"><?= e($label) ?> · <?= e($money($bucket['amount_cents'] ?? 0)) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="admin-section" aria-labelledby="collections-bills-title">
    <div class="admin-section__head">
      <h2 class="admin-section__title" id="collections-bills-title">Open &amp; past-due bills</h2>
      <span class="chip"><?= e((string) count($invoices)) ?></span>
    </div>

    <?php if ($invoices === []): ?>
      <div class="admin-empty">
        <p class="admin-empty__title">Nothing outstanding — nice</p>
        <p class="admin-empty__text">Every utility bill for this estate is settled. New bills will appear here as soon as they are issued.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Bill</th>
              <th scope="col">Amount</th>
              <th scope="col">Paid</th>
              <th scope="col">Balance</th>
              <th scope="col">Due</th>
              <th scope="col">Late</th>
              <th scope="col">Record payment / remind</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $invoice): ?>
              <?php
                $uuid = (string) ($invoice['uuid'] ?? '');
                $currency = (string) ($invoice['currency'] ?? 'KES');
                $balance = (int) ($invoice['balance_cents'] ?? 0);
                $daysLate = (int) ($invoice['days_late'] ?? 0);
              ?>
              <tr>
                <td data-label="Bill">
                  <a class="admin-table__link" href="/admin/billing/invoices/<?= e(rawurlencode($uuid)) ?>"><?= e((string) ($invoice['invoice_number'] ?? 'Bill')) ?></a>
                  <span class="admin-table__sub"><?= e((string) ($invoice['unit_number'] ?? 'Household')) ?><?= ($invoice['building_name'] ?? '') !== '' ? ' · ' . e((string) $invoice['building_name']) : '' ?></span>
                </td>
                <td data-label="Amount" class="admin-table__num"><?= e($money($invoice['amount_cents'] ?? 0, $currency)) ?></td>
                <td data-label="Paid" class="admin-table__num"><?= e($money($invoice['paid_cents'] ?? 0, $currency)) ?></td>
                <td data-label="Balance" class="admin-table__num"><?= e($money($balance, $currency)) ?></td>
                <td data-label="Due" class="admin-table__num"><?= e(admin_datetime((string) ($invoice['due_at'] ?? ''), $timezone, 'd M Y')) ?></td>
                <td data-label="Late"><?= $daysLate > 0 ? '<span class="status status--danger">' . e((string) $daysLate) . 'd</span>' : '<span class="status status--active">Due soon</span>' ?></td>
                <td data-label="Actions">
                  <div class="collections-actions">
                    <form method="post" action="/admin/billing/bills/<?= e(rawurlencode($uuid)) ?>/payment" class="collections-actions__pay">
                      <?= csrf_field() ?>
                      <input class="input" type="number" inputmode="decimal" step="0.01" min="0.01" name="amount" value="<?= e(number_format($balance / 100, 2, '.', '')) ?>" aria-label="Amount received for <?= e((string) ($invoice['invoice_number'] ?? 'bill')) ?>" required>
                      <select class="select" name="method" aria-label="Payment method">
                        <?php foreach ($methods as $value => $label): ?>
                          <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input class="input" type="text" name="reference" placeholder="M-Pesa code" aria-label="Payment reference" maxlength="80">
                      <button class="btn btn--primary btn--sm" type="submit">Record</button>
                    </form>
                    <form method="post" action="/admin/billing/bills/<?= e(rawurlencode($uuid)) ?>/remind">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">Nudge</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="admin-field__hint">Reminders go to the household's active members (in-app always; email/SMS/WhatsApp where the estate has them configured). A nudge is never duplicated for the same bill.</p>
    <?php endif; ?>
  </section>
</div>
