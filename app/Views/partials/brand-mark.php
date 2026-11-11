<?php

declare(strict_types=1);

/**
 * MtaaPass brand mark: a forest gateway with the sunshine pass light.
 * Matches scripts/generate-icons.php so app and icon share one identity.
 *
 * Optional: $class (extra classes on the mark wrapper)
 */

$class = isset($class) && is_string($class) && trim($class) !== '' ? ' ' . trim($class) : '';
?>
<span class="brand__mark<?= e($class) ?>" aria-hidden="true">
  <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="M4 20.5V10a8 8 0 0 1 16 0v10.5"></path>
    <path d="M3 20.5h18"></path>
    <circle cx="12" cy="13.5" r="1.8"></circle>
  </svg>
</span>
