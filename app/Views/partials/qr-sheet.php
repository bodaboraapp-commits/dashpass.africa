<?php

declare(strict_types=1);

/**
 * Resident access-pass sheet (addendum §2D + visitor-access contract §7/§7.1).
 *
 * Rendered once by the app layout for every resident screen; opened by the Home
 * "Access / My QR" tile and the raised bottom-navigation QR button.
 *
 * When the controller provides `$next_pass` the sheet shows that visitor's
 * upcoming pass: visitor name, household label, validity, status and the
 * server-rendered QR image, with a link to the full pass screen. Otherwise it
 * shows a clear "No active visitor pass" state with an invitation shortcut.
 *
 * Security: this sheet links to the resident pass screen and the resident QR
 * image endpoint only, the public bearer pass token is shown on the pass
 * screen itself, not scattered through the app shell. It never contains a PIN.
 *
 * Accessibility: native <dialog> + role="dialog" + aria-modal + labelled title.
 * Hidden by default (`hidden` attribute); app.js removes it before showModal()
 * and restores it on close, adding Escape, backdrop-click and focus return.
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
$validUntilIso = '';
$passStatus = '';
$residentPassUrl = '';
$qrImageUrl = '';

if ($nextPass !== null) {
    $invitationUuid = trim((string) ($nextPass['invitation_uuid'] ?? ''));
    $visitorName = trim((string) ($nextPass['visitor_name'] ?? ''));
    $householdLabel = trim((string) ($nextPass['household_label'] ?? ''));
    $validUntilRaw = isset($nextPass['valid_until']) ? (string) $nextPass['valid_until'] : '';
    $validUntil = $validUntilRaw !== ''
        ? format_datetime($validUntilRaw, $timezone, 'd M Y, H:i')
        : '';
    $validUntilIso = trim($validUntilRaw);
    $statusLabel = trim((string) ($nextPass['status_label'] ?? ''));
    $statusTone = trim((string) ($nextPass['status_tone'] ?? 'neutral'));
    $passStatus = trim((string) ($nextPass['status'] ?? ''));
    if ($invitationUuid !== '') {
        $residentPassUrl = '/app/passes/' . rawurlencode($invitationUuid);
        $qrImageUrl = $residentPassUrl . '/qr.png';
    }
}

$hasPass = $residentPassUrl !== '';
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
<dialog id="qr-sheet" class="qr-sheet" role="dialog" aria-modal="true"
        aria-labelledby="qr-sheet-title" aria-describedby="qr-sheet-note" hidden
        data-has-pass="<?= $hasPass ? '1' : '0' ?>"
        <?php if ($hasPass): ?>
          data-qr-url="<?= e($qrImageUrl) ?>"
          data-pass-url="<?= e($residentPassUrl) ?>"
          data-visitor-name="<?= e($visitorName) ?>"
          data-valid-until="<?= e($validUntil) ?>"
          data-pass-uuid="<?= e($invitationUuid) ?>"
          data-household="<?= e($householdLabel) ?>"
          data-status="<?= e($passStatus) ?>"
          data-status-label="<?= e($statusLabel) ?>"
          data-valid-until-iso="<?= e($validUntilIso) ?>"
        <?php endif; ?>>
  <div class="qr-sheet__panel">
    <div class="qr-sheet__head">
      <h2 class="qr-sheet__title" id="qr-sheet-title">My access pass</h2>
      <button type="button" class="icon-btn qr-sheet__close" data-qr-close aria-label="Close access pass" title="Close access pass">
        <?= $icon('close', 'ico') ?>
      </button>
    </div>

    <?php if ($hasPass): ?>
      <div class="qr-sheet__pass">
        <p class="qr-sheet__eyebrow">Next visitor</p>
        <p class="qr-sheet__visitor"><?= e($visitorName !== '' ? $visitorName : 'Visitor') ?></p>
        <p class="qr-sheet__meta">
          <?php if ($householdLabel !== ''): ?>
            <span><?= e($householdLabel) ?></span>
          <?php endif; ?>
          <?php if ($validUntil !== ''): ?>
            <span>Valid until <?= e($validUntil) ?></span>
          <?php endif; ?>
        </p>
        <?php if ($statusLabel !== ''): ?>
          <span class="status <?= e($statusClass($statusTone)) ?>"><?= e($statusLabel) ?></span>
        <?php endif; ?>

        <?php if ($qrImageUrl !== ''): ?>
          <div class="qr-sheet__qr-box">
            <img class="qr-sheet__img" data-qr-image src="<?= e($qrImageUrl) ?>" alt="Access QR code"
                 width="220" height="220" loading="lazy" decoding="async">
          </div>
        <?php endif; ?>

        <p class="qr-sheet__note" id="qr-sheet-note">
          Show this QR at the gate, or open the pass to share the visitor link.
        </p>

        <a class="btn btn--primary btn--block qr-sheet__done" data-qr-pass-link href="<?= e($residentPassUrl) ?>">
          Open pass
        </a>
        <p class="qr-sheet__estate"><?= e($estateName !== '' ? $estateName : 'Your estate') ?></p>
      </div>
    <?php else: ?>
      <div class="qr-sheet__empty">
        <span class="qr-sheet__empty-icon" aria-hidden="true"><?= $icon('ticket') ?></span>
        <p class="qr-sheet__visitor">No active visitor pass</p>
        <p class="qr-sheet__note" id="qr-sheet-note">
          Pre-register a visitor and their gate QR will appear here.
        </p>
        <a class="btn btn--primary btn--block qr-sheet__done" href="/app/visitors/invite">Invite a visitor</a>
        <p class="qr-sheet__estate"><?= e($estateName !== '' ? $estateName : 'Your estate') ?></p>
      </div>
    <?php endif; ?>

    <button type="button" class="btn btn--outline btn--block qr-sheet__close-secondary" data-qr-close>Close</button>
  </div>
</dialog>
