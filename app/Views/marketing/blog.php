<?php

declare(strict_types=1);

/**
 * Public Blog index (guest layout): newest-first post cards, breadcrumbs,
 * Organization microdata and a contact/sign-in CTA.
 *
 * Falls back to an honest empty state when no posts are published. All dynamic
 * text is escaped with e(); structured data is emitted as schema.org microdata
 * (the CSP allows no inline <script>/JSON-LD).
 *
 * Variables (defensive): $posts (array<int, array<string,mixed>>),
 * $schemaBase (schema.org origin), $siteName.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$posts = isset($posts) && is_array($posts) ? array_values(array_filter($posts, 'is_array')) : [];
$schemaBase = isset($schemaBase) && is_string($schemaBase) ? rtrim($schemaBase, '/') : '';
$siteName = isset($siteName) && is_string($siteName) && trim($siteName) !== ''
    ? trim($siteName)
    : (string) config('app.name', 'MtaaPass');
$supportEmail = (string) config('app.support_email', 'hello@dashpass.africa');
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$dateLabel = static function (string $date) use ($timezone): string {
    return $date === '' ? '' : format_datetime($date, $timezone, 'j F Y');
};
?>
<div class="about-page stack stack--loose">
  <nav aria-label="Breadcrumb">
    <ol class="cluster" itemscope itemtype="<?= e($schemaBase) ?>/BreadcrumbList">
      <li itemprop="itemListElement" itemscope itemtype="<?= e($schemaBase) ?>/ListItem">
        <a itemprop="item" href="<?= e('/') ?>"><span itemprop="name">Home</span></a>
        <meta itemprop="position" content="1">
      </li>
      <li aria-hidden="true">/</li>
      <li itemprop="itemListElement" itemscope itemtype="<?= e($schemaBase) ?>/ListItem">
        <span itemprop="name">Blog</span>
        <meta itemprop="position" content="2">
      </li>
    </ol>
  </nav>

  <header class="stack stack--tight">
    <p class="landing-hero__eyebrow"><?= $icon('campaign', 'ico ico--sm') ?> INSIGHTS &amp; UPDATES</p>
    <h1>Insights &amp; updates</h1>
    <p class="page-lede">
      Practical guidance on estate access, visitor passes and payments from the DashPASS team.
    </p>
  </header>

  <?php if ($posts === []): ?>
    <section class="card stack" aria-labelledby="blog-empty-title">
      <span class="icon-tile icon-tile--info" aria-hidden="true"><?= $icon('list') ?></span>
      <h2 class="card__title" id="blog-empty-title">No posts published yet</h2>
      <p class="muted">
        No posts published yet, check back soon. In the meantime, get in touch and we will let you
        know when the first DashPASS insights go live.
      </p>
      <div class="cluster">
        <a class="btn btn--outline" href="<?= e('/help') ?>">Contact us</a>
        <a class="btn btn--ghost" href="<?= e('/about') ?>">About DashPASS</a>
      </div>
    </section>
  <?php else: ?>
    <section class="stack" aria-labelledby="blog-posts-title">
      <h2 id="blog-posts-title">Latest posts</h2>

      <?php foreach ($posts as $post): ?>
        <?php
        $slug = trim((string) ($post['slug'] ?? ''));
        $title = trim((string) ($post['title'] ?? ''));
        $category = trim((string) ($post['category'] ?? ''));
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $publishedAt = trim((string) ($post['published_at'] ?? ''));
        $readingMinutes = max(1, (int) ($post['reading_minutes'] ?? 1));
        ?>
        <article class="card stack--tight" itemscope itemtype="<?= e($schemaBase) ?>/BlogPosting">
          <p class="muted">
            <?php if ($category !== ''): ?><span><?= e($category) ?></span> · <?php endif; ?>
            <?php if ($publishedAt !== ''): ?>
              <time datetime="<?= e($publishedAt) ?>" itemprop="datePublished"><?= e($dateLabel($publishedAt)) ?></time> ·
            <?php endif; ?>
            <?= e((string) $readingMinutes) ?> min read
          </p>
          <h3 class="card__title">
            <a href="<?= e('/blog/' . rawurlencode($slug)) ?>" itemprop="url">
              <span itemprop="headline"><?= e($title) ?></span>
            </a>
          </h3>
          <?php if ($excerpt !== ''): ?>
            <p class="muted" itemprop="description"><?= e($excerpt) ?></p>
          <?php endif; ?>
          <p>
            <a class="btn btn--outline btn--sm" href="<?= e('/blog/' . rawurlencode($slug)) ?>">Read post</a>
          </p>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="card stack" itemscope itemtype="<?= e($schemaBase) ?>/Organization" aria-labelledby="blog-publisher-title">
      <h2 class="card__title" id="blog-publisher-title">Published by <span itemprop="name"><?= e($siteName) ?></span></h2>
      <p class="muted">
        <span itemprop="description">DashPASS is the Pass &amp; Pay management system for estates.</span>
      </p>
      <p class="muted">
        <a itemprop="url" href="<?= e('/about') ?>">About DashPASS</a> ·
        <a href="<?= e('/help') ?>">Contact the team</a>
      </p>
      <p class="muted">Email <a itemprop="email" href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
    </section>
  <?php endif; ?>

  <section class="card stack" aria-labelledby="blog-cta-title">
    <h2 class="card__title" id="blog-cta-title">Start with DashPASS</h2>
    <p class="muted">Sign in to check in, or talk to us about bringing DashPASS to your estate.</p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e('/login') ?>">Check in</a>
      <a class="btn btn--outline btn--lg" href="<?= e('/help') ?>">Contact us</a>
      <a class="btn btn--ghost btn--lg" href="<?= e('/about') ?>">About DashPASS</a>
    </div>
  </section>
</div>
