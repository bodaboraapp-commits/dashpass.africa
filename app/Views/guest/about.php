<?php

declare(strict_types=1);

/**
 * Guest /about page: full About/welcome content, separate from the landing.
 * Landing links here; the landing itself carries only the hero.
 */
?>
<div class="about-page">
  <a class="link-quiet about-back" href="<?= e('/') ?>">
    <?= \App\Core\View::capture('partials/icon', ['name' => 'chevron-left', 'class' => 'ico']) ?>
    Back to home
  </a>

  <?php include __DIR__ . '/../auth/welcome.php'; ?>
</div>
