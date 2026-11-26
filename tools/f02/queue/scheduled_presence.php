<?php

/**
 * Hosted CI only: real scheduler callbacks and Horizon, synthetic files.
 * No production access, providers, native downloads or verified receipts.
 */

use App\Jobs\LocalBackupPresenceManualObserveJob;
use App\Jobs\LocalBackupPresenceObserveJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

function presenceCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F02 presence probe failed: ' . $stage);
    }
}

/**
 * Kill only an owned child after its real Redis reservation, with the rule
 * locked by a separate DB connection. Then observe actual lease expiration
 * and force simultaneous duplicate delivery without altering retry_after.
 */
function presenceCrashProbe(string $root, LocalBackupPresenceRule $rule, LocalBackupPresenceRun $run, $dispatch, $app): void
{
    $queue = Queue::connection('redis_local_backup_presence');
    $redis = Redis::connection('default');
    $reservedKey = 'queues:LocalBackupPresence:reserved';
    $failedBefore = DB::table('failed_jobs')->count();
    presenceCheck(config('queue.connections.redis_local_backup_presence.retry_after') === 120
        && config('queue.connections.redis_local_backup_presence.connection') === 'default'
        && $queue->size('LocalBackupPresence') === 1, 'real retry configuration');

    $workers = [];
    $command = [PHP_BINARY, 'artisan', 'queue:work', 'redis_local_backup_presence',
        '--queue=LocalBackupPresence', '--once', '--sleep=0', '--timeout=30', '--tries=2', '--no-interaction'];
    $lock = static function () use ($rule): void {
        DB::beginTransaction();
        LocalBackupPresenceRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
    };
    $waitReserved = static function (int $expected, array $children) use ($redis, $reservedKey): array {
        $until = microtime(true) + 15;
        do {
            foreach ($children as $child) {
                presenceCheck($child->isRunning(), 'owned worker running at reservation barrier');
            }
            $payloads = $redis->zrange($reservedKey, 0, -1);
            if (count($payloads) === $expected) {
                return $payloads;
            }
            presenceCheck(microtime(true) < $until, 'real Redis reservation barrier');
            usleep(100000);
        } while (true);
    };

    try {
        $before = $run->fresh()->getRawOriginal();
        $lock();
        $first = new Process($command, $root, null, null, 40);
        $workers[] = $first;
        $first->start();
        $payload = $waitReserved(1, [$first])[0];
        $original = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $lease = $redis->zscore($reservedKey, $payload);
        presenceCheck(is_string($original['uuid'] ?? null) && ($original['attempts'] ?? null) === 1
            && is_numeric($lease) && (int) $lease > time() && (int) $lease <= time() + 120, 'original reserved job and lease');

        // No scan can commit while this rule is locked by the parent.
        $first->signal(SIGKILL);
        $first->wait();
        presenceCheck(! $first->isSuccessful(), 'owned child interrupted');
        DB::rollBack();
        presenceCheck($run->fresh()->getRawOriginal() === $before
            && $redis->zcard($reservedKey) === 1 && $queue->size('LocalBackupPresence') === 1, 'unobserved state and reserved message survive crash');
        echo "F02_PRESENCE_PREOBSERVATION_CRASH_OK (SYNTHETIC_ONLY)\n";

        $until = microtime(true) + 125;
        while (time() <= (int) $lease) {
            presenceCheck(microtime(true) < $until, 'actual retry lease elapsed');
            usleep(200000);
        }
        // Intentionally duplicate the same immutable occurrence IDs in this
        // isolated queue. No new occurrence or synthetic scanner evidence.
        Bus::dispatch(new LocalBackupPresenceObserveJob((int) $rule->id, (int) $run->id));
        presenceCheck($queue->size('LocalBackupPresence') === 2, 'duplicate and recoverable reservation');
        $lock();
        $second = new Process($command, $root, null, null, 40);
        $third = new Process($command, $root, null, null, 40);
        $workers[] = $second;
        $workers[] = $third;
        $second->start();
        $third->start();
        presenceCheck($second->getPid() !== null && $third->getPid() !== null
            && $second->getPid() !== $third->getPid(), 'independent worker processes');
        $payloads = $waitReserved(2, [$second, $third]);
        $attempts = [];
        foreach ($payloads as $item) {
            $decoded = json_decode($item, true, 512, JSON_THROW_ON_ERROR);
            presenceCheck(is_string($decoded['uuid'] ?? null) && is_int($decoded['attempts'] ?? null), 'actual concurrent reserved payload');
            $attempts[$decoded['uuid']] = $decoded['attempts'];
        }
        presenceCheck(count($attempts) === 2 && ($attempts[$original['uuid']] ?? null) === 2
            && in_array(1, $attempts, true), 'original attempt two and distinct duplicate attempt one');
        echo "F02_PRESENCE_REAL_REDELIVERY_CONCURRENT_RESERVATIONS_OK (SYNTHETIC_ONLY)\n";
        DB::commit();
        foreach ([$second, $third] as $worker) {
            $worker->wait();
            presenceCheck($worker->isSuccessful(), 'replacement worker completed');
        }

        $final = $run->fresh();
        presenceCheck($final->status === 'present' && $final->reason === 'matching_file_observed'
            && $final->matched_filename === 'backup_final.cfg'
            && $final->getRawOriginal('first_observed_at_utc') !== null
            && $final->getRawOriginal('first_observed_at_utc') === $final->getRawOriginal('last_observed_at_utc')
            && $final->getRawOriginal('last_observed_at_utc') < $final->getRawOriginal('deadline_at_utc')
            && $final->stability_baseline === null
            && $rule->runs()->count() === 1 && $queue->size('LocalBackupPresence') === 0
            && DB::table('failed_jobs')->count() === $failedBefore, 'single terminal result and both acknowledgements');

        $snapshot = $final->getRawOriginal();
        $dispatch->run($app);
        presenceCheck($queue->size('LocalBackupPresence') === 0 && $run->fresh()->getRawOriginal() === $snapshot, 'crash terminal replay unchanged');
        echo "F02_PRESENCE_CRASH_CONCURRENCY_TERMINAL_OK (SYNTHETIC_ONLY)\n";
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1, SIGKILL);
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}

