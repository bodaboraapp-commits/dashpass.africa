<?php

declare(strict_types=1);

/**
 * MtaaPass deployment database-sync endpoint (cPanel Git deployments).
 *
 * Purpose: apply database migrations (and optionally seed) on a host where the
 * product owner only has HTTP access (cPanel Git Version Control deployments,
 * no Terminal). It is intentionally separate from the public application router
 * and refuses to do anything without DEPLOY_SYNC_TOKEN.
 *
 * Usage (HTTPS only):
 *   GET/POST /deploy-sync.php?token=<token>&action=status
 *   GET/POST /deploy-sync.php?token=<token>&action=migrate
 *   GET/POST /deploy-sync.php?token=<token>&action=seed
 *   GET/POST /deploy-sync.php?token=<token>&action=install   (migrate + seed)
 *
 * The token can also be sent as the `X-Deploy-Token` header (preferred; keeps
 * the secret out of proxy/access logs).
 *
 * Security rules:
 *  - DEPLOY_SYNC_TOKEN must be configured and non-trivial; otherwise 404.
 *  - Token compared with hash_equals; wrong token answers 403 with a generic body.
 *  - Never returns stack traces, credentials, SQL or file paths.
 *  - Serialised with a file lock; safe to call from a deploy hook or cron.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Migrator;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function syncRespond(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$expected = trim((string) env('DEPLOY_SYNC_TOKEN', ''));
$given = '';

if (isset($_SERVER['HTTP_X_DEPLOY_TOKEN'])) {
    $given = (string) $_SERVER['HTTP_X_DEPLOY_TOKEN'];
} elseif (isset($_GET['token'])) {
    $given = (string) $_GET['token'];
} elseif (isset($_POST['token'])) {
    $given = (string) $_POST['token'];
}

// Not configured => pretend the endpoint does not exist.
if ($expected === '' || strlen($expected) < 24) {
    syncRespond(false, 'Not found.', [], 404);
}

if (!hash_equals($expected, $given)) {
    Logger::security('Deployment sync rejected: bad token', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    syncRespond(false, 'Forbidden.', [], 403);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'status')));

if (!in_array($action, ['status', 'migrate', 'seed', 'install'], true)) {
    syncRespond(false, 'Unsupported action.', ['allowed' => ['status', 'migrate', 'seed', 'install']], 400);
}

$root = dirname(__DIR__);
$migrations = $root . '/database/migrations';
$lockPath = $root . '/storage/temporary/deploy-sync.lock';

if (!is_dir(dirname($lockPath))) {
    @mkdir(dirname($lockPath), 0775, true);
}

$lock = @fopen($lockPath, 'c');
$locked = $lock !== false && @flock($lock, LOCK_EX);

if (!$locked) {
    syncRespond(false, 'Another database sync is already running. Try again shortly.', [], 409);
}

try {
    $db = Database::instance();
    $migrator = new Migrator($db, $migrations);
    $migrator->ensureTable();

    $pending = $migrator->pending();
    $totalFiles = count($migrator->files());
    $applied = $totalFiles - count($pending);

    $data = [
        'environment' => (string) Config::get('app.env'),
        'database' => $db->databaseName(),
        'migrations_applied' => $applied,
        'migrations_pending' => count($pending),
        'pending_files' => array_map('basename', $pending),
        'time_utc' => Clock::nowDb(),
    ];

    if ($action === 'status') {
        syncRespond(true, 'Database status read.', $data);
    }

    if ($action === 'migrate' || $action === 'install') {
        $appliedNow = $migrator->migrate();
        $data['applied_now'] = $appliedNow;
        $data['migrations_applied'] = count($migrator->files()) - count($migrator->pending());

        if ($appliedNow !== []) {
            Logger::info('Deployment sync applied migrations', ['migrations' => $appliedNow]);
        }
    }

    if ($action === 'seed' || $action === 'install') {
        $seedFile = $root . '/database/seeds/run.php';

        if (!is_file($seedFile)) {
            syncRespond(false, 'Seeder is not available on this deployment.', $data, 500);
        }

        /** @var callable $seeder */
        $seeder = require $seedFile;
        $seeder();
        $data['seeded'] = true;
    }

    syncRespond(true, $action === 'install' ? 'Database installed.' : 'Database synced.', $data);
} catch (Throwable $exception) {
    // Keep the public response generic; details go to the application log only.
    Logger::error('Deployment sync failed', [
        'action' => $action,
        'exception' => $exception::class,
        'error' => $exception->getMessage(),
    ]);

    syncRespond(
        false,
        'Database sync failed. Check the server application log for the correlation id.',
        ['correlation_id' => Logger::correlationId()],
        500
    );
} finally {
    if ($locked && is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}
