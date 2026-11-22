<?php

/**
 * CI-only proof of actual Redis queue:work redelivery after SIGKILL.
 *
 * No real F02 scheduler or worker is enabled and NO file receipts are written.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\ExternalBackups\F02RedisCrashProbeJob;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('F02_SYNTHETIC_REDIS_QUEUE') !== '1') {
    fwrite(STDERR, "F02 Redis redelivery test refused: GitHub test-only gate required.\n");
    exit(2);
}

$failed = false;
$rule = null;
$task = null;
$device = null;
$queueName = 'f02_ci_' . bin2hex(random_bytes(8));
$directory = sys_get_temp_dir() . '/f02_queue_' . bin2hex(random_bytes(8));
$marker = $directory . '/job';

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing')
        || config('database.default') !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'v8core_test'
        || config('database.redis.default.host') !== '127.0.0.1'
        || config('queue.connections.redis.driver') !== 'redis') {
        throw new RuntimeException('Redis redelivery probe requires ephemeral MySQL/Redis in GitHub CI.');
    }

    if (! mkdir($directory, 0700) || ! is_dir($directory)) {
        throw new RuntimeException('Cannot create synthetic Redis queue barrier.');
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

    $run = (new ExternalBackupOccurrenceClaimService)->claim(
        $rule->id,
        CarbonImmutable::parse('2026-11-24 15:00:00', 'UTC')
    );

    // Raw queue driver push, rather than invoking any production Task/HTTP path.
    Queue::connection('redis')->push(
        new F02RedisCrashProbeJob((int) $run->id, $marker, $queueName),
        '',
        $queueName
    );

    $command = [PHP_BINARY, $root . '/tools/f02/queue/worker.php', $queueName];
    $first = new Process($command, $root, null, null, 25);

    try {
        $first->start();
        $until = microtime(true) + 12;
        while (! is_file($marker . '.started')) {
            if (! $first->isRunning() || microtime(true) > $until) {
                throw new RuntimeException('First actual Redis queue:work did not reach the commit marker.');
            }

            usleep(10000);
        }

        if (! $first->isRunning()) {
            throw new RuntimeException('First Redis queue worker exited before forced interruption.');
        }

        // Parent SIGKILLs ONLY the subprocess it created.
        // The job has committed pending state but has not returned to Laravel
        // to acknowledge/delete the Redis reserved message.
        $first->signal(9);
        $first->wait();

        if ($first->isSuccessful()) {
            throw new RuntimeException('First Redis queue worker was not interrupted.');
        }
    } finally {
        if ($first->isRunning()) {
            $first->stop(1, 9);
        }
    }

    $stored = ExternalBackupCheckRun::findOrFail($run->id);
    $originalUpdate = $stored->getRawOriginal('updated_at');
    if ($stored->status !== 'pending' || $stored->reason !== 'missing'
        || $stored->getRawOriginal('first_observed_at_utc') !== '2026-11-24 15:05:00'
        || ExternalBackupFileReceipt::where('check_run_id', $run->id)->exists()) {
        throw new RuntimeException('Committed state was lost or a receipt appeared after worker crash.');
    }

    // First queue:work child constructed its Redis connection with a temporary
    // in-memory retry_after of 4s. The only consumer was killed before retry.
    // Redis migrates the expired reservation when the replacement worker pops.
    usleep(5500000);

    $secondProcessed = false;
    for ($attempt = 0; $attempt < 3 && ! $secondProcessed; $attempt++) {
        $second = new Process($command, $root, null, null, 25);

        try {
            $second->run();
            if (! $second->isSuccessful()) {
                throw new RuntimeException('Replacement Redis queue worker failed.');
            }

            $secondProcessed = is_file($marker . '.done');
        } finally {
            if ($second->isRunning()) {
                $second->stop(1, 9);
            }
        }

        if (! $secondProcessed) {
            usleep(1000000);
        }
    }

    if (! $secondProcessed || trim((string) file_get_contents($marker . '.done')) !== 'attempts=2') {
        throw new RuntimeException('Redis failed to redeliver the reserved job to a new worker.');
    }

    $final = ExternalBackupCheckRun::findOrFail($run->id);
    if ($final->id !== $run->id || $final->status !== 'pending' || $final->reason !== 'missing'
        || $final->getRawOriginal('first_observed_at_utc') !== '2026-11-24 15:05:00'
        || $final->getRawOriginal('last_observed_at_utc') !== '2026-11-24 15:05:00'
        || $final->getRawOriginal('updated_at') !== $originalUpdate
        || ExternalBackupCheckRun::where('rule_id', $rule->id)->count() !== 1
        || ExternalBackupFileReceipt::where('check_run_id', $run->id)->exists()
        || Queue::connection('redis')->size($queueName) !== 0) {
        throw new RuntimeException('Redis redelivery duplicated state or retained a claimed job.');
    }

    echo "F02 REAL_REDIS_QUEUE_WORKER_REDELIVERY_OK (NO_BACKUP_SUCCESS)\n";
} catch (Throwable $exception) {
    $failed = true;
    // Never print credentials, payloads or temp paths into a public CI log.
    fwrite(STDERR, 'F02 Redis redelivery test failed: ' . get_class($exception) . "\n");
} finally {
    // Only this randomly named synthetic Redis queue namespace is touched.
    if (isset($app) && $app->environment('testing')
        && config('database.redis.default.host') === '127.0.0.1') {
        foreach (['', ':reserved', ':delayed', ':notify'] as $suffix) {
            try {
                Redis::connection()->del('queues:' . $queueName . $suffix);
            } catch (Throwable) {
                $failed = true;
            }
        }
    }

    // Delete only synthetic fixtures created on the guarded testing DB.
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

    foreach ([$marker . '.started', $marker . '.done'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

exit($failed ? 1 : 0);
