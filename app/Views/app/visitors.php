<?php

declare(strict_types=1);

/**
 * Visitors screen — real invitation data (visitor-access-module contract §7,
 * §7.1).
 *
 * Server-rendered Upcoming/History tabs (work without JavaScript), pending
 * walk-in approvals with approve/reject forms, visitor cards with status chips
 * and pass/revoke actions, honest empty states, and a sticky "+ Invite Visitor"
 * CTA. There is no preview content on this screen any more.
 *
 * Variables (§7.1): $visitors_tab, $upcoming (Item[]), $history (Item[]),
 * $pending_walkins (WalkIn[]) plus shell data.
 */

$activeTab = isset($visitors_tab) && $visitors_tab === 'history' ? 'history' : 'upcoming';
$upcoming = isset($upcoming) && is_array($upcoming) ? $upcoming : [];
$history = isset($history) && is_array($history) ? $history : [];
$pendingWalkins = isset($pending_walkins) && is_array($pending_walkins) ? $pending_walkins : [];
$rows = $activeTab === 'history' ? $history : $upcoming;

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$initialsFor = static function (string $name): string {
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], static fn (string $part): bool => $part !== ''));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'V';
};

$formatWhen = static function (?string $iso) use ($timezone): string {
    if (!is_string($iso) || trim($iso) === '') {
        return '—';
    }

    return format_datetime($iso, $timezone, 'd M Y, H:i');
};

