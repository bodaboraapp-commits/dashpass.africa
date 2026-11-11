<?php

declare(strict_types=1);

/**
 * Inc-13 billing automation CLI (P1).
 *
 * One-shot cron tick; idempotent; dry-run performs zero DB writes/audits.
 * Scope P1: subscription generation (`all|subscriptions|schedule`); `send`,
 * `deliveries`, `reminders` are accepted no-ops until their slices land;
 * `utility` is reserved for P3. Disabled feature flag is a safe no-op.
 *
 * Usage: php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD]
 *        [--scope=all|subscriptions|schedule|send|deliveries|reminders]
 *        [--estate=<uuid>] [--json] [--limit=N] [--max-catchup=N] [--help]
 *
 * Exit codes: 0 success/no-op/disabled/lock-held; 1 fatal or partial failures;
 * 2 invalid arguments/date/estate/scope.
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\Uuid;
use App\Modules\Billing\Automation\BillingAutomationService;

$root = dirname(__DIR__);
$app = require $root . '/app/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$options = [
    'dry_run' => false,
    'date' => null,
    'scope' => 'all',
    'estate_uuid' => null,
    'json' => false,
    'limit' => null,
    'max_catchup' => null,
];
$errors = [];

for ($i = 1; $i < count($argv); $i++) {
    $arg = (string) $argv[$i];

    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD] "
            . "[--scope=all|subscriptions|schedule|send|deliveries|reminders] [--all] [--schedule] [--send] "
            . "[--estate=<uuid>] [--json] [--limit=N] [--max-catchup=N]\n");

        exit(0);
    }

    if ($arg === '--dry-run') {
        $options['dry_run'] = true;

        continue;
    }

    if ($arg === '--json') {
        $options['json'] = true;

        continue;
    }

    // Short scope aliases used by cron/docs: --all, --schedule, --send.
    if (in_array($arg, ['--all', '--schedule', '--send'], true)) {
        $options['scope'] = substr($arg, 2);

        continue;
    }

    if (str_starts_with($arg, '--date=')) {
        $options['date'] = substr($arg, 7);

        continue;
    }

    if (str_starts_with($arg, '--scope=')) {
        $options['scope'] = substr($arg, 8);

        continue;
    }

    if (str_starts_with($arg, '--estate=')) {
        $options['estate_uuid'] = substr($arg, 9);

        continue;
    }

    if (str_starts_with($arg, '--limit=')) {
        $options['limit'] = (int) substr($arg, 8);

        continue;
    }

    if (str_starts_with($arg, '--max-catchup=')) {
        $options['max_catchup'] = (int) substr($arg, 14);

        continue;
    }

    $errors[] = 'Unknown option: ' . $arg;
}

$allowedScopes = ['all', 'subscriptions', 'schedule', 'send', 'deliveries', 'reminders', 'utility'];

if (!in_array((string) $options['scope'], $allowedScopes, true)) {
    $errors[] = 'Invalid --scope.';
}

if (is_int($options['limit']) && $options['limit'] < 1) {
    $errors[] = '--limit must be at least 1.';
}

if (is_int($options['max_catchup']) && ($options['max_catchup'] < 1 || $options['max_catchup'] > 24)) {
    $errors[] = '--max-catchup must be between 1 and 24.';
}

if ((string) $options['scope'] === 'utility') {
    $errors[] = 'Utility generation is not available until the P3 slice.';
}

$date = Clock::now();

if ($options['date'] !== null && $options['date'] !== '') {
    $raw = (string) $options['date'];

    try {
        $date = (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        $errors[] = 'Invalid --date; use YYYY-MM-DD (UTC).';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1 && preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw) !== 1) {
        $errors[] = 'Invalid --date; use YYYY-MM-DD (UTC).';
    }
}

$db = Database::instance();

if ($options['estate_uuid'] !== null && $options['estate_uuid'] !== '') {
    if (!Uuid::isValid((string) $options['estate_uuid'])) {
        $errors[] = 'Invalid --estate uuid.';
    } elseif ($db->selectOne('SELECT id FROM estates WHERE uuid = :uuid LIMIT 1', ['uuid' => (string) $options['estate_uuid']]) === null) {
        $errors[] = 'Unknown --estate uuid.';
    }
}

if ($errors !== []) {
    $payload = ['success' => false, 'status' => 'invalid', 'errors' => $errors];

    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        foreach ($errors as $error) {
            fwrite(STDERR, $error . PHP_EOL);
        }
    }

    exit(2);
}

$lockPath = $root . '/' . ltrim((string) Config::get('billing.automation.lock_path', 'storage/temporary/billing-automate.lock'), '/\\');
$lockDir = dirname($lockPath);

if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lock = @fopen($lockPath, 'c');

if ($lock === false) {
    fwrite(STDERR, 'Could not open automation lock file.' . PHP_EOL);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $payload = [
        'success' => true,
        'dry_run' => (bool) $options['dry_run'],
        'date' => $date->format('Y-m-d'),
        'scope' => (string) $options['scope'],
        'status' => 'skipped_locked',
        'counts' => ['scanned' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0],
        'skips' => [],
        'lock' => 'held',
    ];

    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Billing automation: skipped (another run holds the lock).' . PHP_EOL);
    }

    fclose($lock);
    exit(0);
}

try {
    $context = new Context(
        \App\Core\Request::create('GET', '/billing-automate', [], [], '', '127.0.0.1', 'MtaaPass-BillingAutomation/1.0'),
        'cli-' . Uuid::v4()
    );

    $result = BillingAutomationService::make($context)->run([
        'dry_run' => (bool) $options['dry_run'],
        'date' => $date,
        'scope' => (string) $options['scope'],
        'estate_uuid' => $options['estate_uuid'] !== '' ? $options['estate_uuid'] : null,
        'limit' => $options['limit'],
        'max_catchup' => $options['max_catchup'],
    ]);

    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        fwrite(STDOUT, sprintf(
            'Billing automation (%s): status=%s scanned=%d generated=%d skipped=%d failed=%d%s',
            $options['dry_run'] ? 'dry-run' : 'apply',
            (string) ($result['status'] ?? 'unknown'),
            (int) ($counts['scanned'] ?? 0),
            (int) ($counts['generated'] ?? 0),
            (int) ($counts['skipped'] ?? 0),
            (int) ($counts['failed'] ?? 0),
            ($result['lock'] ?? '') === 'held' ? ' lock=held' : ''
        ) . PHP_EOL);
    }

    $success = in_array((string) ($result['status'] ?? ''), ['success', 'disabled', 'skipped_locked'], true);
    $fatal = (string) ($result['status'] ?? '') === 'unsupported';
    exit($success ? 0 : ($fatal ? 1 : 1));
} catch (Throwable $exception) {
    $payload = [
        'success' => false,
        'status' => 'failed',
        'error' => 'Billing automation failed.',
        'detail' => mb_substr($exception->getMessage(), 0, 300),
    ];

    if ($options['json']) {
        fwrite(STDOUT, (string) json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDERR, 'Billing automation failed: ' . $payload['detail'] . PHP_EOL);
    }

    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
