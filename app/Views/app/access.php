<?php

declare(strict_types=1);

/**
 * My access pass (owner request 2026-11-13).
 *
 * Shows ONLY the signed-in resident's own pass: QR image and pass code, plus a
 * button to create a pass for a household member (family member or domestic
 * worker) on /app/access/member.
 *
 * Variables: $pass (array|null from HouseholdAccessService::ownPass()), $estate,
 * shell data. The pass code is the signed token the guard can type/paste when
 * the QR cannot be scanned.
 */

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$pass = isset($pass) && is_array($pass) ? $pass : null;
$members = isset($members) && is_array($members) ? array_values(array_filter($members, 'is_array')) : [];

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
    <h2 class="visually-hidden" id="access-pass-title">My access pass</h2>

    <?php if ($pass !== null): ?>
      <?php
      $name = trim((string) ($pass['name'] ?? ''));
      $roleLabel = trim((string) ($pass['role_label'] ?? 'Household member'));
      $householdLabel = trim((string) ($pass['household_label'] ?? ''));
      $qrUrl = trim((string) ($pass['qr_url'] ?? ''));
      $passCode = trim((string) ($pass['pass_code'] ?? $pass['token'] ?? ''));
      $initials = trim((string) ($pass['initials'] ?? 'HP'));
      ?>
      <div class="card access-card">
        <div class="access-card__person">
          <span class="access-card__avatar" aria-hidden="true"><?= e($initials) ?></span>
          <div class="access-card__identity">
            <p class="access-card__eyebrow"><?= e($roleLabel) ?></p>
            <h2 class="access-card__visitor"><?= e($name !== '' ? $name : 'Household member') ?></h2>
            <p class="access-card__meta">
              <?php if ($householdLabel !== ''): ?>
                <span><?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?></span>
              <?php endif; ?>
              <span class="status status--active"><?= e((string) ($pass['status_label'] ?? 'Active')) ?></span>
            </p>
          </div>
        </div>

        <div class="access-card__qr-box">
          <img class="access-card__qr"
               src="<?= e($qrUrl) ?>"
               alt="My household access QR code"
               width="280" height="280" decoding="async">
        </div>

        <?php if ($estateName !== ''): ?>
          <p class="access-card__estate"><?= e($estateName) ?></p>
        <?php endif; ?>
      </div>

      <?= \App\Core\View::capture('partials/access-pin-card', [
          'passCode' => $passCode,
          'passType' => (string) ($pass['type'] ?? 'member'),
          'passId' => (int) ($pass['id'] ?? 0),
          'returnTo' => '/app/access',
      ]) ?>

      <a class="btn btn--primary btn--block" href="/app/access/member">
        <?= $icon('plus', 'ico ico--sm') ?>
        Create pass for a household member
      </a>
    <?php else: ?>
      <div class="card access-card">
        <span class="access-card__empty-icon" aria-hidden="true"><?= $icon('ticket') ?></span>
        <h2 class="access-card__visitor">No personal pass yet</h2>
        <p class="access-card__note">
          Your account is not linked to an active household yet. Ask your Estate
          Administrator to add you to a household.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($members !== []): ?>
      <section class="household-members" aria-labelledby="household-members-title">
        <div class="household-members__head">
          <h2 class="household-members__title" id="household-members-title">Household members</h2>
          <span class="household-members__count"><?= e((string) count($members)) ?></span>
        </div>
        <ul class="household-members__list" role="list">
          <?php foreach ($members as $member): ?>
            <?php
            $memberType = trim((string) ($member['type'] ?? ''));
            $memberId = (int) ($member['id'] ?? 0);
            $memberName = trim((string) ($member['name'] ?? ''));
            $memberRole = trim((string) ($member['role_label'] ?? 'Household member'));
            $memberInitials = trim((string) ($member['initials'] ?? 'HP'));
            $isSelf = (bool) ($member['is_self'] ?? false);
            $memberLink = '/app/access/member?type=' . rawurlencode($memberType) . '&id=' . $memberId;
            ?>
            <li class="household-member" data-household-member="<?= e($memberType) ?>">
              <span class="household-member__avatar" aria-hidden="true"><?= e($memberInitials) ?></span>
              <div class="household-member__identity">
                <p class="household-member__name"><?= e($memberName !== '' ? $memberName : 'Household member') ?></p>
                <p class="household-member__role"><?= e($memberRole) ?><?= $isSelf ? ' - You' : '' ?></p>
              </div>
              <div class="household-member__aside">
                <span class="status status--active"><?= e((string) ($member['status_label'] ?? 'Active')) ?></span>
                <?php if ($isSelf): ?>
                  <span class="household-member__self">Your pass</span>
                <?php else: ?>
                  <a class="btn btn--outline btn--sm" href="<?= e($memberLink) ?>">View pass</a>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <a class="btn btn--outline btn--block" href="/app">Close</a>
  </section>
</div>
