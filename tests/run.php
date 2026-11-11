<?php

declare(strict_types=1);

/**
 * MtaaPass plain-PHP test runner.
 *
 * Usage:
 *   php tests/run.php
 *   php tests/run.php --filter=Router
 *   php tests/run.php --filter=Integration\AuthFlowTest::testFullOtpLogin
 *
 * The runner boots the application once, rebuilds the `_test` database and
 * seeds it once, then runs every `test*` method on every discovered test class
 * in tests/{Unit,Integration,Security}. Failures are isolated per test method:
 * one failing test never prevents the remaining tests from running.
 *
 * Exit code: 0 when every test passed, 1 otherwise.
 */

use App\Core\Database;
use App\Core\Migrator;
use Tests\AssertionFailed;
use Tests\TestCase;
use Tests\TestDatabase;

$startedAt = microtime(true);

$filter = null;

for ($i = 1; $i < count($argv); $i++) {
    $argument = $argv[$i];

    if (str_starts_with($argument, '--filter=')) {
        $filter = substr($argument, strlen('--filter='));
    } elseif ($argument === '--filter') {
        $filter = $argv[$i + 1] ?? null;
        $i++;
    } elseif ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php tests/run.php [--filter=<substring>]\n");
        exit(0);
    }
}

if (is_string($filter)) {
    $filter = trim($filter);

    if ($filter === '') {
        $filter = null;
    }
}

// -----------------------------------------------------------------------------
// Cross-process lock.
//
// The suite shares one disposable `_test` database, so two concurrent runs (for
// example two agents verifying at the same time) would race each other's
// Migrator::fresh() rebuild and fail with "table already exists"/FK errors. The
// lock is held for the lifetime of the process (released on exit) and makes a
// second runner wait for the first instead of corrupting it. Best effort: if the
// temp lock file cannot be created, the suite still runs.
// -----------------------------------------------------------------------------
$lockHandle = null;

try {
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'mtaapass-test-' . substr(hash('sha256', BASE_PATH), 0, 16) . '.lock';
    $handle = @fopen($lockPath, 'c');

    if (is_resource($handle)) {
        $deadline = microtime(true) + 900.0;
        $waiting = false;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (!$waiting) {
                echo "Another test run is using the shared test database; waiting for it to finish...\n";
                $waiting = true;
            }

            if (microtime(true) >= $deadline) {
                fwrite(STDERR, "Timed out waiting for another test run to finish.\n");
                exit(1);
            }

            usleep(500000);
        }

        $lockHandle = $handle;
    }
} catch (Throwable) {
    $lockHandle = null;
}

// -----------------------------------------------------------------------------
// Boot: environment overrides first, then the real app kernel.
// -----------------------------------------------------------------------------
$bootstrap = require __DIR__ . '/bootstrap.php';

/** @var App\Core\App $app */
$app = $bootstrap['app'] ?? null;

if (!$app instanceof App\Core\App) {
    fwrite(STDERR, "Test bootstrap did not return the booted application.\n");
    exit(1);
}

$GLOBALS['MTAAPASS_TEST_APP'] = $app;

// PSR-4 style autoloader for the Tests\ namespace (tests/ directory).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// -----------------------------------------------------------------------------
// One-time schema rebuild + seed.
// -----------------------------------------------------------------------------
echo "MtaaPass test suite\n";
echo 'Environment : ' . config('app.env') . "\n";
echo 'Database    : ' . Database::instance()->databaseName() . "\n";

try {
    $migrator = new Migrator(Database::instance(), BASE_PATH . '/database/migrations');
    $applied = $migrator->fresh();
    TestDatabase::seed();
    echo 'Schema      : rebuilt (' . count($applied) . " migration(s) applied) + seeded\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\nBOOTSTRAP FAILED: " . $exception->getMessage() . "\n");
    fwrite(STDERR, 'In ' . $exception->getFile() . ':' . $exception->getLine() . "\n");

    exit(1);
}

