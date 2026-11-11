<?php

declare(strict_types=1);

/**
 * Public visitor pass page — `/v/{token}` (visitor-access contract §4.7, §7).
 *
 * Rendered inside the guest layout for an unauthenticated visitor. It shows
 * only the estate, visitor name, household label, validity window and status —
 * never a PIN, QR token, resident phone/email or any internal identifiers.
 * `$invalid = true` (or a null pass) renders the neutral "not available" state;
 * the controller serves that with HTTP 404.
 *
 * Variables (§7.1): $pass (null | array), $invalid (bool)
 */

$invalid = (bool) ($invalid ?? false);
$pass = isset($pass) && is_array($pass) ? $pass : null;

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$statusClass = static function (string $tone): string {
    return match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        default => 'status--inactive',
    };
};

$formatWhen = static function (?string $iso): string {
    if (!is_string($iso) || trim($iso) === '') {
        return '—';
    }

    return format_datetime($iso, (string) config('app.timezone', 'Africa/Nairobi'), 'd M Y, H:i');
};

if ($invalid || $pass === null) {
    ?>
    <div class="public-pass public-pass--neutral">
      <span class="public-pass__icon" aria-hidden="true"><?= $icon('shield') ?></span>
      <span class="status status--inactive">Pass unavailable</span>
      <h1 class="public-pass__title">This pass link is not available</h1>
      <p class="public-pass__text">
        The link may be incorrect, expired or revoked. Ask your host to send you a new
        visitor pass from <?= e((string) config('app.name', 'MtaaPass')) ?>.
      </p>
      <a class="btn btn--outline btn--lg btn--block" href="/">Go to <?= e((string) config('app.name', 'MtaaPass')) ?></a>
    </div>
    <?php
    return;
}

$estateName = trim((string) ($pass['estate_name'] ?? ''));
$visitorName = trim((string) ($pass['visitor_name'] ?? 'Visitor'));
$householdLabel = trim((string) ($pass['household_label'] ?? ''));
$statusLabel = trim((string) ($pass['status_label'] ?? ''));
$statusTone = trim((string) ($pass['status_tone'] ?? 'neutral'));
$validFrom = isset($pass['valid_from']) ? (string) $pass['valid_from'] : '';
$validUntil = isset($pass['valid_until']) ? (string) $pass['valid_until'] : '';
$validWindow = trim((string) ($pass['valid_window'] ?? ''));
$instructions = trim((string) ($pass['instructions'] ?? ''));
$statusClassValue = $statusClass($statusTone);

if ($instructions === '') {
    $instructions = 'Show this page or your access PIN to the security team at the gate. '
        . 'The pass is valid only during the window shown above.';
}
?>
<div class="public-pass">
  <section class="public-pass-card" aria-labelledby="public-pass-title">
    <p class="public-pass__eyebrow"><?= $icon('ticket', 'ico ico--sm') ?> Visitor pass</p>

    <h1 class="public-pass__title" id="public-pass-title"><?= e($visitorName) ?></h1>

    <?php if ($statusLabel !== ''): ?>
      <span class="status <?= e($statusClassValue) ?>"><?= e($statusLabel) ?></span>
    <?php endif; ?>

    <dl class="public-pass__meta">
      <?php if ($estateName !== ''): ?>
        <div class="public-pass__meta-item">
          <dt>Estate</dt>
          <dd><?= e($estateName) ?></dd>
        </div>
      <?php endif; ?>
      <?php if ($householdLabel !== ''): ?>
        <div class="public-pass__meta-item">
          <dt>Visiting</dt>
          <dd><?= e($householdLabel) ?></dd>
        </div>
      <?php endif; ?>
      <?php if ($validWindow !== ''): ?>
        <div class="public-pass__meta-item">
          <dt>Valid window</dt>
          <dd><?= e($validWindow) ?></dd>
        </div>
      <?php else: ?>
        <div class="public-pass__meta-item">
          <dt>Valid from</dt>
          <dd><?= e($formatWhen($validFrom)) ?></dd>
        </div>
        <div class="public-pass__meta-item">
          <dt>Valid until</dt>
          <dd><?= e($formatWhen($validUntil)) ?></dd>
        </div>
      <?php endif; ?>
    </dl>

    <div class="public-pass__instructions">
      <?= $icon('info', 'ico ico--sm') ?>
      <p><?= e($instructions) ?></p>
    </div>

    <p class="public-pass__note">
      This page never shows your access PIN or your host's contact details.
      Keep your PIN private and never share it publicly.
    </p>

    <a class="btn btn--outline btn--lg btn--block" href="/">Back to <?= e((string) config('app.name', 'MtaaPass')) ?></a>
  </section>
</div>
