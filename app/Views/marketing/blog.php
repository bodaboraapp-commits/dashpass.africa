<?php

declare(strict_types=1);

/**
 * Public Blog page (guest layout).
 *
 * DashPASS has no published posts yet, so the page renders an honest empty state
 * (no fabricated articles) plus a contact CTA.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$supportEmail = (string) config('app.support_email', 'hello@dashpass.africa');
?>
<div class="about-page stack stack--loose">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="stack stack--tight">
    <p class="landing-hero__eyebrow"><?= $icon('campaign', 'ico ico--sm') ?> INSIGHTS &amp; UPDATES</p>
    <h1>Insights &amp; updates</h1>
    <p class="page-lede">
      Product notes, estate access and payment best practice from the DashPASS team.
    </p>
  </header>

  <section class="card stack" aria-labelledby="blog-empty-title">
    <span class="icon-tile icon-tile--info" aria-hidden="true"><?= $icon('list') ?></span>
    <h2 class="card__title" id="blog-empty-title">No posts published yet</h2>
    <p class="muted">
      No posts published yet — check back soon. In the meantime, get in touch and we will let you
      know when the first DashPASS insights go live.
    </p>
    <div class="cluster">
      <a class="btn btn--outline" href="<?= e('/help') ?>">Contact us</a>
      <a class="btn btn--ghost" href="<?= e('/about') ?>">About DashPASS</a>
    </div>
  </section>

  <p class="muted">Email <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
</div>
