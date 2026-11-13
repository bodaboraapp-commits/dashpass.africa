<?php

declare(strict_types=1);

/**
 * Navigation for the public legal + privacy pages (legal sidebar).
 *
 * Variables: $legalActive (privacy|terms|data-policy|choices|deletion).
 * Rendered inside the sticky legal sidebar of every legal page so a visitor can
 * move between the notice, the contract and the privacy-choice tools without
 * going back to the footer. Blog articles use the same doc-nav treatment for
 * their own article navigation.
 */

$legalActive = isset($legalActive) && is_string($legalActive) ? $legalActive : '';

$legalLinks = [
    ['key' => 'privacy', 'href' => '/privacy', 'label' => 'Privacy Policy'],
    ['key' => 'terms', 'href' => '/terms', 'label' => 'Terms & Conditions'],
    ['key' => 'data-policy', 'href' => '/data-policy', 'label' => 'Data Policy'],
    ['key' => 'choices', 'href' => '/privacy/choices', 'label' => 'Privacy choices'],
    ['key' => 'deletion', 'href' => '/data-deletion', 'label' => 'Delete my data'],
];
?>
<nav class="doc-nav" aria-label="Legal and privacy pages">
  <?php foreach ($legalLinks as $legalLink): ?>
    <?php $isCurrent = $legalActive === $legalLink['key']; ?>
    <a class="doc-nav__link<?= $isCurrent ? ' doc-nav__link--current' : '' ?>"
       href="<?= e($legalLink['href']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= e($legalLink['label']) ?></a>
  <?php endforeach; ?>
</nav>
