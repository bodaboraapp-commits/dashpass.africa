<?php

declare(strict_types=1);

/**
 * Sticky sidebar for the public legal + privacy pages.
 *
 * Contains the legal-page switcher, an on-this-page table of contents (native
 * anchors, no JavaScript) and one small "need help" card. On narrow screens the
 * stylesheet turns it into a wrapping chip bar plus a two-column contents list
 * above the reading panel.
 *
 * Variables: $legalActive (privacy|terms|data-policy|choices|deletion),
 * $toc (list of ['id' => string, 'label' => string]), $sideTitle, $sideText,
 * $sideHref, $sideLabel, $privacyEmail, $supportEmail, $responseDays.
 */

$legalActive = isset($legalActive) && is_string($legalActive) ? $legalActive : '';
$toc = isset($toc) && is_array($toc) ? $toc : [];
$privacyEmail = isset($privacyEmail) ? (string) $privacyEmail : 'privacy@dashpass.africa';
$supportEmail = isset($supportEmail) ? (string) $supportEmail : $privacyEmail;
$responseDays = isset($responseDays) ? max(1, (int) $responseDays) : 30;
$sideTitle = isset($sideTitle) && is_string($sideTitle) && $sideTitle !== '' ? $sideTitle : 'Need help?';
$sideText = isset($sideText) && is_string($sideText) && $sideText !== ''
    ? $sideText
    : 'Questions about this page or your data? Email us and we will reply within '
        . $responseDays . ' days.';
$sideHref = isset($sideHref) && is_string($sideHref) && $sideHref !== '' ? $sideHref : '/privacy/choices';
$sideLabel = isset($sideLabel) && is_string($sideLabel) && $sideLabel !== '' ? $sideLabel : 'Your privacy choices';
// Privacy/data contact by default; general support only for help-centre links.
$sideEmail = isset($sideEmail) && is_string($sideEmail) && $sideEmail !== ''
    ? $sideEmail
    : (str_starts_with($sideHref, '/help') ? $supportEmail : $privacyEmail);
?>
<aside class="legal-side" aria-label="Legal navigation and page contents">
  <?= \App\Core\View::capture('marketing/_legal-nav', ['legalActive' => $legalActive]) ?>

  <?php if ($toc !== []): ?>
    <nav class="legal-toc" aria-label="On this page">
      <p class="legal-toc__title">On this page</p>
      <ol class="legal-toc__list">
        <?php foreach ($toc as $tocItem): ?>
          <?php
          $tocId = isset($tocItem['id']) && is_scalar($tocItem['id']) ? (string) $tocItem['id'] : '';
          $tocLabel = isset($tocItem['label']) && is_scalar($tocItem['label']) ? (string) $tocItem['label'] : '';

          if ($tocId === '' || $tocLabel === '') {
              continue;
          }
          ?>
          <li><a href="#<?= e($tocId) ?>"><?= e($tocLabel) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>
  <?php endif; ?>

  <div class="legal-side__card">
    <p class="legal-side__title"><?= e($sideTitle) ?></p>
    <p>
      <?= e($sideText) ?>
      <a href="mailto:<?= e($sideEmail) ?>"><?= e($sideEmail) ?></a>.
    </p>
    <a class="btn btn--outline btn--sm btn--block" href="<?= e($sideHref) ?>"><?= e($sideLabel) ?></a>
  </div>
</aside>
