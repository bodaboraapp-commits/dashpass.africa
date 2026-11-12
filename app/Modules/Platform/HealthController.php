<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Health endpoint.
 *
 * Public responses expose only `{status, app, version}`. The detailed check
 * payload is available in local/testing environments or with a valid
 * HEALTH_DETAIL_TOKEN (header X-Health-Token or ?token=).
 */
final class HealthController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $checks = [];

        $databaseStarted = microtime(true);
        $databaseOk = true;

        try {
            $context->db->scalar('SELECT 1');
        } catch (Throwable) {
            $databaseOk = false;
        }

        $checks['database'] = [
            'status' => $databaseOk ? 'ok' : 'error',
            'latency_ms' => round((microtime(true) - $databaseStarted) * 1000, 1),
        ];

        $applied = 0;
        $pending = 0;
        $migrationsOk = $databaseOk;

        if ($databaseOk) {
            try {
                $migrator = new Migrator($context->db, BASE_PATH . '/database/migrations');
                $status = $migrator->status();

                foreach ($status as $migration) {
                    if (($migration['applied'] ?? false) === true) {
                        $applied++;
                    } else {
                        $pending++;
                    }
                }
            } catch (Throwable) {
                $migrationsOk = false;
            }
        }

        $checks['migrations'] = [
            'status' => $migrationsOk ? 'ok' : 'error',
            'applied' => $applied,
            'pending' => $pending,
        ];

        $storageOk = $this->storageWritable();

        $checks['storage'] = [
            'status' => $storageOk ? 'ok' : 'error',
        ];

        $healthy = $databaseOk && $migrationsOk && $storageOk;
        $payload = [
            'status' => $healthy ? 'ok' : 'error',
            'app' => (string) Config::get('app.name', 'MtaaPass'),
            'version' => (string) Config::get('app.version', '0.1.0-foundation'),
        ];

        if ($this->detailAllowed($request)) {
            $payload['environment'] = (string) Config::get('app.env', 'production');
            $payload['time_utc'] = Clock::now()->format('Y-m-d\TH:i:s.v\Z');
            $payload['checks'] = $checks;
        }

        return Response::json($payload, $healthy ? 200 : 503);
    }

    private function detailAllowed(Request $request): bool
    {
        if (in_array((string) Config::get('app.env', 'production'), ['local', 'testing'], true)) {
            return true;
        }

        $expected = trim((string) (getenv('HEALTH_DETAIL_TOKEN') ?: ''));

        if ($expected === '') {
            return false;
        }

        $supplied = trim((string) ($request->header('X-Health-Token') ?? $request->queryString('token')));

        return $supplied !== '' && hash_equals($expected, $supplied);
    }

    /**
     * Prove the log directory accepts writes by writing a short-lived probe
     * file. `is_writable()` alone is unreliable for directories on some
     * Windows builds, so the probe is the authoritative check.
     */
    private function storageWritable(): bool
    {
        $directory = BASE_PATH . '/storage/logs';

        if (!is_dir($directory)) {
            return false;
        }

        $probe = $directory . '/.health-' . bin2hex(random_bytes(6));
        $written = @file_put_contents($probe, 'ok');

        if ($written === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }
}
