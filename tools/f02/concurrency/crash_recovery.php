<?php

/**
 * Synthetic-only SIGKILL / MySQL rollback and commit-acknowledgment tests.
 *
 * No real queue, device, backup data, filesystem intake or receipts are used.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_CONCURRENCY') !== '1') {
    fwrite(STDERR, "F02 crash/retry test refused: testing-only gate not satisfied.\n");
    exit(2);
}

$task = null;
$device = null;
$rule = null;
$folder = sys_get_temp_dir() . '/rconfig_f02_crash_' . bin2hex(random_bytes(8));
$failed = false;

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing')
        || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'v8core_test') {
        throw new RuntimeException('Crash test requires ephemeral v8core_test MySQL.');
    }

    if (! mkdir($folder, 0700) || ! is_dir($folder)) {
        throw new RuntimeException('Could not create disposable crash-test directory.');
    }

    $task = Task::factory()->create();
    $device = Device::factory()->create();
    $rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'deadline_seconds' => 3600,
        'enabled' => true,
    ]);

    $scheduled = CarbonImmutable::parse('2026-11-23 15:00:00', 'UTC');
    $run = (new ExternalBackupOccurrenceClaimService)->claim($rule->id, $scheduled);
    $runId = (int) $run->id;

    /**
     * Start a separate PHP process, wait for its transaction marker,
     * send SIGKILL, and verify the child exited unsuccessfully.
     */
    $crash = static function (string $phase) use ($root, $folder, $runId): void {
        $marker = $folder . '/f02_crash_' . $phase . '_' . bin2hex(random_bytes(4));
        $child = new Process([
            PHP_BINARY,
            $root . '/tools/f02/concurrency/crash_worker.php',
            $phase,
            (string) $runId,
            $marker,
        ], $root, null, null, 25);

        try {
            $child->start();

            $limit = microtime(true) + 12;
            while (! is_file($marker . '.ready')) {
                if (microtime(true) >= $limit || ! $child->isRunning()) {
                    throw new RuntimeException('Synthetic crash worker did not reach its required phase.');
                }

                usleep(10000);
            }

            if (! $child->isRunning()) {
                throw new RuntimeException('Synthetic crash worker ended before forced termination.');
            }

            // Linux hosted CI only: kill the isolated child process, never the
            // parent runner, the PHP test suite, or an actual queue worker.
            $child->signal(9);
            $child->wait();

            if ($child->isSuccessful()) {
                throw new RuntimeException('Synthetic crash worker was not terminated.');
            }
        } finally {
            if ($child->isRunning()) {
                $child->stop(1, 9);
            }

            if (is_file($marker . '.ready')) {
                unlink($marker . '.ready');
            }
        }
    };

    // Phase A: a worker is killed after UPDATE while holding the row lock.
    // Its uncommitted SQL changes must be rolled back by MySQL on disconnect.
    $crash('precommit');

    $rolledBack = ExternalBackupCheckRun::findOrFail($runId);
    if ($rolledBack->status !== 'pending'
        || $rolledBack->reason !== null
        || $rolledBack->getRawOriginal('first_observed_at_utc') !== null
        || $rolledBack->getRawOriginal('last_observed_at_utc') !== null) {
        throw new RuntimeException('Uncommitted worker changes leaked after SIGKILL.');
    }

    // The actual service can retry the same observation without resurrecting
    // leaked metadata from the aborted transaction.
    $state = new ExternalBackupRunStateService;
    $recovered = $state->recordPending(
        $runId,
        CarbonImmutable::parse('2026-11-23 15:05:00', 'UTC'),
        'unstable',
        'synthetic_retry.cfg'
    );

    if ($recovered->status !== 'pending'
        || $recovered->getRawOriginal('first_observed_at_utc') !== '2026-11-23 15:05:00') {
        throw new RuntimeException('Pending observation did not recover after rollback.');
    }

    // Phase B: a worker is killed AFTER the actual service has committed
    // failed status but BEFORE the caller has received any acknowledgment.
    $crash('postcommit');

    $committed = ExternalBackupCheckRun::findOrFail($runId);
    $originalUpdatedAt = $committed->getRawOriginal('updated_at');
    if ($committed->status !== 'failed'
        || $committed->reason !== 'missing'
        || $committed->getRawOriginal('last_observed_at_utc') !== '2026-11-23 16:00:00') {
        throw new RuntimeException('Committed terminal state was lost with the worker.');
    }

    $replayed = $state->expire(
        $runId,
        CarbonImmutable::parse('2026-11-23 17:00:00', 'UTC'),
        'unsafe_file',
        'synthetic_other.cfg'
    );
    $claimedAgain = (new ExternalBackupOccurrenceClaimService)->claim($rule->id, $scheduled);

    if ($replayed->id !== $runId || $claimedAgain->id !== $runId
        || $replayed->status !== 'failed' || $replayed->reason !== 'missing'
        || $claimedAgain->status !== 'failed'
        || $replayed->getRawOriginal('updated_at') !== $originalUpdatedAt
        || ExternalBackupCheckRun::where('rule_id', $rule->id)->count() !== 1
        || ExternalBackupFileReceipt::where('check_run_id', $runId)->exists()) {
        throw new RuntimeException('Committed state was duplicated, rewritten or given a receipt on retry.');
    }

    echo "F02 SIGKILL_ROLLBACK_COMMIT_RETRY_OK (NO_BACKUP_SUCCESS)\n";
} catch (Throwable $exception) {
    $failed = true;
    // Avoid printing DB connection information or generated paths in public CI.
    fwrite(STDERR, 'F02 synthetic crash/retry test failed: ' . get_class($exception) . "\n");
} finally {
    // Delete only fixtures created on the explicitly gated testing database.
    if ($rule !== null) {
        try {
            ExternalBackupCheckRun::where('rule_id', $rule->id)->delete();
            $rule->delete();
        } catch (Throwable) {
            $failed = true;
        }
    }

    if ($device !== null) {
        try {
            $device->delete();
        } catch (Throwable) {
            $failed = true;
        }
    }

    if ($task !== null) {
        try {
            $task->delete();
        } catch (Throwable) {
            $failed = true;
        }
    }

    foreach (glob($folder . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($folder)) {
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
