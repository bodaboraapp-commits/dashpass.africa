<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/_helpers.php';

$month = isset($month) && is_string($month) ? $month : '';
$run = isset($run) && is_array($run) ? $run : ['month' => $month, 'schedules' => [], 'totals' => []];
$schedules = is_array($run['schedules'] ?? null) ? array_values(array_filter($run['schedules'], 'is_array')) : [];
$totals = is_array($run['totals'] ?? null) ? $run['totals'] : [];
$collections = isset($collections) && is_array($collections) ? $collections : ['totals' => []];
$collectionTotals = is_array($collections['totals'] ?? null) ? $collections['totals'] : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$timezone = admin_timezone(isset($estate) && is_array($estate) ? $estate : []);
$money = static fn (mixed $cents, string $currency = 'KES'): string => number_format(((int) $cents) / 100, 2) . ' ' . $currency;
$periodLabel = static function (array $schedule) use ($timezone): string {
    $start = admin_datetime(isset($schedule['period_start']) ? (string) $schedule['period_start'] : null, $timezone, 'j M Y');
    $end = admin_datetime(isset($schedule['period_end']) ? (string) $schedule['period_end'] : null, $timezone, 'j M Y');
    $due = admin_datetime(isset($schedule['due_at']) ? (string) $schedule['due_at'] : null, $timezone, 'j M Y');

    return $start . ' to ' . $end . ' · due ' . $due;
};
$basisLabel = static function (array $schedule) use ($money): string {
    $basis = (string) ($schedule['basis'] ?? 'flat');
    $flat = $money($schedule['flat_cents'] ?? 0);
    $rate = $money($schedule['rate_cents'] ?? 0);
    $unit = (string) ($schedule['unit_label'] ?? 'units');

    return match ($basis) {
        'metered' => 'Metered · ' . $rate . ' per ' . $unit,
        'both' => 'Flat ' . $flat . ' + ' . $rate . ' per ' . $unit,
        default => 'Flat · ' . $flat . ' per household',
    };
};
$unsentCount = 0;
$issuedTotal = 0;
foreach ($schedules as $schedule) {
    foreach ((array) ($schedule['households'] ?? []) as $household) {
        if (($household['existing'] ?? null) !== null) {
            $issuedTotal++;
            if (($household['existing']['sent_at'] ?? null) === null) {
                $unsentCount++;
            }
        }
    }
}
?>
<div class="admin-page">
  <div class="admin-page__head">
    <div>
      <h1 class="admin-page__title">Utilities</h1>
      <p class="admin-page__lede">Take this period's meter readings, issue one bill per household, then send them. Issued bills are final — a repeated run never double-bills.</p>
    </div>
    <div class="cluster">
      <a class="btn btn--ghost btn--sm" href="/admin/billing">Billing</a>
      <a class="btn btn--ghost btn--sm" href="/admin/billing/collections">Collections</a>
    </div>
  </div>

  <form method="get" action="/admin/utility-bills" class="admin-form admin-filterbar">
    <div class="admin-field">
      <label class="admin-field__label" for="utility_period">Billing month</label>
      <input class="input" type="month" id="utility_period" name="period" value="<?= e($month) ?>">
    </div>
    <button class="btn btn--primary btn--sm" type="submit">Apply</button>
    <p class="admin-field__hint">Utilities are billed for <?= e($month !== '' ? $month : 'the current month') ?>. Change the month to work on a past run.</p>
  </form>

  <?= admin_summary($errors) ?>

  <div class="admin-stats">
    <div class="admin-stat"><span class="admin-stat__value"><?= e((string) (int) ($totals['schedules'] ?? 0)) ?></span><span class="admin-stat__label">Utilities</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e((string) (int) ($totals['households'] ?? 0)) ?></span><span class="admin-stat__label">Households</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e((string) $issuedTotal) ?></span><span class="admin-stat__label">Bills issued</span></div>
    <div class="admin-stat"><span class="admin-stat__value"><?= e($money($collectionTotals['outstanding'] ?? 0)) ?></span><span class="admin-stat__label">Outstanding</span></div>
  </div>

  <?php if ($issuedTotal > 0): ?>
    <section class="admin-section" aria-labelledby="utility-send-title">
      <div class="admin-section__head">
        <h2 class="admin-section__title" id="utility-send-title">Send to residents</h2>
        <span class="chip"><?= e((string) $unsentCount) ?> waiting</span>
      </div>
      <p class="admin-field__hint">Sends the issued bills for <?= e($month) ?> to the household members who can receive them (in-app always; email/SMS/WhatsApp where the estate has them configured).</p>
      <form method="post" action="/admin/utility-bills/send" class="cluster">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($month) ?>">
        <button class="btn btn--primary btn--sm" type="submit"<?= $unsentCount === 0 ? ' disabled' : '' ?>>Send <?= e((string) $unsentCount) ?> bill<?= $unsentCount === 1 ? '' : 's' ?></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($schedules === []): ?>
    <div class="admin-empty">
      <p class="admin-empty__title">No utility schedules yet</p>
      <p class="admin-empty__text">A utility (water, electricity, garbage) has to be set up for this estate before a bill run. Ask the platform team to add one.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($schedules as $schedule): ?>
    <?php
      $uuid = (string) ($schedule['uuid'] ?? '');
      $households = is_array($schedule['households'] ?? null) ? $schedule['households'] : [];
      $issued = (int) ($schedule['issued_count'] ?? 0);
      $householdCount = (int) ($schedule['household_count'] ?? 0);
      $pending = $householdCount - $issued;
      $periodKey = (string) ($schedule['period_key'] ?? '');
      $scheduleOld = is_array($old[$uuid] ?? null) ? $old[$uuid] : [];
    ?>
    <section class="admin-section" aria-labelledby="utility-<?= e($uuid) ?>-title">
      <div class="admin-section__head">
        <h2 class="admin-section__title" id="utility-<?= e($uuid) ?>-title"><?= e((string) ($schedule['name'] ?? 'Utility')) ?></h2>
        <span class="chip"><?= e((string) $issued) ?> / <?= e((string) $householdCount) ?> issued</span>
      </div>
      <p class="admin-field__hint"><?= e($basisLabel($schedule)) ?> · period <?= e($periodLabel($schedule)) ?> · period key <?= e($periodKey) ?></p>

      <?php if ($pending > 0): ?>
        <form method="post" action="/admin/utility-bills/issue" class="utility-run" data-utility-run>
          <?= csrf_field() ?>
          <input type="hidden" name="period" value="<?= e($month) ?>">
          <div class="table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th scope="col">Household</th>
                  <th scope="col">Last reading</th>
                  <th scope="col">New reading</th>
                  <th scope="col">Usage</th>
                  <th scope="col">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($households as $household): ?>
                  <?php
                    $householdUuid = (string) ($household['uuid'] ?? '');
                    $existing = $household['existing'] ?? null;
                    $isOld = is_scalar($scheduleOld[$householdUuid] ?? null) ? trim((string) $scheduleOld[$householdUuid]) : '';
                    $last = $household['last_reading'] ?? null;
                  ?>
                  <tr data-utility-row data-rate="<?= e((string) (int) ($schedule['rate_cents'] ?? 0)) ?>" data-flat="<?= e((string) (int) ($schedule['flat_cents'] ?? 0)) ?>" data-last="<?= e($last === null ? '' : (string) (float) $last) ?>">
                    <td data-label="Household">
                      <?= e((string) ($household['unit_number'] ?? 'Household')) ?>
                      <?php if (($household['building_name'] ?? '') !== ''): ?><span class="admin-table__sub"><?= e((string) $household['building_name']) ?></span><?php endif; ?>
                      <?php if ($existing !== null): ?><span class="admin-table__sub">Issued · <?= e((string) ($existing['invoice_number'] ?? '')) ?></span><?php endif; ?>
                    </td>
                    <td data-label="Last reading"><?= e($last === null ? 'First bill' : (string) (float) $last) ?></td>
                    <td data-label="New reading">
                      <?php if ($existing !== null): ?>
                        <span class="status status--active">Issued</span>
                      <?php elseif ((string) ($schedule['basis'] ?? 'flat') === 'flat'): ?>
                        <span class="admin-table__sub">Flat</span>
                      <?php else: ?>
                        <input class="input utility-run__reading" type="number" inputmode="decimal" step="0.001" min="0"
                               id="reading-<?= e($uuid . '-' . $householdUuid) ?>"
                               name="readings[<?= e($uuid) ?>][<?= e($householdUuid) ?>]"
                               value="<?= e($isOld) ?>" aria-label="New reading for <?= e((string) ($household['unit_number'] ?? 'household')) ?>" data-utility-reading>
                      <?php endif; ?>
                    </td>
                    <td data-label="Usage" class="utility-run__usage" data-utility-usage><?= $existing !== null ? '—' : '' ?></td>
                    <td data-label="Amount" class="utility-run__amount" data-utility-amount></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th scope="row" colspan="4">Total for this run</th>
                  <td data-label="Total" class="utility-run__amount" data-utility-total><?= e($money(0)) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="cluster">
            <button class="btn btn--primary btn--sm" type="submit">Issue <?= e((string) $pending) ?> bill<?= $pending === 1 ? '' : 's' ?></button>
            <span class="admin-field__hint">Readings are checked before any bill is created; a repeated submit never double-bills.</span>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($issued > 0): ?>
        <div class="table-wrap">
          <table class="admin-table">
            <thead><tr><th scope="col">Issued bill</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Sent</th></tr></thead>
            <tbody>
            <?php foreach ($households as $household): ?>
              <?php $existing = $household['existing'] ?? null; if ($existing === null) { continue; } ?>
              <tr>
                <td data-label="Bill"><a class="admin-table__link" href="/admin/billing/invoices/<?= e(rawurlencode((string) ($existing['uuid'] ?? ''))) ?>"><?= e((string) ($existing['invoice_number'] ?? 'Bill')) ?></a><span class="admin-table__sub"><?= e((string) ($household['unit_number'] ?? '')) ?></span></td>
                <td data-label="Amount"><?= e($money($existing['amount_cents'] ?? 0, (string) ($existing['currency'] ?? 'KES'))) ?></td>
                <td data-label="Status"><span class="status <?= e(admin_tone_class('', (string) ($existing['status'] ?? ''))) ?>"><?= e(ucfirst((string) ($existing['status'] ?? 'unknown'))) ?></span></td>
                <td data-label="Sent"><?= e(($existing['sent_at'] ?? null) === null ? 'Not sent' : admin_datetime((string) $existing['sent_at'], $timezone, 'd M Y, H:i')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($households === []): ?>
        <div class="admin-empty"><p class="admin-empty__title">No active households</p><p class="admin-empty__text">Bills are issued to active households only. Add households before running utility billing.</p></div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
