<?php

/**
 * Synthetic-only worker for the F02 MySQL concurrency test.
 *
 * Run only through run.php on an ephemeral testing database.
 * No filesystem intake, backup receipt, or production integration is involved.
 */

use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';

if ($argc !== 5 || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_CONCURRENCY') !== '1') {
    fwrite(STDERR, "F02 worker refused: ephemeral testing environment required.\n");
    exit(2);
}

$action = $argv[1];
$id = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$at = $argv[3];
$gate = $argv[4];

if (! in_array($action, ['claim', 'pending', 'expire'], true) || $id === false || ! is_dir(dirname($gate))) {
    fwrite(STDERR, "F02 worker refused: invalid input.\n");
    exit(2);
}

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'v8core_test'
        || DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Only ephemeral test MySQL connections are permitted.');
    }

    $ready = $gate . '.' . getmypid() . '.ready';
    if (file_put_contents($ready, "ready\n") === false) {
        throw new RuntimeException('Cannot signal test worker readiness.');
    }

    $released = false;
    for ($i = 0; $i < 1500; $i++) {
        if (is_file($gate)) {
            $released = true;
            break;
        }
        usleep(10000);
    }

    if (! $released) {
        throw new RuntimeException('F02 test barrier timed out.');
    }

    $instant = CarbonImmutable::parse($at, 'UTC');
    $run = match ($action) {
        'claim' => (new ExternalBackupOccurrenceClaimService)->claim($id, $instant),
        'pending' => (new ExternalBackupRunStateService)->recordPending($id, $instant, 'missing'),
        'expire' => (new ExternalBackupRunStateService)->expire($id, $instant, 'missing'),
    };

    $saved = $run->fresh(); // Include SQL defaults even on the first INSERT.
    echo json_encode(['id' => $saved->id, 'status' => $saved->status, 'rule_id' => $saved->rule_id], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    // Do not echo credentials, paths or exception trace into CI.
    fwrite(STDERR, 'F02 worker failed: ' . get_class($exception) . "\n");
    exit(1);
}