$statusClass = static function (string $tone): string {
    return match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        default => 'status--inactive',
    };
};
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Visitors</h1>
    <a class="screen-bar__action" href="/app/visitors/invite" aria-label="Invite a visitor" title="Invite a visitor">
      <?= $icon('plus', 'ico') ?>
    </a>
  </header>

  <div class="seg-tabs" role="group" aria-label="Visitor lists">
    <a class="seg-tab<?= $activeTab === 'upcoming' ? ' seg-tab--active' : '' ?>"
       href="/app/visitors"
       <?= $activeTab === 'upcoming' ? 'aria-current="page"' : '' ?>>Upcoming</a>
    <a class="seg-tab<?= $activeTab === 'history' ? ' seg-tab--active' : '' ?>"
       href="/app/visitors?tab=history"
       <?= $activeTab === 'history' ? 'aria-current="page"' : '' ?>>History</a>
  </div>

  <?php if ($pendingWalkins !== []): ?>
    <section class="resident-section" aria-labelledby="walkins-title">
      <div class="resident-section__head">
        <div>
          <h2 class="resident-section__title" id="walkins-title">Walk-in approvals</h2>
          <p class="resident-section__hint">Someone at the gate is asking to visit your household.</p>
        </div>
        <span class="chip chip--sunshine"><?= e((string) count($pendingWalkins)) ?> waiting</span>
      </div>

      <ul class="approval-list">
        <?php foreach ($pendingWalkins as $walkin): ?>
          <?php
          if (!is_array($walkin)) {
              continue;
          }
          $walkinUuid = trim((string) ($walkin['uuid'] ?? ''));
          $visitorName = trim((string) ($walkin['visitor_name'] ?? 'Visitor'));
          $purpose = trim((string) ($walkin['purpose'] ?? ''));
          $gateName = trim((string) ($walkin['gate_name'] ?? ''));
          $householdLabel = trim((string) ($walkin['household_label'] ?? ''));
          $requestedAt = isset($walkin['requested_at']) ? (string) $walkin['requested_at'] : '';
          ?>
          <li class="approval-card">
            <div class="approval-card__head">
              <span class="approval-card__avatar" aria-hidden="true"><?= e($initialsFor($visitorName)) ?></span>
              <div class="approval-card__body">
                <p class="approval-card__name"><?= e($visitorName) ?></p>
                <p class="approval-card__meta">
                  <?php if ($purpose !== ''): ?>
                    <?= e($purpose) ?><?php if ($gateName !== ''): ?> · <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($gateName !== ''): ?>At <?= e($gateName) ?><?php endif; ?>
                </p>
              </div>
              <span class="status status--pending">Pending</span>
            </div>

            <p class="approval-card__details">
              <?php if ($householdLabel !== ''): ?>
                <span class="approval-card__detail"><?= $icon('home', 'ico ico--sm') ?> <?= e($householdLabel) ?></span>
              <?php endif; ?>
              <?php if ($requestedAt !== ''): ?>
                <span class="approval-card__detail"><?= $icon('clock', 'ico ico--sm') ?> <?= e($formatWhen($requestedAt)) ?></span>
              <?php endif; ?>
            </p>

            <?php if ($walkinUuid !== ''): ?>
              <div class="approval-card__actions">
                <form method="post" action="/app/walkins/<?= e(rawurlencode($walkinUuid)) ?>/approve">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn--primary btn--sm">Approve</button>
                </form>
                <form method="post" action="/app/walkins/<?= e(rawurlencode($walkinUuid)) ?>/reject">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn--danger-ghost btn--sm">Reject</button>
                </form>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="resident-section" aria-labelledby="visitor-list-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="visitor-list-title">
        <?= $activeTab === 'history' ? 'Past visits' : 'Upcoming visitors' ?>
      </h2>
      <?php if ($rows !== []): ?>
        <span class="chip"><?= e((string) count($rows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($rows !== []): ?>
      <ul class="visitor-list">
        <?php foreach ($rows as $row): ?>
          <?php
          if (!is_array($row)) {
              continue;
          }
          $invitationUuid = trim((string) ($row['uuid'] ?? ''));
          $visitorName = trim((string) ($row['visitor_name'] ?? 'Visitor'));
          $purpose = trim((string) ($row['purpose'] ?? ''));
          $householdLabel = trim((string) ($row['household_label'] ?? ''));
          $status = trim((string) ($row['status'] ?? ''));
          $statusLabel = trim((string) ($row['status_label'] ?? ''));
          $statusTone = trim((string) ($row['status_tone'] ?? 'neutral'));
          $alreadyInside = (bool) ($row['already_inside'] ?? false);
          $validFrom = isset($row['valid_from']) ? (string) $row['valid_from'] : '';
          $validUntil = isset($row['valid_until']) ? (string) $row['valid_until'] : '';
          $canRevoke = in_array($status, ['approved', 'checked_in'], true);
          ?>
          <li class="visitor-card visitor-card--rich">
            <div class="visitor-card__head">
              <span class="visitor-card__avatar" aria-hidden="true"><?= e($initialsFor($visitorName)) ?></span>
              <div class="visitor-card__body">
                <span class="visitor-card__name"><?= e($visitorName) ?></span>
                <?php if ($purpose !== ''): ?>
                  <span class="visitor-card__purpose"><?= e($purpose) ?></span>
                <?php endif; ?>
                <?php if ($householdLabel !== ''): ?>
                  <span class="visitor-card__meta"><?= $icon('home', 'ico ico--sm') ?> <?= e($householdLabel) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($statusLabel !== ''): ?>
                <span class="status <?= e($statusClass($statusTone)) ?>"><?= e($statusLabel) ?></span>
              <?php endif; ?>
            </div>

            <p class="visitor-card__when">
              <?= $icon('clock', 'ico ico--sm') ?>
              <?php if ($validFrom !== '' && $validUntil !== ''): ?>
                <?= e($formatWhen($validFrom)) ?> — <?= e($formatWhen($validUntil)) ?>
              <?php elseif ($validUntil !== ''): ?>
                Valid until <?= e($formatWhen($validUntil)) ?>
              <?php else: ?>
                Validity not set
              <?php endif; ?>
              <?php if ($alreadyInside): ?>
                <span class="chip chip--emerald">Inside now</span>
              <?php endif; ?>
            </p>

            <div class="visitor-card__actions">
              <?php if ($invitationUuid !== ''): ?>
                <a class="btn btn--outline btn--sm" href="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>">
                  <?= $icon('ticket', 'ico ico--sm') ?>
                  View pass
                </a>
              <?php endif; ?>
              <?php if ($canRevoke && $invitationUuid !== ''): ?>
                <form method="post" action="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>/revoke"
                      data-confirm="Revoke this pass? The visitor will no longer be able to enter.">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn--danger-ghost btn--sm">Revoke</button>
                </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($activeTab === 'history'): ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('clock') ?></span>
        <p class="resident-empty__title">No visitor history yet</p>
        <p class="resident-empty__text">Checked-out, revoked and expired visits will appear here.</p>
      </div>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('ticket') ?></span>
        <p class="resident-empty__title">No visitors scheduled</p>
        <p class="resident-empty__text">
          <?php if ($estateName !== ''): ?>
            Pre-register a visitor for <?= e($estateName) ?> and they will receive a PIN and pass link by SMS.
          <?php else: ?>
            Pre-register a visitor and they will receive a PIN and pass link by SMS.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>
  </section>

  <div class="cta-dock">
    <a class="btn btn--primary btn--lg btn--block" href="/app/visitors/invite">+ Invite Visitor</a>
  </div>
</div>
