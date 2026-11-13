<?php

declare(strict_types=1);

/**
 * My access pass (full page, owner request 2026-11-13).
 *
 * Shows the resident household's people, not visitors: the Tenant (primary
 * resident), family members on the household and domestic workers in the
 * household staff directory. Each person has a signed, gate-scannable pass
 * rendered as a QR image. Deactivated people disappear from the list, which
 * also revokes their pass (gate verification reloads the live record).
 *
 * Variables: $passes (array<int,array<string,mixed>> from HouseholdAccessService),
 * $estate, shell data. No PINs or bearer visitor tokens are rendered here.
 */

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$passes = isset($passes) && is_array($passes) ? array_values(array_filter($passes, 'is_array')) : [];

$roleTone = static function (string $type): string {
    return $type === 'staff' ? 'status--pending' : 'status--active';
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

  <p class="page-lede">
    Your household's gate passes. Show a person's QR at the gate; each pass
    belongs to a named person in this household.
  </p>

  <section class="access-page" aria-labelledby="access-pass-title">
    <h2 class="visually-hidden" id="access-pass-title">Household access passes</h2>

    <?php if ($passes !== []): ?>
      <?php foreach ($passes as $pass): ?>
        <?php
        $type = trim((string) ($pass['type'] ?? ''));
        $name = trim((string) ($pass['name'] ?? ''));
        $roleLabel = trim((string) ($pass['role_label'] ?? 'Household member'));
        $householdLabel = trim((string) ($pass['household_label'] ?? ''));
        $qrUrl = trim((string) ($pass['qr_url'] ?? ''));
        $initials = trim((string) ($pass['initials'] ?? 'HP'));
        ?>
        <div class="card access-card" data-household-pass="<?= e($type) ?>">
          <div class="access-card__person">
            <span class="access-card__avatar" aria-hidden="true"><?= e($initials) ?></span>
            <div class="access-card__identity">
              <p class="access-card__eyebrow"><?= e($roleLabel) ?></p>
              <h3 class="access-card__visitor"><?= e($name !== '' ? $name : 'Household member') ?></h3>
              <p class="access-card__meta">
                <?php if ($householdLabel !== ''): ?>
                  <span><?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?></span>
                <?php endif; ?>
                <span class="status <?= e($roleTone($type)) ?>"><?= e((string) ($pass['status_label'] ?? 'Active')) ?></span>
              </p>
            </div>
          </div>

          <div class="access-card__qr-box">
            <img class="access-card__qr"
                 src="<?= e($qrUrl) ?>"
                 alt="Household access QR code for <?= e($name !== '' ? $name : 'household member') ?>"
                 width="280" height="280" decoding="async">
          </div>

          <p class="access-card__note">
            Show this QR at the gate. The guard sees who the pass belongs to.
          </p>
        </div>
      <?php endforeach; ?>

      <?php if ($estateName !== ''): ?>
        <p class="access-card__estate"><?= e($estateName) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <div class="card access-card">
        <span class="access-card__empty-icon" aria-hidden="true"><?= $icon('ticket') ?></span>
        <h3 class="access-card__visitor">No household pass yet</h3>
        <p class="access-card__note">
          Your household has no active Tenant, family member or domestic worker
          record yet. Ask your Estate Administrator to add you to a household.
        </p>
      </div>
    <?php endif; ?>

    <a class="btn btn--outline btn--block" href="/app">Close</a>
  </section>
</div>
