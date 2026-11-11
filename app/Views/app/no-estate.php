<?php

declare(strict_types=1);

/**
 * No-estate explanation (guest layout).
 * Variables (contract §8.2): $csrfToken, $appVersion, $isDev
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="state-page">
  <span class="state-page__icon" aria-hidden="true"><?= $icon('building') ?></span>
  <span class="status status--pending">No active estate</span>

  <h1>Your account is not linked to an estate yet</h1>
  <p>
    Sign-in worked, but there is no active estate membership attached to this phone
    number. Your estate administrator links residents to a household — once they
    add you, sign in again and your estate will appear.
  </p>

  <div class="state-page__actions">
    <a class="btn btn--primary btn--lg btn--block" href="<?= e('/app') ?>">Try again</a>
    <form method="post" action="<?= e('/logout') ?>" data-logout>
      <?= csrf_field() ?>
      <button type="submit" class="btn btn--outline btn--lg btn--block">
        <?= $icon('logout', 'ico ico--sm') ?>
        Sign out
      </button>
    </form>
  </div>

  <p class="text-sm muted">
    Need help? Contact your estate administrator or
    <a href="mailto:<?= e((string) config('app.support_email', 'support@mtaapass.com')) ?>"><?= e((string) config('app.support_email', 'support@mtaapass.com')) ?></a>.
  </p>
</div>
