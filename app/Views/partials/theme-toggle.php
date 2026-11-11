<?php

declare(strict_types=1);

/**
 * Theme toggle button. Hidden until theme.js/app.js mark that JavaScript is
 * available, so it is never a dead control.
 *
 * Optional: $class (extra classes)
 */

$class = isset($class) && is_string($class) && trim($class) !== '' ? ' ' . trim($class) : '';
$icon = static fn (string $name, string $extra): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<button type="button" class="btn btn--icon btn--ghost theme-toggle<?= e($class) ?>" data-theme-toggle hidden aria-label="Switch colour theme" title="Switch colour theme">
  <?= $icon('sun', 'ico theme-toggle__sun') ?>
  <?= $icon('moon', 'ico theme-toggle__moon') ?>
</button>