/**
 * Test actual job SQL transaction and queue acknowledgement boundaries.
 * The child installs test-only listeners; application code is untouched.
 */
function presenceTransactionCrashProbe(string $root, string $fixture, string $phase, LocalBackupPresenceRule $rule, LocalBackupPresenceRun $run, $dispatch, $app): void
{
    $queue = Queue::connection('redis_local_backup_presence');
    $redis = Redis::connection('default');
    $reservedKey = 'queues:LocalBackupPresence:reserved';
    $marker = $fixture . '/transaction-barrier';
    $timeoutMarker = $fixture . '/timeout-event';
    $failedBefore = DB::table('failed_jobs')->count();
    $before = $run->fresh()->getRawOriginal();
    presenceCheck(config('queue.connections.redis_local_backup_presence.retry_after') === 120
        && $queue->size('LocalBackupPresence') === 1 && ! file_exists($marker) && ! file_exists($timeoutMarker), 'transaction crash preflight');

    $workers = [];
    $command = [PHP_BINARY, $root . '/tools/f02/queue/presence_transaction_worker.php'];
    try {
        $child = new Process([...$command, $phase, (string) $run->id], $root, null, null, 40);
        $workers[] = $child;
        $started = hrtime(true);
        $child->start();
        $until = microtime(true) + 15;
        while (! is_file($marker) || trim((string) file_get_contents($marker)) !== $phase) {
            presenceCheck($child->isRunning() && microtime(true) < $until, 'actual SQL boundary barrier');
            usleep(100000);
        }
        presenceCheck($child->isRunning(), 'owned transaction worker at barrier');
        $reserved = $redis->zrange($reservedKey, 0, -1);
        presenceCheck(count($reserved) === 1, 'job reserved before acknowledgement');
        $original = json_decode($reserved[0], true, 512, JSON_THROW_ON_ERROR);
        $lease = $redis->zscore($reservedKey, $reserved[0]);
        presenceCheck(is_string($original['uuid'] ?? null) && ($original['attempts'] ?? null) === 1
            && is_numeric($lease) && (int) $lease > time() && (int) $lease <= time() + 120, 'original retry lease');

        $visible = $run->fresh();
        if (in_array($phase, ['precommit', 'timeout'], true)) {
            presenceCheck($visible->getRawOriginal() === $before, 'uncommitted observation not visible to separate connection');
        } else {
            presenceCheck($visible->status === 'present' && $visible->reason === 'matching_file_observed'
                && $visible->matched_filename === 'backup_final.cfg'
                && $visible->getRawOriginal('last_observed_at_utc') !== null, 'committed evidence visible before acknowledgement');
        }

        $snapshot = $visible->getRawOriginal();
        if ($phase === 'timeout') {
            // The worker's own Laravel alarm must fire; never signal it here.
            while ($child->isRunning()) {
                presenceCheck((hrtime(true) - $started) / 1000000000 < 40, 'worker self-termination deadline');
                usleep(100000);
            }
            $elapsed = (hrtime(true) - $started) / 1000000000;
            presenceCheck(! $child->isSuccessful() && $elapsed >= 29 && $elapsed < 40
                && is_file($timeoutMarker)
                && trim((string) file_get_contents($timeoutMarker)) === $original['uuid'] . ':1', 'real JobTimedOut and autonomous worker exit');
            printf("F02_PRESENCE_TIMEOUT_METRICS elapsed_seconds=%.3f\n", $elapsed);
        } else {
            $child->signal(SIGKILL);
            $child->wait();
            presenceCheck(! $child->isSuccessful(), 'owned transaction worker interrupted');
        }
        presenceCheck($run->fresh()->getRawOriginal() === $snapshot
            && $queue->size('LocalBackupPresence') === 1 && count($redis->zrange($reservedKey, 0, -1)) === 1, 'database and reserved job survive interruption');
        echo match ($phase) {
            'precommit' => "F02_PRESENCE_PRECOMMIT_ROLLBACK_OK (SYNTHETIC_ONLY)\n",
            'timeout' => "F02_PRESENCE_WORKER_TIMEOUT_ROLLBACK_OK (SYNTHETIC_ONLY)\n",
            default => "F02_PRESENCE_POSTCOMMIT_PREACK_OK (SYNTHETIC_ONLY)\n",
        };

        presenceCheck(unlink($marker), 'remove owned barrier');
        $until = microtime(true) + 125;
        while (time() <= (int) $lease) {
            presenceCheck(microtime(true) < $until, 'actual transaction retry lease elapsed');
            usleep(200000);
        }
        $retry = new Process([...$command, 'replay', (string) $run->id], $root, null, null, 40);
        $workers[] = $retry;
        $retry->run();
        presenceCheck($retry->isSuccessful() && is_file($marker)
            && trim((string) file_get_contents($marker)) === $original['uuid'] . ':2', 'same Redis message redelivered at attempt two');
        $final = $run->fresh();
        presenceCheck($final->status === 'present' && $final->reason === 'matching_file_observed'
            && $final->matched_filename === 'backup_final.cfg'
            && $final->getRawOriginal('first_observed_at_utc') !== null
            && $final->getRawOriginal('first_observed_at_utc') === $final->getRawOriginal('last_observed_at_utc')
            && $final->getRawOriginal('last_observed_at_utc') < $final->getRawOriginal('deadline_at_utc')
            && $final->stability_baseline === null && $rule->runs()->count() === 1
            && $queue->size('LocalBackupPresence') === 0 && DB::table('failed_jobs')->count() === $failedBefore, 'transaction crash recovered exact result');
        if ($phase === 'postcommit') {
            presenceCheck($final->getRawOriginal() === $snapshot, 'committed terminal evidence unchanged by redelivery');
        }
        $snapshot = $final->getRawOriginal();
        $dispatch->run($app);
        presenceCheck($queue->size('LocalBackupPresence') === 0 && $run->fresh()->getRawOriginal() === $snapshot, 'transaction crash terminal replay unchanged');
        echo match ($phase) {
            'precommit' => "F02_PRESENCE_PRECOMMIT_REDELIVERY_OK (SYNTHETIC_ONLY)\n",
            'timeout' => "F02_PRESENCE_WORKER_TIMEOUT_REDELIVERY_OK (SYNTHETIC_ONLY)\n",
            default => "F02_PRESENCE_POSTCOMMIT_REDELIVERY_OK (SYNTHETIC_ONLY)\n",
        };
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1, SIGKILL);
            }
        }
        foreach ([$marker, $timeoutMarker] as $ownedMarker) {
            if (is_file($ownedMarker)) {
                unlink($ownedMarker);
            }
        }
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('F02_SYNTHETIC_PRESENCE_HORIZON') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== '5' || getenv('REDIS_CACHE_DB') !== '6') {
    exit(2);
}

