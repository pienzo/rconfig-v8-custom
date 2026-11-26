<?php

/** Hosted disposable CI only: actual manual job SQL and Redis ACK boundaries. */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

function manualCrashCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F02 manual crash probe failed: ' . $stage);
    }
}

$mode = getenv('F02_PRESENCE_LOAD');
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('F02_SYNTHETIC_PRESENCE_HORIZON') !== '1' || getenv('APP_ENV') !== 'testing'
    || ! in_array($mode, ['7', '8', '10'], true)
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== '5' || getenv('REDIS_CACHE_DB') !== '6') {
    exit(2);
}

$failClosed = static function (Throwable $exception): never {
    $message = $exception->getMessage();
    fwrite(STDERR, ($exception instanceof RuntimeException && str_starts_with($message, 'F02 manual crash probe failed: ')
        ? $message : 'F02 manual crash probe failed: ' . get_class($exception)) . PHP_EOL);
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
manualCrashCheck($app->environment('testing') && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === '5'
    && (string) config('database.redis.cache.database') === '6'
    && config('queue.connections.redis_local_backup_presence.retry_after') === 120
    && config('queue.connections.redis_local_backup_presence.connection') === 'default'
    && config('mail.default') === 'log' && config('rConfig.is_demo', false) === false, 'disposable configuration');
manualCrashCheck(is_string($fixture) && is_dir($fixture) && ! is_link($fixture)
    && realpath($fixture) === $fixture && str_starts_with(basename($fixture), 'rconfig-f02-presence.')
    && (fileperms($fixture) & 0777) === 0700
    && config('local-backup-presence.roots') === ['synthetic' => $fixture]
    && scandir($fixture) === ['.', '..'], 'private empty fixture');
foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled', 'manual_probe_enabled', 'manual_dispatch_enabled'] as $flag) {
    manualCrashCheck(config('local-backup-presence.' . $flag) === true, 'manual presence gate');
}
foreach (['local-backup-presence.catchup_enabled', 'external-backups.runtime_enabled', 'external-backups.queue_enabled',
    'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled', 'rconfig-telegram.enabled',
    'rconfig-backup-email.enabled', 'rconfig-whatsapp.enabled'] as $flag) {
    manualCrashCheck(config($flag) === false, 'unrelated gate off');
}
$queue = Queue::connection('redis_local_backup_presence');
$redis = Redis::connection('default');
$reservedKey = 'queues:LocalBackupPresence:reserved';
manualCrashCheck($queue->size('LocalBackupPresence') === 0 && LocalBackupPresenceManualSession::count() === 0
    && LocalBackupPresenceRule::where('enabled', true)->count() === 0, 'isolated queue and sessions');
$nativeQueues = ['PolicyCompliance', 'rConfigDefault', 'TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'];
foreach ($nativeQueues as $nativeQueue) {
    manualCrashCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'native queue empty');
}
$integrity = static fn (): array => [
    LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
    DB::table('custom_backup_notification_deliveries')->count(), DB::table('failed_jobs')->count(),
];
$before = $integrity();
$phase = match ($mode) {
    '7' => 'precommit',
    '8' => 'postcommit',
    '10' => 'timeout',
};
$pendingWrite = in_array($phase, ['precommit', 'timeout'], true);
$folder = $fixture . '/manual-device';
$file = $folder . '/backup_final.cfg';
$marker = $fixture . '/transaction-barrier';
$timeoutMarker = $fixture . '/timeout-event';
$bytes = "synthetic manual crash fixture\n";
$device = null;
$rule = null;
$children = [];
$failed = false;

