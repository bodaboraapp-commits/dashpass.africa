<?php

declare(strict_types=1);

/**
 * Standalone error page rendered directly by App\Core\ErrorHandler through
 * View::capture() — it is a complete HTML document and must not assume a layout.
 *
 * Variables: $status, $code, $message, $correlationId, $debug, $debugMessage
 */

$status = isset($status) ? (int) $status : 500;
if ($status < 400 || $status > 599) {
    $status = 500;
}
$code = isset($code) && is_string($code) && trim($code) !== '' ? trim($code) : 'INTERNAL_ERROR';
$message = isset($message) && is_string($message) && trim($message) !== ''
    ? trim($message)
    : 'Something went wrong on our side. Please try again.';
$correlationId = isset($correlationId) && is_string($correlationId) ? trim($correlationId) : '';
$debug = (bool) ($debug ?? false);
$debugMessage = $debugMessage ?? null;
$appName = (string) config('app.name', 'MtaaPass');
$appVersion = (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);

$headings = [
    400 => 'That request was not valid',
    401 => 'Please sign in',
    403 => 'You do not have access to this',
    404 => 'We could not find that page',
    405 => 'That action is not allowed',
    409 => 'That could not be completed',
    419 => 'Your session has expired',
    422 => 'Please check the details',
    429 => 'Slow down a moment',
    500 => 'Something went wrong',
    503 => $appName . ' is unavailable',
];

$hints = [
    400 => 'Check the details and try again.',
    401 => 'Sign in again to continue to your estate.',
    403 => 'If you believe you should have access, contact your estate administrator.',
    404 => 'The page may have moved, or the link may be out of date.',
    405 => 'Return to your dashboard and try again from there.',
    409 => 'Refresh the page and try again.',
    419 => 'Your session security token expired. Sign in again to continue.',
    422 => 'One or more values were not accepted. Go back and check them.',
    429 => 'Too many attempts. Please wait a moment before trying again.',
    500 => 'The issue has been logged. Support can use the reference below to trace it.',
    503 => 'Please try again in a moment.',
];

$heading = $headings[$status] ?? 'Something went wrong';
$hint = $hints[$status] ?? 'Please try again. If it keeps happening, contact your estate administrator.';

$debugText = null;
if ($debug && $debugMessage !== null) {
    if (is_array($debugMessage)) {
        $encoded = json_encode($debugMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $debugText = $encoded === false ? 'Debug details could not be encoded.' : $encoded;
    } else {
        $debugText = (string) $debugMessage;
    }
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
$pageTitle = $status === 404 ? 'Page not found' : 'Error ' . $status;
?>
<!DOCTYPE html>
<html lang="en" data-theme-default="dark">
<head>
<?= \App\Core\View::capture('partials/head', [
    'title' => $pageTitle,
    'appVersion' => $appVersion,
    'appResident' => true,
]) ?>
<meta name="robots" content="noindex">
</head>
<body class="error-body">
  <a class="skip-link" href="#main">Skip to content</a>

  <main id="main" class="error-page">
    <div class="error-card">
      <a class="error-brand" href="<?= e('/') ?>">
        <?= \App\Core\View::capture('partials/brand-mark') ?>
        <span><?= e($appName) ?></span>
      </a>

      <div class="cluster" aria-hidden="true">
        <span class="error-code"><?= e((string) $status) ?></span>
        <span class="chip chip--outline mono"><?= e($code) ?></span>
      </div>

      <h1><?= e($heading) ?></h1>
      <p><?= e($message) ?></p>
      <p class="text-sm muted"><?= e($hint) ?></p>

      <?php if ($correlationId !== ''): ?>
        <div class="error-meta">
          <div class="cluster cluster--between">
            <p class="error-meta__label">Reference for support</p>
            <button type="button" class="btn btn--icon btn--ghost" data-copy="<?= e($correlationId) ?>" aria-label="Copy reference" title="Copy reference">
              <?= $icon('copy', 'ico ico--sm') ?>
            </button>
          </div>
          <p class="error-meta__value"><?= e($correlationId) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($debugText !== null): ?>
        <div class="error-debug">
          <p class="error-meta__label">Debug detail (development only)</p>
          <pre><?= e($debugText) ?></pre>
        </div>
      <?php endif; ?>

      <div class="error-actions">
        <a class="btn btn--primary btn--lg" href="<?= e('/') ?>">
          <?= $icon('home', 'ico ico--sm') ?>
          Back to <?= e($appName) ?>
        </a>
        <a class="btn btn--outline btn--lg" href="<?= e('/login') ?>">
          <?= $icon('lock', 'ico ico--sm') ?>
          Sign in
        </a>
      </div>
    </div>
  </main>

  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