$mode = getenv('F02_PRESENCE_LOAD');
if ($mode !== false && ! in_array($mode, ['0', '1', '2', '3', '4', '5', '6'], true)) {
    exit(2);
}
$manual = $mode === '6';
$load = $mode === '1';
$crash = $mode === '2';
$transactionCrash = in_array($mode, ['3', '4', '5'], true);
$count = $load ? 100 : ($crash || $transactionCrash ? 1 : 2);

$failClosed = static function (Throwable $exception): never {
    $message = $exception->getMessage();
    fwrite(STDERR, ($exception instanceof RuntimeException && str_starts_with($message, 'F02 presence probe failed: ')
        ? $message : 'F02 presence probe failed: ' . get_class($exception)) . PHP_EOL);
    exit(1);
};
set_exception_handler($failClosed);
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler($failClosed);

$fixture = getenv('F02_PRESENCE_FIXTURE');
presenceCheck($app->environment('testing') && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === '5'
    && (string) config('database.redis.cache.database') === '6'
    && config('mail.default') === 'log' && config('rConfig.is_demo', false) === false, 'disposable configuration');
presenceCheck(is_string($fixture) && is_dir($fixture) && ! is_link($fixture)
    && realpath($fixture) === $fixture && str_starts_with(basename($fixture), 'rconfig-f02-presence.')
    && (fileperms($fixture) & 0777) === 0700
    && config('local-backup-presence.roots') === ['synthetic' => $fixture], 'private mapped fixture');
foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled'] as $flag) {
    presenceCheck(config('local-backup-presence.' . $flag) === true, 'local presence gate');
}
foreach (['manual_probe_enabled', 'manual_dispatch_enabled'] as $flag) {
    presenceCheck(config('local-backup-presence.' . $flag) === $manual, 'manual presence gate matches test mode');
}
presenceCheck(LocalBackupPresenceManualSession::count() === 0, 'no preexisting manual diagnostics');
foreach (['local-backup-presence.catchup_enabled', 'external-backups.runtime_enabled', 'external-backups.queue_enabled',
    'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled', 'rconfig-telegram.enabled',
    'rconfig-backup-email.enabled', 'rconfig-whatsapp.enabled'] as $flag) {
    presenceCheck(config($flag) === false, 'unrelated gate off');
}
presenceCheck((config('horizon.environments.production.HorizonLocalBackupPresence.queue') ?? []) === ['LocalBackupPresence'], 'actual supervisor configuration');
$queue = Queue::connection('redis_local_backup_presence');
presenceCheck($queue->size('LocalBackupPresence') === 0, 'isolated presence queue empty');
presenceCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 0, 'no other active presence rules');
$nativeQueues = ['PolicyCompliance', 'rConfigDefault', 'TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'];
foreach ($nativeQueues as $nativeQueue) {
    presenceCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'native queue empty');
}
$before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count()];
$devices = [];
$tasks = [];
$rules = [];
$folders = [];
$manualRules = [];
$manualTasks = [];
$manualSessions = [];
$master = null;
$failed = false;

