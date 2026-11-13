<?php

declare(strict_types=1);

/**
 * Guard dashboard (visitor-access-module-contract §7, §7.1).
 *
 * Gate selection starts the shift; once a gate is selected the guard sees live
 * stats, the visitors currently inside and pending walk-in requests, plus large
 * shortcuts into the verify and walk-in screens. All data optional/defensive.
 *
 * Variables: shell + $stats {inside, expected, pending_walkins}, $active [Item],
 * $pending_walkins [WalkIn].
 */

$stats = isset($stats) && is_array($stats) ? $stats : [];
$inside = (int) ($stats['inside'] ?? 0);
$expected = (int) ($stats['expected'] ?? 0);
$pendingCount = (int) ($stats['pending_walkins'] ?? 0);
$active = isset($active) && is_array($active) ? $active : [];
$pendingWalkins = isset($pending_walkins) && is_array($pending_walkins) ? $pending_walkins : [];
$gates = isset($gates) && is_array($gates) ? $gates : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$user = isset($user) && is_array($user) ? $user : [];
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$assignedGates = [];
$otherGates = [];

foreach ($gates as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }

    $name = trim((string) ($candidate['name'] ?? ''));
    $uuid = trim((string) ($candidate['uuid'] ?? ''));

    if ($name === '' || $uuid === '') {
        continue;
    }

    $entry = ['uuid' => $uuid, 'name' => $name, 'assigned' => (bool) ($candidate['assigned'] ?? false)];

    if ($entry['assigned']) {
        $assignedGates[] = $entry;
    } else {
        $otherGates[] = $entry;
    }
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$firstName = trim((string) ($user['full_name'] ?? ''));
$firstName = $firstName !== '' ? explode(' ', $firstName)[0] : 'Guard';
$activePreview = array_slice(array_values(array_filter($active, 'is_array')), 0, 4);
$walkinPreview = array_slice(array_values(array_filter($pendingWalkins, 'is_array')), 0, 3);
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow">Welcome back, <?= e($firstName) ?></p>
      <h1 class="g-head__title">Gate dashboard</h1>
    </div>
    <?php if ($gateName !== ''): ?>
      <p class="g-head__gate"><?= $icon('gate', 'ico ico--sm') ?><span><?= e($gateName) ?></span></p>
    <?php endif; ?>
  </header>

  <?php if ($gateName === ''): ?>
    <section class="g-panel" aria-labelledby="gate-select-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="gate-select-title">Select your gate</h2>
        <p class="g-panel__hint">Your shift starts once you choose one of your assigned gates.</p>
      </div>

      <?php if ($assignedGates !== []): ?>
        <ul class="g-gate-list">
          <?php foreach ($assignedGates as $assignedGate): ?>
            <li>
              <form method="post" action="/gate/select-gate" class="g-gate-form">
                <?= csrf_field() ?>
                <input type="hidden" name="gate_uuid" value="<?= e($assignedGate['uuid']) ?>">
                <button type="submit" class="g-gate-option">
                  <span class="g-gate-option__icon" aria-hidden="true"><?= $icon('gate') ?></span>
                  <span class="g-gate-option__body">
                    <span class="g-gate-option__name"><?= e($assignedGate['name']) ?></span>
                    <span class="g-gate-option__hint">Start shift at this gate</span>
                  </span>
                  <span class="g-gate-option__go" aria-hidden="true"><?= $icon('chevron', 'ico ico--sm') ?></span>
                </button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="g-empty">
          <span class="g-empty__icon" aria-hidden="true"><?= $icon('shield') ?></span>
          <p class="g-empty__title">No gate assigned yet</p>
          <p class="g-empty__text">
            Your supervisor has not assigned you to a gate in this estate. Ask them to add you before your next shift.
          </p>
        </div>
      <?php endif; ?>

      <?php if ($otherGates !== []): ?>
        <p class="g-muted g-panel__foot">
          Not assigned:
          <?= e(implode(', ', array_map(static fn (array $row): string => $row['name'], $otherGates))) ?>
        </p>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="g-stats" aria-label="Live gate stats">
      <a class="g-stat g-stat--inside" href="/gate/active">
        <span class="g-stat__label">Inside</span>
        <span class="g-stat__value"><?= e((string) $inside) ?></span>
        <span class="g-stat__hint">Visitors on site</span>
      </a>
      <div class="g-stat g-stat--expected">
        <span class="g-stat__label">Expected</span>
        <span class="g-stat__value"><?= e((string) $expected) ?></span>
        <span class="g-stat__hint">Approved passes</span>
      </div>
      <a class="g-stat g-stat--pending" href="/gate/walkin">
        <span class="g-stat__label">Walk-ins</span>
        <span class="g-stat__value"><?= e((string) $pendingCount) ?></span>
        <span class="g-stat__hint">Awaiting resident</span>
      </a>
    </section>

    <div class="g-actions">
      <a class="btn btn--primary btn--lg btn--block g-action" href="/gate/verify">
        <?= $icon('key', 'ico') ?>
        Verify a visitor
      </a>
      <a class="btn btn--outline btn--lg btn--block g-action" href="/gate/walkin">
        <?= $icon('ticket', 'ico') ?>
        Create walk-in request
      </a>
      <a class="btn btn--outline btn--lg btn--block g-action" href="/gate/incidents/new">
        <?= $icon('alert', 'ico') ?>
        Report an incident
      </a>
      <a class="btn btn--outline btn--lg btn--block g-action" href="/gate/notices">
        <?= $icon('bell', 'ico') ?>
        Read notices
      </a>
      <a class="btn btn--outline btn--lg btn--block g-action" href="/gate/goods">
        <?= $icon('list', 'ico') ?>
        Goods in / out
      </a>
      <a class="btn btn--outline btn--lg btn--block g-action" href="/gate/parking/violations">
        <?= $icon('parking', 'ico') ?>
        Parking violations
      </a>
    </div>

    <section class="g-panel" aria-labelledby="inside-now-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="inside-now-title">Inside now</h2>
        <a class="g-panel__link" href="/gate/active">View all</a>
      </div>

      <?php if ($activePreview !== []): ?>
        <ul class="g-list">
          <?php foreach ($activePreview as $item): ?>
            <?= \App\Core\View::capture('guard/partials/visitor-card', [
                'item' => $item,
                'showCheckout' => true,
                'timezone' => $timezone,
            ]) ?>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="g-empty-line">No visitors are inside right now.</p>
      <?php endif; ?>
    </section>

    <section class="g-panel" aria-labelledby="pending-walkins-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="pending-walkins-title">Pending walk-ins</h2>
        <a class="g-panel__link" href="/gate/walkin">Manage</a>
      </div>

      <?php if ($walkinPreview !== []): ?>
        <ul class="g-list">
          <?php foreach ($walkinPreview as $walkin): ?>
            <?= \App\Core\View::capture('guard/partials/walkin-card', [
                'walkin' => $walkin,
                'timezone' => $timezone,
            ]) ?>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="g-empty-line">No walk-ins waiting for a resident reply.</p>
      <?php endif; ?>
    </section>

    <?php if (count($assignedGates) > 1): ?>
      <section class="g-panel" aria-labelledby="switch-gate-title">
        <div class="g-panel__head">
          <h2 class="g-panel__title" id="switch-gate-title">Switch gate</h2>
        </div>
        <ul class="g-switch-list">
          <?php foreach ($assignedGates as $assignedGate): ?>
            <?php if ($assignedGate['uuid'] === (string) ($selectedGate['uuid'] ?? '')) { continue; } ?>
            <li>
              <form method="post" action="/gate/select-gate">
                <?= csrf_field() ?>
                <input type="hidden" name="gate_uuid" value="<?= e($assignedGate['uuid']) ?>">
                <button type="submit" class="btn btn--ghost btn--sm">
                  <?= $icon('gate', 'ico ico--sm') ?>
                  Move to <?= e($assignedGate['name']) ?>
                </button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
