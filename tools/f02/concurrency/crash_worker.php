<?php

/**
 * Crash-injection subprocess for CI-only F02 recovery tests.
 *
 * This is NOT a queue worker, a production endpoint or an upload-file reader.
 * Parent intentionally sends SIGKILL after the readiness marker.
 */

use App\Models\ExternalBackupCheckRun;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';

if ($argc !== 4 || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_CONCURRENCY') !== '1') {
    fwrite(STDERR, "F02 crash worker refused: CI test gate required.\n");
    exit(2);
}

$mode = $argv[1];
$runId = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$marker = $argv[3];
$directory = realpath(dirname($marker));
$temporaryRoot = realpath(sys_get_temp_dir());

if (! in_array($mode, ['precommit', 'postcommit'], true)
    || $runId === false || $directory === false || $temporaryRoot === false
    || ! str_starts_with($directory, $temporaryRoot . DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename($marker), 'f02_crash_')) {
    fwrite(STDERR, "F02 crash worker refused: invalid test arguments.\n");
    exit(2);
}

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing')
        || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'v8core_test') {
        throw new RuntimeException('Crash test requires ephemeral v8core_test MySQL.');
    }

    if ($mode === 'precommit') {
        // Simulate a process dying after a row UPDATE but before COMMIT.
        // Do NOT use the service here: this deliberately instruments the
        // transaction boundary that the service does not expose as a hook.
        DB::beginTransaction();

        $run = ExternalBackupCheckRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
        if ($run->status !== 'pending' || $run->receipt()->exists()) {
            throw new RuntimeException('Precommit fixture is not pending.');
        }

        $run->reason = 'unstable';
        $run->matched_filename = 'synthetic_in_progress.cfg';
        $run->first_observed_at_utc = '2026-11-23 15:05:00';
        $run->last_observed_at_utc = '2026-11-23 15:05:00';
        $run->save();
    } else {
        // This service commits before our marker is written. A kill after
        // the marker simulates loss of the worker's acknowledgment.
        (new ExternalBackupRunStateService)->expire(
            $runId,
            CarbonImmutable::parse('2026-11-23 16:00:00', 'UTC'),
            'missing'
        );
    }

    if (file_put_contents($marker . '.ready', "ready\n") === false) {
        throw new RuntimeException('Unable to signal test transaction state.');
    }

    // Parent must terminate the process with SIGKILL. Deliberate finite
    // wait prevents a hung orphan from holding the MySQL lock indefinitely.
    usleep(20000000);

    throw new RuntimeException('Crash worker was not interrupted by parent.');
} catch (Throwable $exception) {
    fwrite(STDERR, 'F02 crash worker failed: ' . get_class($exception) . "\n");
    exit(1);
}