try {
    foreach (range(1, $count) as $index) {
        $folders[$index] = $fixture . '/device-' . $index;
        presenceCheck(mkdir($folders[$index], 0700), 'synthetic directory');
        $devices[$index] = Device::factory()->create($load ? [
            'device_name' => 'F02-PRESENCE-CI-' . $index,
            'device_ip' => '192.0.2.' . $index,
        ] : []);
        $tasks[$index] = Task::factory()->create([
            'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
            'task_cron' => '* * * * *',
            'task_devices' => true,
            'task_categories' => false,
            'task_tags' => false,
            'task_email_notify' => false,
            'download_report_notify' => false,
            'is_system' => false,
            'is_paused' => 0,
        ]);
        $tasks[$index]->device()->attach($devices[$index]->id);
        $rules[$index] = LocalBackupPresenceRule::create([
            'task_id' => $tasks[$index]->id,
            'device_id' => $devices[$index]->id,
            'directory_alias' => 'synthetic',
            'relative_directory' => 'device-' . $index,
            'pattern_mode' => 'glob',
            'filename_pattern' => 'backup_*.cfg',
            'quiet_seconds' => ! $load && ! $crash && ! $transactionCrash && $index === 1 ? 2 : null,
            'deadline_seconds' => $crash || $transactionCrash ? 300 : ($load || $index === 1 ? 180 : 60),
        ]);
        $rules[$index]->enabled = true;
        $rules[$index]->activated_at_utc = CarbonImmutable::now('UTC')->subMinute()->startOfMinute()->format('Y-m-d H:i:s');
        $rules[$index]->save();
    }
    $bytes = "synthetic presence fixture\n";
    $files = [];
    foreach ($load ? $folders : [1 => $folders[1]] as $index => $folder) {
        $files[$index] = $folder . '/backup_final.cfg';
        presenceCheck(file_put_contents($files[$index], $bytes) === strlen($bytes), 'synthetic file');
        if ($load) {
            foreach (range(1, 24) as $noise) {
                presenceCheck(file_put_contents($folder . '/noise-' . $noise . '.txt', $bytes) === strlen($bytes), 'nonmatching synthetic file');
            }
        }
    }

    if ($manual) {
        // Separate dormant rules, sharing only the approved fixture folders.
        foreach ([1, 2] as $index) {
            $manualTasks[$index] = Task::factory()->create([
                'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
                'task_cron' => '* * * * *',
                'task_devices' => true,
                'task_categories' => false,
                'task_tags' => false,
                'task_email_notify' => false,
                'download_report_notify' => false,
                'verbose_download_report_notify' => false,
                'is_system' => false,
                'is_paused' => 1,
            ]);
            $manualTasks[$index]->device()->attach($devices[$index]->id);
            $manualRules[$index] = LocalBackupPresenceRule::create([
                'task_id' => $manualTasks[$index]->id,
                'device_id' => $devices[$index]->id,
                'directory_alias' => 'synthetic',
                'relative_directory' => 'device-' . $index,
                'pattern_mode' => 'glob',
                'filename_pattern' => 'backup_*.cfg',
                'quiet_seconds' => $index === 1 ? 2 : null,
                'deadline_seconds' => 180,
            ]);
        }
    }

    $schedule = $kernel->resolveConsoleSchedule();
    $find = static function (string $name) use ($schedule, $app) {
        $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === $name));
        presenceCheck(count($events) === 1 && $events[0]->isDue($app), 'actual named due event');

        return $events[0];
    };
    $reserve = $find('f02-local-presence-reserve-due');
    $dispatch = $find('f02-local-presence-reconcile-pending');
    $manualDispatch = $manual ? $find('f02-local-presence-reconcile-manual') : null;

    // Start close to a real minute boundary, leaving time for the first
    // observation before the shorter 60-second deadline. No frozen clock.
    $until = microtime(true) + 65;
    while (CarbonImmutable::now('UTC')->second > 3) {
        presenceCheck(microtime(true) < $until, 'wait for real minute boundary');
        usleep(100000);
    }
    $start = hrtime(true);
    $reserve->run($app);
    $reservationMillis = (hrtime(true) - $start) / 1000000;
    $runs = [];
    foreach ($rules as $index => $rule) {
        $runs[$index] = $rule->runs()->sole();
        presenceCheck($runs[$index]->status === 'pending'
            && $runs[$index]->getRawOriginal('scheduled_at_utc') === $runs[1]->getRawOriginal('scheduled_at_utc')
            && $runs[$index]->getRawOriginal('last_observed_at_utc') === null
            && (int) $runs[$index]->device_id === (int) $devices[$index]->id, 'durable unobserved occurrence');
    }
    presenceCheck($queue->size('LocalBackupPresence') === 0, 'same tick before queue handoff');
    $reserve->run($app);
    foreach ($rules as $rule) {
        presenceCheck($rule->runs()->count() === 1, 'reservation replay');
    }
    $start = hrtime(true);
    $dispatch->run($app);
    $dispatchMillis = (hrtime(true) - $start) / 1000000;
    $dispatch->run($app);
    presenceCheck($queue->size('LocalBackupPresence') === $count, 'exact jobs and cooldown replay');
    echo "F02_PRESENCE_DURABLE_SCHEDULE_OK (SYNTHETIC_ONLY)\n";

    if ($manual) {
        $sessions = app(LocalBackupPresenceManualSessions::class);
        foreach ($manualRules as $index => $rule) {
            $manualSessions[$index] = $sessions->start((int) $rule->id);
            presenceCheck($manualSessions[$index]->status === 'queued'
                && $sessions->start((int) $rule->id)->id === $manualSessions[$index]->id, 'manual admission replay');
        }
        $manualDispatch->run($app);
        $manualDispatch->run($app);
        presenceCheck($queue->size('LocalBackupPresence') === $count + 2, 'exact mixed queue and cooldown');
        foreach ($manualSessions as $session) {
            presenceCheck($session->fresh()->dispatch_not_before !== null
                && $session->fresh()->next_observation_at === $session->requested_at, 'manual dispatch reservation preserves due time');
        }
        echo "F02_MANUAL_MIXED_DISPATCH_OK (SYNTHETIC_ONLY)\n";
    }

    if ($crash) {
        presenceCrashProbe($root, $rules[1], $runs[1], $dispatch, $app);
    } elseif ($transactionCrash) {
        $phase = match ($mode) {
            '3' => 'precommit',
            '4' => 'postcommit',
            default => 'timeout',
        };
        presenceTransactionCrashProbe($root, $fixture, $phase, $rules[1], $runs[1], $dispatch, $app);
    } else {
        $master = new Process([PHP_BINARY, 'artisan', 'horizon', '--environment=production'], $root, null, null, 150);
        $workerStart = hrtime(true);
        $master->start();
        $await = static function (Closure $condition, string $label, int $timeout = 40) use ($master): void {
            $until = microtime(true) + $timeout;
            while (! $condition()) {
                presenceCheck($master->isRunning() && microtime(true) < $until, $label);
                usleep(200000);
            }
        };
        if ($load) {
            $ids = array_map(static fn ($run): int => (int) $run->id, $runs);
            $await(static fn (): bool => LocalBackupPresenceRun::whereIn('id', $ids)->where('status', 'present')->count() === $count
                && $queue->size('LocalBackupPresence') === 0, '100-rule queue drain', 50);
            $drainMillis = (hrtime(true) - $workerStart) / 1000000;
            presenceCheck($drainMillis <= 50000, '50-second candidate drain budget');
            $terminal = [];
            foreach ($runs as $index => $run) {
                $final = $run->fresh();
                presenceCheck($final->status === 'present'
                    && $final->reason === 'matching_file_observed'
                    && $final->matched_filename === 'backup_final.cfg'
                    && (int) $final->device_id === (int) $devices[$index]->id
                    && $final->getRawOriginal('last_observed_at_utc') < $final->getRawOriginal('deadline_at_utc')
                    && $final->stability_baseline === null, 'exact per-device load evidence');
                $terminal[$index] = $final->getRawOriginal();
            }
            $dispatch->run($app);
            presenceCheck($queue->size('LocalBackupPresence') === 0, 'load terminal replay queue empty');
            foreach ($runs as $index => $run) {
                presenceCheck($run->fresh()->getRawOriginal() === $terminal[$index], 'load terminal replay unchanged');
            }
            printf("F02_PRESENCE_LOAD_METRICS rules=%d files=%d reserve_ms=%.2f dispatch_ms=%.2f drain_ms=%.2f\n",
                $count, $count * 25, $reservationMillis, $dispatchMillis, $drainMillis);
            echo "F02_PRESENCE_100_RULE_HORIZON_OK (SYNTHETIC_ONLY)\n";
        } else {
            $await(static fn (): bool => $runs[1]->fresh()->reason === 'stability_wait'
                && $runs[2]->fresh()->reason === 'no_matching_file'
                && $queue->size('LocalBackupPresence') === 0, 'actual initial worker observations');
            presenceCheck($runs[1]->fresh()->status === 'pending' && $runs[2]->fresh()->status === 'pending', 'initial outcomes pending');
            if ($manual) {
                $await(static fn (): bool => $manualSessions[1]->fresh()->status === 'pending'
                    && $manualSessions[2]->fresh()->status === 'absent'
                    && $queue->size('LocalBackupPresence') === 0, 'actual initial manual observations');
                $pending = $manualSessions[1]->fresh();
                $negative = $manualSessions[2]->fresh();
                presenceCheck($pending->result['reason'] === 'stability_wait'
                    && is_array($pending->continuation)
                    && $pending->next_observation_at !== null
                    && $negative->result['reason'] === 'no_matching_file'
                    && $negative->continuation === null
                    && $negative->next_observation_at === null, 'manual pending and immediate absence');
                echo "F02_MANUAL_INITIAL_OBSERVATIONS_OK (SYNTHETIC_ONLY)\n";
            }
            $deadline = CarbonImmutable::parse($runs[2]->getRawOriginal('deadline_at_utc'), 'UTC');
            $await(static fn (): bool => CarbonImmutable::now('UTC')->greaterThanOrEqualTo($deadline->addSecond()), 'real deadline elapsed', 70);
            $dispatch->run($app);
            if ($manual) {
                // Real quiet interval and cooldown; no clock or DB manipulation.
                $await(static fn (): bool => time() >= max($pending->next_observation_at, $pending->dispatch_not_before), 'real manual continuation due', 65);
                $manualDispatch->run($app);
                $manualDispatch->run($app);
                $await(static fn (): bool => $manualSessions[1]->fresh()->status === 'present'
                    && $queue->size('LocalBackupPresence') === 0, 'actual manual stable completion');
                $positive = $manualSessions[1]->fresh();
                presenceCheck($positive->result['reason'] === 'stable_matching_file_observed'
                    && $positive->result['filename'] === 'backup_final.cfg'
                    && $positive->result['verified_success'] === false
                    && $positive->result['persistent_check_run'] === false
                    && $positive->continuation === null
                    && $positive->next_observation_at === null
                    && $positive->expires_at === $pending->expires_at
                    && $negative->fresh()->getRawOriginal() === $negative->getRawOriginal(), 'exact manual terminal evidence');
                echo "F02_MANUAL_REAL_HORIZON_STABILITY_OK (SYNTHETIC_ONLY)\n";

                Bus::dispatch(new LocalBackupPresenceManualObserveJob((int) $manualRules[1]->id, $positive->id));
                Bus::dispatch(new LocalBackupPresenceManualObserveJob((int) $manualRules[2]->id, $negative->id));
                $await(static fn (): bool => $queue->size('LocalBackupPresence') === 0, 'actual terminal duplicate drain');
                $manualDispatch->run($app);
                presenceCheck($queue->size('LocalBackupPresence') === 0
                    && $positive->fresh()->getRawOriginal() === $positive->getRawOriginal()
                    && $negative->fresh()->getRawOriginal() === $negative->getRawOriginal(), 'manual terminal replay unchanged');
                echo "F02_MANUAL_TERMINAL_REPLAY_OK (SYNTHETIC_ONLY)\n";
            }
            $await(static fn (): bool => $runs[1]->fresh()->status === 'present'
                && $runs[2]->fresh()->status === 'absent' && $queue->size('LocalBackupPresence') === 0, 'actual stable presence and deadline absence');
            $present = $runs[1]->fresh();
            $absent = $runs[2]->fresh();
            presenceCheck($present->reason === 'stable_matching_file_observed' && $present->matched_filename === 'backup_final.cfg'
                && $absent->reason === 'no_matching_file' && $absent->matched_filename === null
                && $present->stability_baseline === null && $absent->stability_baseline === null, 'exact terminal evidence');
            echo "F02_PRESENCE_REAL_HORIZON_MIXED_OUTCOMES_OK (SYNTHETIC_ONLY)\n";

            $dispatch->run($app);
            presenceCheck($queue->size('LocalBackupPresence') === 0
                && $present->fresh()->getRawOriginal() === $present->getRawOriginal()
                && $absent->fresh()->getRawOriginal() === $absent->getRawOriginal(), 'terminal reconciliation replay');
        }
    }
    foreach ($rules as $rule) {
        presenceCheck($rule->runs()->count() === 1, 'no extra occurrence');
    }
    foreach ($manualRules as $rule) {
        presenceCheck($rule->runs()->count() === 0 && $rule->fresh()->enabled === false, 'no scheduled manual occurrence or activation');
    }
    foreach ($nativeQueues as $nativeQueue) {
        presenceCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'no native work');
    }
    presenceCheck([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count()] === $before, 'no receipt or notification');
    foreach ($files as $file) {
        presenceCheck(file_get_contents($file) === $bytes, 'no matching file mutation');
    }
    if ($load) {
        foreach ($folders as $folder) {
            foreach (range(1, 24) as $noise) {
                presenceCheck(file_get_contents($folder . '/noise-' . $noise . '.txt') === $bytes, 'no nonmatching file mutation');
            }
        }
        echo "F02_PRESENCE_100_RULE_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
    }
    if ($crash) {
        echo "F02_PRESENCE_CRASH_CONCURRENCY_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
    }
    if ($transactionCrash) {
        echo "F02_PRESENCE_TRANSACTION_CRASH_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
    }
    if ($manual) {
        echo "F02_MANUAL_MIXED_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
    }
    echo "F02_PRESENCE_TERMINAL_REPLAY_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    $message = $exception->getMessage();
    fwrite(STDERR, ($exception instanceof RuntimeException && str_starts_with($message, 'F02 presence probe failed: ')
        ? $message : 'F02 presence probe failed: ' . get_class($exception)) . PHP_EOL);
} finally {
    if ($master !== null && $master->isRunning()) {
        $master->signal(SIGINT);
        $until = microtime(true) + 15;
        while ($master->isRunning() && microtime(true) < $until) {
            usleep(200000);
        }
        if ($master->isRunning()) {
            $master->stop(2);
            $failed = true;
        }
    }
    foreach ($rules as $rule) {
        LocalBackupPresenceRun::where('rule_id', $rule->id)->delete();
        $rule->delete();
    }
    foreach ($tasks as $task) {
        $task->device()->detach();
        $task->delete();
    }
    foreach ($manualRules as $rule) {
        $rule->delete();
    }
    foreach ($manualTasks as $task) {
        $task->device()->detach();
        $task->delete();
    }
    foreach ($devices as $device) {
        $device->delete();
    }
    foreach ($folders as $folder) {
        if (is_file($folder . '/backup_final.cfg')) {
            unlink($folder . '/backup_final.cfg');
        }
        if ($load) {
            foreach (range(1, 24) as $noise) {
                $path = $folder . '/noise-' . $noise . '.txt';
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        if (is_dir($folder)) {
            rmdir($folder);
        }
    }
}

exit($failed ? 1 : 0);
