<?php

declare(strict_types=1);

/**
 * Public legal / privacy links for the guest footer (landing page included).
 *
 * Deliberately just the three published notices so the slim landing footer stays
 * uncluttered. The opt in/opt out choices and the delete-my-data page remain one
 * tap away from the Privacy Policy page (and from the legal page tab bar), where
 * they belong next to the explanation of the rights they exercise.
 */
?>
<nav class="legal-links" aria-label="Legal">
  <a class="legal-links__item" href="<?= e('/privacy') ?>">Privacy Policy</a>
  <span class="legal-links__sep" aria-hidden="true">·</span>
  <a class="legal-links__item" href="<?= e('/terms') ?>">Terms &amp; Conditions</a>
  <span class="legal-links__sep" aria-hidden="true">·</span>
  <a class="legal-links__item" href="<?= e('/data-policy') ?>">Data Policy</a>
</nav>
