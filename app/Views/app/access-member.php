<?php

declare(strict_types=1);

/**
 * Create a household member pass (owner request 2026-11-13).
 *
 * Reached from the "Create pass for a household member" button on /app/access.
 * Lists the household's family members and domestic workers (never the
 * caller's own pass and never visitors), lets the resident choose one and then
 * shows that person's signed QR pass and pass code.
 *
 * Variables: $members (array from HouseholdAccessService::othersPasses()),
 * $selected_pass (array|null), $error (string|null), $estate, shell data.
 */

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$members = isset($members) && is_array($members) ? array_values(array_filter($members, 'is_array')) : [];
$selected = isset($selected_pass) && is_array($selected_pass) ? $selected_pass : null;
$error = isset($error) && is_string($error) && trim($error) !== '' ? trim($error) : null;

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/access" aria-label="Back to my access pass" title="Back to my access pass">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Create a household pass</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <section class="access-page" aria-labelledby="access-member-title">
    <h2 class="visually-hidden" id="access-member-title">Create a household pass</h2>

    <?php if ($error !== null): ?>
      <div class="alert alert--error" role="alert">
        <?= $icon('alert') ?>
        <div class="alert__body">
          <p class="alert__title">Pass not created</p>
          <p><?= e($error) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($selected !== null): ?>
      <?php
      $name = trim((string) ($selected['name'] ?? ''));
      $roleLabel = trim((string) ($selected['role_label'] ?? 'Household member'));
      $householdLabel = trim((string) ($selected['household_label'] ?? ''));
      $qrUrl = trim((string) ($selected['qr_url'] ?? ''));
      $passCode = trim((string) ($selected['pass_code'] ?? $selected['token'] ?? ''));
      $initials = trim((string) ($selected['initials'] ?? 'HP'));
      ?>
      <div class="card access-card" data-created-pass="<?= e((string) ($selected['type'] ?? '')) ?>">
        <div class="access-card__person">
          <span class="access-card__avatar" aria-hidden="true"><?= e($initials) ?></span>
          <div class="access-card__identity">
            <p class="access-card__eyebrow"><?= e($roleLabel) ?></p>
            <h2 class="access-card__visitor"><?= e($name !== '' ? $name : 'Household member') ?></h2>
            <p class="access-card__meta">
              <?php if ($householdLabel !== ''): ?>
                <span><?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?></span>
              <?php endif; ?>
              <span class="status status--active"><?= e((string) ($selected['status_label'] ?? 'Active')) ?></span>
            </p>
          </div>
        </div>

        <div class="access-card__qr-box">
          <img class="access-card__qr"
               src="<?= e($qrUrl) ?>"
               alt="Household access QR code for <?= e($name !== '' ? $name : 'household member') ?>"
               width="280" height="280" decoding="async">
        </div>

        <?php if ($estateName !== ''): ?>
          <p class="access-card__estate"><?= e($estateName) ?></p>
        <?php endif; ?>
      </div>

      <?= \App\Core\View::capture('partials/access-pin-card', [
          'passCode' => $passCode,
          'passType' => (string) ($selected['type'] ?? 'member'),
          'passId' => (int) ($selected['id'] ?? 0),
          'returnTo' => '/app/access/member',
      ]) ?>
    <?php endif; ?>

    <div class="card access-card access-card--form">
      <h2 class="access-card__visitor"><?= $selected !== null ? 'Create another pass' : 'Choose a household member' ?></h2>
      <p class="access-card__note">Passes are for your household's family members and domestic workers only.</p>

      <?php if ($members !== []): ?>
        <form method="post" action="/app/access/member" class="access-member-form">
          <?= csrf_field() ?>
          <div class="field">
            <label class="field__label" for="household-member">Household member</label>
            <select class="input" id="household-member" name="member" required>
              <option value="">Select a household member</option>
              <?php foreach ($members as $member): ?>
                <?php
                $value = (string) ($member['type'] ?? '') . ':' . (string) ($member['id'] ?? '');
                $label = trim((string) ($member['name'] ?? ''));
                $role = trim((string) ($member['role_label'] ?? ''));
                ?>
                <option value="<?= e($value) ?>"><?= e($label . ($role !== '' ? ' (' . $role . ')' : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn--primary btn--block">
            <?= $icon('plus', 'ico ico--sm') ?>
            Create pass
          </button>
        </form>
      <?php else: ?>
        <p class="access-card__note">
          Your household has no other family members or domestic workers yet. Ask
          your Estate Administrator to add them, then come back here.
        </p>
      <?php endif; ?>
    </div>

    <a class="btn btn--outline btn--block" href="/app/access">Back to my pass</a>
  </section>
</div>