// -----------------------------------------------------------------------------
// Discover test classes.
// -----------------------------------------------------------------------------
$files = [];

foreach (['Unit', 'Integration', 'Security'] as $directory) {
    $found = glob(__DIR__ . '/' . $directory . '/*Test.php');

    if ($found !== false && $found !== []) {
        sort($found);
        $files = array_merge($files, $found);
    }
}

if ($files === []) {
    fwrite(STDERR, "\nNo test files found in tests/{Unit,Integration,Security}/*Test.php\n");
    exit(1);
}

$classes = [];

foreach ($files as $file) {
    require_once $file;

    $relative = str_replace('\\', '/', substr($file, strlen(__DIR__) + 1));
    $relative = substr($relative, 0, -strlen('.php'));
    $class = 'Tests\\' . str_replace('/', '\\', $relative);

    if (!class_exists($class)) {
        fwrite(STDERR, sprintf("\nTest file %s does not declare the expected class %s\n", $relative . '.php', $class));
        exit(1);
    }

    $reflection = new ReflectionClass($class);

    if ($reflection->isAbstract()) {
        continue;
    }

    $classes[$class] = $reflection;
}

// -----------------------------------------------------------------------------
// Run the suite.
// -----------------------------------------------------------------------------
$total = 0;
$passed = 0;
$failed = 0;
$failures = [];

foreach ($classes as $class => $reflection) {
    $methods = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();

        if (!str_starts_with($name, 'test') || $method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $methods[] = $name;
    }

    sort($methods);

    foreach ($methods as $method) {
        $label = $class . '::' . $method;

        if ($filter !== null && stripos($label, $filter) === false) {
            continue;
        }

        $total++;

        $test = null;
        $error = null;

        try {
            $test = new $class($app);
            $test->setUp();
            $test->{$method}();
        } catch (AssertionFailed $assertion) {
            $error = $assertion;
        } catch (Throwable $throwable) {
            $error = $throwable;
        } finally {
            if ($test instanceof TestCase) {
                try {
                    $test->tearDown();
                } catch (Throwable) {
                    // A tearDown failure must not mask the test outcome.
                }
            }
        }

        if ($error === null) {
            $passed++;
            echo 'PASS  ' . $label . "\n";
        } else {
            $failed++;
            $failures[] = ['label' => $label, 'error' => $error];
            echo 'FAIL  ' . $label . "\n";
        }
    }
}

// -----------------------------------------------------------------------------
// Failure details.
// -----------------------------------------------------------------------------
if ($failures !== []) {
    echo "\nFailures\n" . str_repeat('-', 78) . "\n";

    foreach ($failures as $index => $failure) {
        $error = $failure['error'];
        $kind = $error instanceof AssertionFailed ? 'Assertion' : $error::class;

        echo ($index + 1) . ') ' . $failure['label'] . "\n";
        echo '   ' . $kind . ': ' . str_replace("\n", "\n   ", $error->getMessage()) . "\n";

        if (!$error instanceof AssertionFailed) {
            echo '   At ' . $error->getFile() . ':' . $error->getLine() . "\n";
        }
    }

    echo str_repeat('-', 78) . "\n";
}

// -----------------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------------
$duration = microtime(true) - $startedAt;

echo "\nSummary\n" . str_repeat('-', 78) . "\n";
echo sprintf("  Total    : %d\n", $total);
echo sprintf("  Passed   : %d\n", $passed);
echo sprintf("  Failed   : %d\n", $failed);
echo sprintf("  Duration : %.2fs\n", $duration);

if ($total === 0) {
    echo "\nNo tests matched" . ($filter !== null ? sprintf(' --filter=%s', $filter) : '') . ".\n";
    exit(1);
}

echo $failed === 0 ? "\nRESULT: PASS\n" : "\nRESULT: FAIL\n";

exit($failed === 0 ? 0 : 1);
