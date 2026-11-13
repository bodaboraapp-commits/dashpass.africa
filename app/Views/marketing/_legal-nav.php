<?php

declare(strict_types=1);

/**
 * Shared tab navigation for the public legal + privacy pages.
 *
 * Variables: $legalActive (privacy|terms|data-policy|choices|deletion).
 * Rendered inside every legal page so a visitor can move between the notice,
 * the contract and the privacy-choice tools without going back to the footer.
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
<nav class="legal-nav" aria-label="Legal and privacy pages">
  <?php foreach ($legalLinks as $legalLink): ?>
    <?php $isCurrent = $legalActive === $legalLink['key']; ?>
    <a class="legal-nav__link<?= $isCurrent ? ' legal-nav__link--current' : '' ?>"
       href="<?= e($legalLink['href']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= e($legalLink['label']) ?></a>
  <?php endforeach; ?>
</nav>