try {
    manualCrashCheck(mkdir($folder, 0700) && file_put_contents($file, $bytes) === strlen($bytes), 'synthetic file');
    $device = Device::factory()->create();
    $lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $rule = $lifecycle->create((int) $device->id, 'Synthetic manual crash', '* * * * *', [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'manual-device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => $pendingWrite ? 2 : null,
        'deadline_seconds' => 420,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ]);
    $ruleBefore = $rule->getRawOriginal();
    $session = app(LocalBackupPresenceManualSessions::class)->start((int) $rule->id);
    $schedule = $kernel->resolveConsoleSchedule();
    $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === 'f02-local-presence-reconcile-manual'));
    manualCrashCheck(count($events) === 1 && $events[0]->isDue($app), 'actual manual reconciliation callback');
    $dispatch = $events[0];
    $dispatch->run($app);
    manualCrashCheck($queue->size('LocalBackupPresence') === 1, 'one real queued observation');
    $snapshot = $session->fresh()->getRawOriginal();

    $command = [PHP_BINARY, $root . '/tools/f02/queue/presence_transaction_worker.php'];
    $child = new Process([...$command, $phase, $session->id], $root, null, null, 40);
    $children[] = $child;
    $started = hrtime(true);
    $child->start();
    $until = microtime(true) + 15;
    while (! is_file($marker) || trim((string) file_get_contents($marker)) !== $phase) {
        manualCrashCheck($child->isRunning() && microtime(true) < $until, 'actual manual SQL barrier');
        usleep(100000);
    }
    manualCrashCheck($child->isRunning(), 'owned worker at barrier');
    $reserved = $redis->zrange($reservedKey, 0, -1);
    manualCrashCheck(count($reserved) === 1, 'actual reserved job');
    $original = json_decode($reserved[0], true, 512, JSON_THROW_ON_ERROR);
    $lease = $redis->zscore($reservedKey, $reserved[0]);
    manualCrashCheck(is_string($original['uuid'] ?? null) && ($original['attempts'] ?? null) === 1
        && is_numeric($lease) && (int) $lease > time() && (int) $lease <= time() + 120, 'original lease');
    $visible = $session->fresh();
    if ($pendingWrite) {
        manualCrashCheck($visible->getRawOriginal() === $snapshot, 'uncommitted baseline invisible');
    } else {
        manualCrashCheck($visible->status === 'present' && $visible->result['reason'] === 'matching_file_observed'
            && $visible->result['filename'] === 'backup_final.cfg' && $visible->continuation === null
            && $visible->next_observation_at === null, 'terminal committed before acknowledgement');
    }
    $visibleSnapshot = $visible->getRawOriginal();
    if ($phase === 'timeout') {
        // No signal from the parent: require Laravel's own alarm and event.
        while ($child->isRunning()) {
            manualCrashCheck((hrtime(true) - $started) / 1000000000 < 40, 'manual worker self-termination deadline');
            usleep(100000);
        }
        $elapsed = (hrtime(true) - $started) / 1000000000;
        manualCrashCheck(! $child->isSuccessful() && $elapsed >= 29 && $elapsed < 40
            && is_file($timeoutMarker)
            && trim((string) file_get_contents($timeoutMarker)) === $original['uuid'] . ':1', 'actual manual JobTimedOut and autonomous exit');
        printf("F02_MANUAL_TIMEOUT_METRICS elapsed_seconds=%.3f\n", $elapsed);
    } else {
        $child->signal(SIGKILL);
        $child->wait();
    }
    manualCrashCheck(! $child->isSuccessful() && $session->fresh()->getRawOriginal() === $visibleSnapshot
        && $queue->size('LocalBackupPresence') === 1 && count($redis->zrange($reservedKey, 0, -1)) === 1, 'state survives interruption');
    echo match ($phase) {
        'precommit' => "F02_MANUAL_PRECOMMIT_ROLLBACK_OK (SYNTHETIC_ONLY)\n",
        'timeout' => "F02_MANUAL_TIMEOUT_ROLLBACK_OK (SYNTHETIC_ONLY)\n",
        'postcommit' => "F02_MANUAL_POSTCOMMIT_PREACK_OK (SYNTHETIC_ONLY)\n",
    };

    manualCrashCheck(unlink($marker), 'remove owned barrier');
    $until = microtime(true) + 125;
    while (time() <= (int) $lease) {
        manualCrashCheck(microtime(true) < $until, 'actual retry lease elapsed');
        usleep(200000);
    }
    $retry = new Process([...$command, 'replay', $session->id], $root, null, null, 40);
    $children[] = $retry;
    $retry->run();
    manualCrashCheck($retry->isSuccessful() && is_file($marker)
        && trim((string) file_get_contents($marker)) === $original['uuid'] . ':2'
        && $queue->size('LocalBackupPresence') === 0, 'same Redis message acknowledged at attempt two');

    $replayed = $session->fresh();
    if ($pendingWrite) {
        manualCrashCheck($replayed->status === 'pending' && $replayed->result['reason'] === 'stability_wait'
            && is_array($replayed->continuation) && $replayed->next_observation_at !== null
            && $replayed->expires_at === $session->expires_at, 'durable baseline recovered');
        echo $phase === 'timeout'
            ? "F02_MANUAL_TIMEOUT_REDELIVERY_OK (SYNTHETIC_ONLY)\n"
            : "F02_MANUAL_PRECOMMIT_REDELIVERY_OK (SYNTHETIC_ONLY)\n";
        $until = microtime(true) + 15;
        while (time() < max($replayed->next_observation_at, $replayed->dispatch_not_before)) {
            manualCrashCheck(microtime(true) < $until, 'real continuation due');
            usleep(100000);
        }
        $dispatch->run($app);
        manualCrashCheck($queue->size('LocalBackupPresence') === 1, 'actual continuation queued');
        $continuation = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis_local_backup_presence',
            '--queue=LocalBackupPresence', '--once', '--sleep=0', '--timeout=30', '--tries=2', '--no-interaction'], $root, null, null, 40);
        $children[] = $continuation;
        $continuation->run();
        $final = $session->fresh();
        manualCrashCheck($continuation->isSuccessful() && $final->status === 'present'
            && $final->result['reason'] === 'stable_matching_file_observed'
            && $final->result['filename'] === 'backup_final.cfg' && $final->continuation === null
            && $final->next_observation_at === null && $final->expires_at === $session->expires_at, 'stable completion after recovery');
        echo $phase === 'timeout'
            ? "F02_MANUAL_TIMEOUT_STABILITY_OK (SYNTHETIC_ONLY)\n"
            : "F02_MANUAL_CRASH_STABILITY_OK (SYNTHETIC_ONLY)\n";
    } else {
        $final = $replayed;
        manualCrashCheck($final->getRawOriginal() === $visibleSnapshot, 'terminal replay immutable');
        echo "F02_MANUAL_POSTCOMMIT_REDELIVERY_OK (SYNTHETIC_ONLY)\n";
    }
    $dispatch->run($app);
    manualCrashCheck($queue->size('LocalBackupPresence') === 0 && $session->fresh()->getRawOriginal() === $final->getRawOriginal()
        && $final->result['verified_success'] === false && $final->result['persistent_check_run'] === false
        && $rule->fresh()->getRawOriginal() === $ruleBefore && $rule->runs()->count() === 0
        && $integrity() === $before && file_get_contents($file) === $bytes, 'terminal and unrelated evidence unchanged');
    foreach ($nativeQueues as $nativeQueue) {
        manualCrashCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'no native work');
    }
    echo $phase === 'timeout'
        ? "F02_MANUAL_TIMEOUT_INTEGRITY_OK (SYNTHETIC_ONLY)\n"
        : "F02_MANUAL_CRASH_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    $message = $exception->getMessage();
    fwrite(STDERR, ($exception instanceof RuntimeException && str_starts_with($message, 'F02 manual crash probe failed: ')
        ? $message : 'F02 manual crash probe failed: ' . get_class($exception)) . PHP_EOL);
} finally {
    foreach ($children as $child) {
        if ($child->isRunning()) {
            $child->stop(1, SIGKILL);
        }
    }
    if ($rule !== null) {
        app(LocalBackupPresenceRuleLifecycle::class)->delete((int) $rule->id);
    }
    if ($device !== null) {
        $device->delete();
    }
    foreach ([$marker, $timeoutMarker, $file] as $owned) {
        if (is_file($owned)) {
            unlink($owned);
        }
    }
    if (is_dir($folder)) {
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
