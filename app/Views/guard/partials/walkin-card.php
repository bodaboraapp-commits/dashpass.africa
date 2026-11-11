<?php

declare(strict_types=1);

/**
 * One walk-in request row for the guard screens.
 *
 * Variables: $walkin (§7.1 WalkIn shape), $timezone (string).
 */

$walkin = isset($walkin) && is_array($walkin) ? $walkin : [];
$timezone = isset($timezone) && is_string($timezone) && $timezone !== ''
    ? $timezone
    : (string) config('app.timezone', 'Africa/Nairobi');

$visitorName = trim((string) ($walkin['visitor_name'] ?? ''));
$purpose = trim((string) ($walkin['purpose'] ?? ''));
$household = trim((string) ($walkin['household_label'] ?? ''));
$gateName = trim((string) ($walkin['gate_name'] ?? ''));
$requestedAt = trim((string) ($walkin['requested_at'] ?? ''));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<li class="g-walkin">
  <div class="g-walkin__body">
    <p class="g-walkin__name"><?= e($visitorName !== '' ? $visitorName : 'Walk-in visitor') ?></p>
    <p class="g-walkin__meta">
      <?php if ($household !== ''): ?>
        <span><?= e($household) ?></span>
      <?php endif; ?>
      <?php if ($purpose !== ''): ?>
        <span><?= e($purpose) ?></span>
      <?php endif; ?>
      <?php if ($gateName !== ''): ?>
        <span><?= e($gateName) ?></span>
      <?php endif; ?>
    </p>
    <?php if ($requestedAt !== ''): ?>
      <p class="g-walkin__when">
        <?= $icon('clock', 'ico ico--sm') ?>
        <span>Requested <?= e(format_datetime($requestedAt, $timezone, 'd M, H:i')) ?></span>
      </p>
    <?php endif; ?>
  </div>
  <div class="g-walkin__side">
    <?= \App\Core\View::capture('guard/partials/status-chip', [
        'status' => 'pending',
        'statusLabel' => 'AWAITING RESIDENT',
        'statusTone' => 'warn',
    ]) ?>
  </div>
</li>
