<?php

/**
 * Two real PHP processes and two MySQL connections, against disposable CI data.
 *
 * Deliberately NOT a backup scanner, a production task or a receipt writer.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_CONCURRENCY') !== '1') {
    fwrite(STDERR, "F02 concurrency test refused: testing-only gate not satisfied.\n");
    exit(2);
}

$task = null;
$device = null;
$rule = null;
$folder = sys_get_temp_dir() . '/rconfig_f02_concurrency_' . bin2hex(random_bytes(8));
$failed = false;

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'v8core_test') {
        throw new RuntimeException('Only the ephemeral v8core_test MySQL database is allowed.');
    }

    if (! mkdir($folder, 0700) || ! is_dir($folder)) {
        throw new RuntimeException('Unable to allocate synthetic test barrier.');
    }

    $task = Task::factory()->create();
    $device = Device::factory()->create();
    $rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'enabled' => true,
    ]);

    /** @return array{array<string, mixed>, array<string, mixed>} */
    $pair = static function (string $action, int $id, string $instant) use ($root, $folder): array {
        $gate = $folder . '/barrier_' . bin2hex(random_bytes(6));
        $worker = $root . '/tools/f02/concurrency/worker.php';
        $processes = [
            new Process([PHP_BINARY, $worker, $action, (string) $id, $instant, $gate], $root, null, null, 25),
            new Process([PHP_BINARY, $worker, $action, (string) $id, $instant, $gate], $root, null, null, 25),
        ];

        try {
            foreach ($processes as $process) {
                $process->start();
            }

            // Both processes have bootstrapped Laravel and reached the barrier
            // before either begins its database claim.
            $end = microtime(true) + 15;
            while (count(glob($gate . '.*.ready') ?: []) !== 2) {
                if (microtime(true) >= $end || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    throw new RuntimeException('Two workers did not both reach the synthetic test barrier.');
                }
                usleep(10000);
            }

            if (! touch($gate)) {
                throw new RuntimeException('Unable to release test workers.');
            }

            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException('Synthetic worker returned a nonzero exit code.');
                }

                $value = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($value) || ! isset($value['id'], $value['status'])) {
                    throw new RuntimeException('Synthetic worker did not return a valid run identifier.');
                }
                $outputs[] = $value;
            }

            return $outputs;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($gate . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    };

    $claims = $pair('claim', $rule->id, '2026-11-22T15:00:00+00:00');
    if ($claims[0]['id'] !== $claims[1]['id'] || $claims[0]['status'] !== 'pending'
        || $claims[1]['status'] !== 'pending') {
        throw new RuntimeException('Concurrent claim diverged across PHP workers.');
    }

    $runId = (int) $claims[0]['id'];
    if (ExternalBackupCheckRun::where('rule_id', $rule->id)->count() !== 1) {
        throw new RuntimeException('Duplicate run was created despite unique occurrence key.');
    }

    $pending = $pair('pending', $runId, '2026-11-22T15:05:00+00:00');
    if ($pending[0]['status'] !== 'pending' || $pending[1]['status'] !== 'pending') {
        throw new RuntimeException('Concurrent pending observations diverged.');
    }

    $expired = $pair('expire', $runId, '2026-11-22T16:00:00+00:00');
    if ($expired[0]['status'] !== 'failed' || $expired[1]['status'] !== 'failed') {
        throw new RuntimeException('Concurrent terminal transitions diverged.');
    }

    $retries = $pair('claim', $rule->id, '2026-11-22T15:00:00+00:00');
    if ($retries[0]['id'] !== $runId || $retries[1]['id'] !== $runId
        || $retries[0]['status'] !== 'failed' || $retries[1]['status'] !== 'failed') {
        throw new RuntimeException('Retry unexpectedly reset a terminal failure.');
    }

    $final = ExternalBackupCheckRun::findOrFail($runId);
    if ($final->reason !== 'missing'
        || $final->getRawOriginal('first_observed_at_utc') !== '2026-11-22 15:05:00'
        || $final->getRawOriginal('last_observed_at_utc') !== '2026-11-22 16:00:00'
        || ExternalBackupFileReceipt::where('check_run_id', $runId)->exists()) {
        throw new RuntimeException('Concurrent final state or receipt guard violated.');
    }

    echo "F02 TWO_WORKER_CLAIM_PENDING_EXPIRE_RETRY_OK (NO_BACKUP_SUCCESS)\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'F02 synthetic concurrency test failed: ' . get_class($exception) . "\n");
} finally {
    // Only fixtures created on the explicitly gated, ephemeral CI database.
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

    foreach (glob($folder . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($folder)) {
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
