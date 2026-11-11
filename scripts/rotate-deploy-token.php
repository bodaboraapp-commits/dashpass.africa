<?php

declare(strict_types=1);

/**
 * Rotate the DEPLOY_SYNC_TOKEN in the server `.env` (CLI only).
 *
 * Usage (run from the application root on the server, e.g. cPanel Terminal):
 *
 *   php scripts/rotate-deploy-token.php --from-file=.deploy-token-new
 *   php scripts/rotate-deploy-token.php --generate
 *
 * --from-file : reads a 48-char hex token from the given file (upload the new
 *               token with File Manager, then delete it), writes it into `.env`,
 *               keeps a timestamped backup, and removes the handoff file.
 * --generate  : generates a new 48-char hex token itself, writes it into `.env`,
 *               and prints it once so it can be copied into local secrets.
 *
 * Safety: refuses to run from a web request, validates length/charset, only
 * rewrites the DEPLOY_SYNC_TOKEN line, preserves the rest of `.env`, sets 0600
 * permissions when possible, and never prints the full token unless generated.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$envPath = $root . '/.env';

if (!is_file($envPath)) {
    fwrite(STDERR, "No .env found at: {$envPath}\n");
    exit(1);
}

$args = $argv;
array_shift($args);
$mode = 'generate';
$file = null;

foreach ($args as $arg) {
    if ($arg === '--generate') {
        $mode = 'generate';
    } elseif (str_starts_with($arg, '--from-file=')) {
        $mode = 'file';
        $file = substr($arg, strlen('--from-file='));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage:\n  php scripts/rotate-deploy-token.php --from-file=<path>\n  php scripts/rotate-deploy-token.php --generate\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$token = '';

if ($mode === 'file') {
    if ($file === null || $file === '') {
        fwrite(STDERR, "--from-file requires a path.\n");
        exit(2);
    }

    // Accept an absolute path or a path relative to the application root.
    $candidate = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $file) === 1
        ? $file
        : $root . '/' . ltrim($file, '/\\');

    if (!is_file($candidate)) {
        fwrite(STDERR, "Token file not found: {$candidate}\n");
        exit(2);
    }

    $token = trim((string) file_get_contents($candidate));
    @unlink($candidate);
} else {
    $token = bin2hex(random_bytes(24));
}

if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
    fwrite(STDERR, "Refusing: token must be exactly 48 lowercase hex characters.\n");
    exit(2);
}

$contents = (string) file_get_contents($envPath);
$contents = str_replace("\r\n", "\n", $contents);
$stamp = date('Ymd-His');
$backup = $envPath . '.bak-' . $stamp;

if (!copy($envPath, $backup)) {
    fwrite(STDERR, "Could not create backup: {$backup}\n");
    exit(1);
}

if (preg_match('/^DEPLOY_SYNC_TOKEN=/m', $contents) === 1) {
    $contents = preg_replace('/^DEPLOY_SYNC_TOKEN=.*$/m', 'DEPLOY_SYNC_TOKEN=' . $token, $contents, 1) ?? $contents;
} else {
    $contents = rtrim($contents, "\n") . "\n\n# Deploy DB sync endpoint token (rotated {$stamp})\nDEPLOY_SYNC_TOKEN={$token}\n";
}

$tmp = $envPath . '.tmp-' . $stamp;

if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write temporary .env.\n");
    exit(1);
}

@chmod($tmp, 0600);

if (!rename($tmp, $envPath)) {
    @unlink($tmp);
    fwrite(STDERR, "Could not replace .env (permissions?). Backup kept at {$backup}\n");
    exit(1);
}

@chmod($envPath, 0600);

fwrite(STDOUT, "DEPLOY_SYNC_TOKEN rotated for {$envPath}\n");
fwrite(STDOUT, "Backup: {$backup}\n");
fwrite(STDOUT, "Next: verify the endpoint with the new token (?action=status) and confirm the old token now returns 403.\n");

if ($mode === 'generate') {
    fwrite(STDOUT, "NEW TOKEN (copy this into your local gitignored secrets now; it is shown once):\n{$token}\n");
} else {
    fwrite(STDOUT, "Token source file removed. Remember to update local secrets if they use a different value.\n");
}
