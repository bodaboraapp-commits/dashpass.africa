<?php

declare(strict_types=1);

/**
 * One active/expected visitor row for the guard screens.
 *
 * Variables: $item (§7.1 Item shape), $showCheckout (bool), $timezone (string).
 */

$item = isset($item) && is_array($item) ? $item : [];
$showCheckout = (bool) ($showCheckout ?? false);
$timezone = isset($timezone) && is_string($timezone) && $timezone !== ''
    ? $timezone
    : (string) config('app.timezone', 'Africa/Nairobi');

$uuid = trim((string) ($item['uuid'] ?? ''));
$visitorName = trim((string) ($item['visitor_name'] ?? ''));
$purpose = trim((string) ($item['purpose'] ?? ''));
$household = trim((string) ($item['household_label'] ?? ''));
$validFrom = trim((string) ($item['valid_from'] ?? ''));
$validUntil = trim((string) ($item['valid_until'] ?? ''));
$status = trim((string) ($item['status'] ?? ''));
$statusLabel = trim((string) ($item['status_label'] ?? ''));
$statusTone = trim((string) ($item['status_tone'] ?? ''));
$alreadyInside = (bool) ($item['already_inside'] ?? false) || $status === 'checked_in';
$canCheckout = $showCheckout && $uuid !== '' && $alreadyInside;

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$formatWhen = static function (string $iso) use ($timezone): string {
    return $iso === '' ? '' : format_datetime($iso, $timezone, 'd M, H:i');
};
?>
<li class="g-visitor">
  <div class="g-visitor__body">
    <p class="g-visitor__name"><?= e($visitorName !== '' ? $visitorName : 'Visitor') ?></p>
    <p class="g-visitor__meta">
      <?php if ($household !== ''): ?>
        <span class="g-visitor__household"><?= e($household) ?></span>
      <?php endif; ?>
      <?php if ($purpose !== ''): ?>
        <span class="g-visitor__purpose"><?= e($purpose) ?></span>
      <?php endif; ?>
    </p>
    <?php if ($validUntil !== ''): ?>
      <p class="g-visitor__when">
        <?= $icon('clock', 'ico ico--sm') ?>
        <span>
          <?php if ($alreadyInside): ?>
            Inside now
          <?php else: ?>
            Expected <?= e($formatWhen($validFrom)) ?> to <?= e($formatWhen($validUntil)) ?>
          <?php endif; ?>
        </span>
      </p>
    <?php endif; ?>
  </div>
  <div class="g-visitor__side">
    <?= \App\Core\View::capture('guard/partials/status-chip', [
        'status' => $status,
        'statusLabel' => $statusLabel,
        'statusTone' => $statusTone,
    ]) ?>
    <?php if ($canCheckout): ?>
      <form method="post" action="/gate/checkout" class="g-visitor__action"
            data-guard-checkout data-invitation-uuid="<?= e($uuid) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="invitation_uuid" value="<?= e($uuid) ?>">
        <button type="submit" class="btn btn--outline btn--sm">
          <?= $icon('logout', 'ico ico--sm') ?>
          Check out
        </button>
      </form>
    <?php endif; ?>
  </div>
</li>
