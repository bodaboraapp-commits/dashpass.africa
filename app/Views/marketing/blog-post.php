<?php

declare(strict_types=1);

/**
 * Public blog post (guest layout).
 *
 * One post with breadcrumbs, BlogPosting microdata (no inline JSON-LD, the CSP
 * has script-src 'self'), article sections, an FAQPage microdata block, related
 * posts and internal CTAs. Every content string is escaped with e(); link hrefs
 * are only rendered when they are internal site paths.
 *
 * Variables (defensive): $post (array<string,mixed>), $relatedPosts
 * (array<int, array<string,mixed>>), $schemaBase, $siteName.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$post = isset($post) && is_array($post) ? $post : [];
$relatedPosts = isset($relatedPosts) && is_array($relatedPosts)
    ? array_values(array_filter($relatedPosts, 'is_array'))
    : [];
$schemaBase = isset($schemaBase) && is_string($schemaBase) ? rtrim($schemaBase, '/') : '';
$siteName = isset($siteName) && is_string($siteName) && trim($siteName) !== ''
    ? trim($siteName)
    : (string) config('app.name', 'MtaaPass');
$supportEmail = (string) config('app.support_email', 'hello@dashpass.africa');
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$title = trim((string) ($post['title'] ?? 'Blog post'));
$category = trim((string) ($post['category'] ?? ''));
$description = trim((string) ($post['description'] ?? ''));
$author = trim((string) ($post['author'] ?? 'DashPASS Team'));
$publishedAt = trim((string) ($post['published_at'] ?? ''));
$updatedAt = trim((string) ($post['updated_at'] ?? ''));
$readingMinutes = max(1, (int) ($post['reading_minutes'] ?? 1));
$sections = isset($post['sections']) && is_array($post['sections'])
    ? array_values(array_filter($post['sections'], 'is_array'))
    : [];
$faqs = isset($post['faq']) && is_array($post['faq']) ? array_values(array_filter($post['faq'], 'is_array')) : [];
$relatedLinks = isset($post['related_links']) && is_array($post['related_links'])
    ? array_values(array_filter($post['related_links'], 'is_array'))
    : [];
$cta = isset($post['cta']) && is_array($post['cta']) ? $post['cta'] : [];
$image = isset($post['image']) && is_array($post['image']) ? $post['image'] : [];

$dateLabel = static function (string $date) use ($timezone): string {
    return $date === '' ? '' : format_datetime($date, $timezone, 'j F Y');
};

// Only same-site paths are rendered as links (content is data, not markup).
$isInternal = static function (string $href): bool {
    return $href !== '' && str_starts_with($href, '/') && !str_starts_with($href, '//');
};

$ctaLabel = trim((string) ($cta['label'] ?? 'Check in'));
$ctaHref = trim((string) ($cta['href'] ?? '/login'));

if (!$isInternal($ctaHref)) {
    $ctaLabel = 'Check in';
    $ctaHref = '/login';
}
?>
<article class="about-page stack stack--loose"
         itemscope itemtype="<?= e($schemaBase) ?>/BlogPosting">
  <nav aria-label="Breadcrumb">
    <ol class="cluster" itemscope itemtype="<?= e($schemaBase) ?>/BreadcrumbList">
      <li itemprop="itemListElement" itemscope itemtype="<?= e($schemaBase) ?>/ListItem">
        <a itemprop="item" href="<?= e('/') ?>"><span itemprop="name">Home</span></a>
        <meta itemprop="position" content="1">
      </li>
      <li aria-hidden="true">/</li>
      <li itemprop="itemListElement" itemscope itemtype="<?= e($schemaBase) ?>/ListItem">
        <a itemprop="item" href="<?= e('/blog') ?>"><span itemprop="name">Blog</span></a>
        <meta itemprop="position" content="2">
      </li>
      <li aria-hidden="true">/</li>
      <li itemprop="itemListElement" itemscope itemtype="<?= e($schemaBase) ?>/ListItem">
        <span itemprop="name"><?= e($title) ?></span>
        <meta itemprop="position" content="3">
      </li>
    </ol>
  </nav>

  <header class="stack stack--tight">
    <p class="landing-hero__eyebrow">
      <?= $icon('campaign', 'ico ico--sm') ?>
      <?php if ($category !== ''): ?><?= e($category) ?> · <?php endif; ?>
      <?= e((string) $readingMinutes) ?> min read
    </p>
    <h1 itemprop="headline"><?= e($title) ?></h1>
    <p class="muted">
      <?php if ($publishedAt !== ''): ?>
        Published <time datetime="<?= e($publishedAt) ?>" itemprop="datePublished"><?= e($dateLabel($publishedAt)) ?></time>
      <?php endif; ?>
      <?php if ($updatedAt !== '' && $updatedAt !== $publishedAt): ?>
        · Updated <time datetime="<?= e($updatedAt) ?>" itemprop="dateModified"><?= e($dateLabel($updatedAt)) ?></time>
      <?php endif; ?>
      · By
      <span itemprop="author" itemscope itemtype="<?= e($schemaBase) ?>/Person">
        <span itemprop="name"><?= e($author) ?></span>
      </span>
      · <span itemprop="publisher" itemscope itemtype="<?= e($schemaBase) ?>/Organization"><span itemprop="name"><?= e($siteName) ?></span></span>
    </p>
    <?php if ($description !== ''): ?>
      <p class="page-lede" itemprop="description"><?= e($description) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($image !== [] && trim((string) ($image['url'] ?? '')) !== ''): ?>
    <figure class="blog-post__figure">
      <img class="blog-post__image"
           src="<?= e((string) ($image['url'] ?? '')) ?>"
           alt="<?= e((string) ($image['alt'] ?? '')) ?>"
           width="<?= e((string) ($image['width'] ?? 1280)) ?>"
           height="<?= e((string) ($image['height'] ?? 720)) ?>"
           decoding="async" itemprop="image">
      <?php if (trim((string) ($image['credit'] ?? '')) !== ''): ?>
        <figcaption class="blog-post__credit"><?= e((string) $image['credit']) ?></figcaption>
      <?php endif; ?>
    </figure>
  <?php endif; ?>

  <?php foreach ($sections as $sectionIndex => $section): ?>
    <?php
    $heading = trim((string) ($section['heading'] ?? ''));
    $paragraphs = isset($section['paragraphs']) && is_array($section['paragraphs'])
        ? array_values(array_filter($section['paragraphs'], 'is_string'))
        : [];
    $bullets = isset($section['bullets']) && is_array($section['bullets'])
        ? array_values(array_filter($section['bullets'], 'is_string'))
        : [];
    $sectionId = 'post-section-' . (string) ((int) $sectionIndex + 1);
    ?>
    <section class="stack" aria-labelledby="<?= e($sectionId) ?>">
      <?php if ($heading !== ''): ?>
        <h2 id="<?= e($sectionId) ?>"><?= e($heading) ?></h2>
      <?php endif; ?>
      <?php foreach ($paragraphs as $paragraph): ?>
        <p><?= e($paragraph) ?></p>
      <?php endforeach; ?>
      <?php if ($bullets !== []): ?>
        <ul>
          <?php foreach ($bullets as $bullet): ?>
            <li><?= e($bullet) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($faqs !== []): ?>
    <section class="stack" itemscope itemtype="<?= e($schemaBase) ?>/FAQPage" aria-labelledby="post-faq-title">
      <h2 id="post-faq-title">Frequently asked questions</h2>
      <?php foreach ($faqs as $faq): ?>
        <?php
        $question = trim((string) ($faq['q'] ?? ''));
        $answer = trim((string) ($faq['a'] ?? ''));
        ?>
        <?php if ($question !== '' && $answer !== ''): ?>
          <details class="disclosure" itemscope itemtype="<?= e($schemaBase) ?>/Question">
            <summary itemprop="name"><?= e($question) ?></summary>
            <div class="disclosure__body" itemprop="acceptedAnswer" itemscope itemtype="<?= e($schemaBase) ?>/Answer">
              <p class="muted" itemprop="text"><?= e($answer) ?></p>
            </div>
          </details>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="stack" aria-labelledby="post-related-title">
    <h2 id="post-related-title">Related reading</h2>
    <ul>
      <?php foreach ($relatedPosts as $related): ?>
        <?php
        $relatedSlug = trim((string) ($related['slug'] ?? ''));
        $relatedTitle = trim((string) ($related['title'] ?? ''));
        ?>
        <?php if ($relatedSlug !== '' && $relatedTitle !== ''): ?>
          <li><a href="<?= e('/blog/' . rawurlencode($relatedSlug)) ?>"><?= e($relatedTitle) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>

    <?php
    $renderedLinks = [];

    foreach ($relatedLinks as $link) {
        $label = trim((string) ($link['label'] ?? ''));
        $href = trim((string) ($link['href'] ?? ''));

        if ($label !== '' && $isInternal($href)) {
            $renderedLinks[] = ['label' => $label, 'href' => $href];
        }
    }
    ?>
    <?php if ($renderedLinks !== []): ?>
      <h3>Useful links</h3>
      <ul>
        <?php foreach ($renderedLinks as $link): ?>
          <li><a href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card stack" aria-labelledby="post-cta-title">
    <h2 class="card__title" id="post-cta-title">Ready to try DashPASS?</h2>
    <p class="muted">Sign in to check in, or talk to us about bringing DashPASS to your estate.</p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e($ctaHref) ?>"><?= e($ctaLabel) ?></a>
      <a class="btn btn--outline btn--lg" href="<?= e('/about') ?>">About DashPASS</a>
      <a class="btn btn--ghost btn--lg" href="<?= e('/help') ?>">Contact us</a>
    </div>
    <p class="muted">Email <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
  </section>
</article>
