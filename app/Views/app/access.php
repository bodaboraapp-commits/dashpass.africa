<?php

declare(strict_types=1);

/**
 * My access pass (full page, owner request 2026-11-13: no modal).
 *
 * Reached from the Home "Access / My Pass" tile and the centre QR button in the
 * bottom navigation. Shows the resident's next visitor pass as a centred card:
 * visitor name, household + validity, status, the server-rendered QR image, a
 * link to the full pass screen and the estate name. Honest empty state with an
 * invitation shortcut when there is no upcoming pass.
 *
 * Variables: $next_pass (array|null from InvitationService::nextPassForResident),
 * $estate, shell data. No PINs or bearer tokens are rendered here.
 */

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$nextPass = isset($next_pass) && is_array($next_pass) ? $next_pass : null;

$invitationUuid = '';
$visitorName = '';
$householdLabel = '';
$validUntil = '';
$statusLabel = '';
$statusTone = 'neutral';
$passUrl = '';
$qrImageUrl = '';

if ($nextPass !== null) {
    $invitationUuid = trim((string) ($nextPass['invitation_uuid'] ?? ''));
    $visitorName = trim((string) ($nextPass['visitor_name'] ?? ''));
    $householdLabel = trim((string) ($nextPass['household_label'] ?? ''));
    $validUntilRaw = isset($nextPass['valid_until']) ? (string) $nextPass['valid_until'] : '';
    $validUntil = $validUntilRaw !== '' ? format_datetime($validUntilRaw, $timezone, 'd M Y, H:i') : '';
    $statusLabel = trim((string) ($nextPass['status_label'] ?? ''));
    $statusTone = trim((string) ($nextPass['status_tone'] ?? 'neutral'));
    if ($invitationUuid !== '') {
        $passUrl = '/app/passes/' . rawurlencode($invitationUuid);
        $qrImageUrl = $passUrl . '/qr.png';
    }
}

$hasPass = $passUrl !== '';

$statusClass = static function (string $tone): string {
    return match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        default => 'status--inactive',
    };
};

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">My access pass</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <section class="access-page" aria-labelledby="access-pass-title">
    <div class="card access-card">
      <?php if ($hasPass): ?>
        <p class="access-card__eyebrow">Next visitor</p>
        <h2 class="access-card__visitor" id="access-pass-title"><?= e($visitorName !== '' ? $visitorName : 'Visitor') ?></h2>

        <p class="access-card__meta">
          <?php if ($householdLabel !== ''): ?><span><?= e($householdLabel) ?></span><?php endif; ?>
          <?php if ($validUntil !== ''): ?>
            <span><?= $icon('clock', 'ico ico--xs') ?> Valid until <?= e($validUntil) ?></span>
          <?php endif; ?>
        </p>

        <span class="status <?= e($statusClass($statusTone)) ?>"><?= e($statusLabel !== '' ? $statusLabel : 'Approved') ?></span>

        <div class="access-card__qr-box">
          <img class="access-card__qr" src="<?= e($qrImageUrl) ?>" alt="Access QR code for <?= e($visitorName !== '' ? $visitorName : 'your visitor') ?>" width="280" height="280" decoding="async">
        </div>

        <p class="access-card__note">
          Show this QR at the gate, or open the pass to share the visitor link.
        </p>

        <a class="btn btn--primary btn--block access-card__cta" href="<?= e($passUrl) ?>">Open pass</a>

        <?php if ($estateName !== ''): ?>
          <p class="access-card__estate"><?= e($estateName) ?></p>
        <?php endif; ?>
      <?php else: ?>
        <span class="access-card__empty-icon" aria-hidden="true"><?= $icon('ticket') ?></span>
        <h2 class="access-card__visitor" id="access-pass-title">No active visitor pass</h2>
        <p class="access-card__note">
          When you invite a visitor, their QR code will appear here for the gate.
        </p>
        <a class="btn btn--primary btn--block access-card__cta" href="/app/visitors/invite">Invite a visitor</a>
        <?php if ($estateName !== ''): ?>
          <p class="access-card__estate"><?= e($estateName) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <a class="btn btn--outline btn--block" href="/app">Close</a>
  </section>
</div>
