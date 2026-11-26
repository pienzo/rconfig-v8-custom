<?php

/** Hosted disposable CI only: stale manual UUIDs after restart and expiry. */

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
    || $mode !== '11'
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
$folder = $fixture . '/manual-device';
$file = $folder . '/backup_final.cfg';
$marker = $fixture . '/transaction-barrier';
$readyKey = 'queues:LocalBackupPresence';
$bytes = "synthetic manual restart fixture\n";
$device = null;
$rules = [];
$children = [];
$failed = false;
$baselineCounts = [Device::count(), LocalBackupPresenceRule::count(), LocalBackupPresenceManualSession::count(), DB::table('tasks')->count()];
$ruleStates = [];

try {
    manualCrashCheck(mkdir($folder, 0700) && file_put_contents($file, $bytes) === strlen($bytes), 'synthetic file');
    $device = Device::factory()->create(['device_name' => 'F02-MANUAL-REPLAY-CI', 'device_ip' => '192.0.2.102']);
    $lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $sessions = app(LocalBackupPresenceManualSessions::class);
    $criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'manual-device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => null,
        'deadline_seconds' => 420,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ];
    $makeRule = static function (int $ttl) use ($lifecycle, $device, $criteria, &$rules, &$ruleStates): LocalBackupPresenceRule {
        $criteria['deadline_seconds'] = $ttl;
        $rule = $lifecycle->create((int) $device->id, 'Synthetic manual replay ' . $ttl, '* * * * *', $criteria);
        $rules[] = $rule;
        $ruleStates[$rule->id] = [$rule->getRawOriginal(), $rule->task()->firstOrFail()->getRawOriginal()];

        return $rule;
    };
    $schedule = $kernel->resolveConsoleSchedule();
    $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === 'f02-local-presence-reconcile-manual'));
    manualCrashCheck(count($events) === 1 && $events[0]->isDue($app), 'actual manual reconciliation callback');
    $dispatch = $events[0];
    $ready = static fn (): array => $redis->lrange($readyKey, 0, -1);
    $payloadFor = static function (string $raw, string $sessionId): array {
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        manualCrashCheck(is_string($payload['uuid'] ?? null)
            && ($payload['data']['commandName'] ?? null) === App\Jobs\LocalBackupPresenceManualObserveJob::class
            && is_string($payload['data']['command'] ?? null)
            && str_contains($payload['data']['command'], $sessionId), 'exact manual payload identity');

        return $payload;
    };
    $runOnce = static function () use ($root, &$children): void {
        $child = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis_local_backup_presence',
            '--queue=LocalBackupPresence', '--once', '--sleep=0', '--timeout=30', '--tries=2', '--no-interaction'], $root, null, null, 40);
        $children[] = $child;
        $child->run();
        manualCrashCheck($child->isSuccessful(), 'real one-job worker exit');
    };
    $assertPresent = static function (LocalBackupPresenceManualSession $session): void {
        $final = $session->fresh();
        manualCrashCheck($final !== null && $final->status === 'present'
            && $final->result['reason'] === 'matching_file_observed'
            && $final->result['filename'] === 'backup_final.cfg'
            && $final->result['verified_success'] === false && $final->result['persistent_check_run'] === false
            && $final->continuation === null && $final->next_observation_at === null
            && $final->expires_at === $session->expires_at, 'new diagnostic completes without backup success');
    };

    // Real reserved message at postcommit/preack: replace its terminal session
    // while the interrupted worker's original lease remains in Redis.
    $rule = $makeRule(420);
    $old = $sessions->start((int) $rule->id);
    $dispatch->run($app);
    $queued = $ready();
    manualCrashCheck(count($queued) === 1 && $queue->size('LocalBackupPresence') === 1, 'one old queued observation');
    $original = $payloadFor($queued[0], $old->id);
    $command = [PHP_BINARY, $root . '/tools/f02/queue/presence_transaction_worker.php'];
    $child = new Process([...$command, 'postcommit', $old->id], $root, null, null, 40);
    $children[] = $child;
    $child->start();
    $until = microtime(true) + 15;
    while (! is_file($marker) || trim((string) file_get_contents($marker)) !== 'postcommit') {
        manualCrashCheck($child->isRunning() && microtime(true) < $until, 'actual postcommit barrier');
        usleep(100000);
    }
    $reserved = $redis->zrange($reservedKey, 0, -1);
    manualCrashCheck(count($reserved) === 1 && $ready() === [], 'reserved old message');
    $held = $payloadFor($reserved[0], $old->id);
    $lease = $redis->zscore($reservedKey, $reserved[0]);
    manualCrashCheck($held['uuid'] === $original['uuid'] && $held['attempts'] === 1
        && is_numeric($lease) && (int) $lease > time() && (int) $lease <= time() + 120
        && $old->fresh()->status === 'present' && $child->isRunning(), 'terminal result and original lease');
    $child->signal(SIGKILL);
    $child->wait();
    manualCrashCheck(! $child->isSuccessful() && $child->hasBeenSignaled() && $child->getTermSignal() === SIGKILL
        && $redis->zrange($reservedKey, 0, -1) === $reserved, 'owned worker interrupted before ack');
    $new = $sessions->restart((int) $rule->id, $old->id);
    $snapshot = $new->getRawOriginal();
    manualCrashCheck($new->id !== $old->id && $old->fresh() === null && $new->status === 'queued'
        && $new->result === null && $redis->zrange($reservedKey, 0, -1) === $reserved, 'terminal replacement while old message reserved');
    echo "F02_MANUAL_RESTART_RESERVED_OK (SYNTHETIC_ONLY)\n";

    manualCrashCheck(unlink($marker), 'remove owned barrier');
    $until = microtime(true) + 125;
    while (time() <= (int) $lease) {
        manualCrashCheck(microtime(true) < $until, 'actual retry lease elapsed');
        usleep(200000);
    }
    $retry = new Process([...$command, 'replay', $old->id], $root, null, null, 40);
    $children[] = $retry;
    $retry->run();
    manualCrashCheck($retry->isSuccessful() && is_file($marker)
        && trim((string) file_get_contents($marker)) === $original['uuid'] . ':2'
        && $queue->size('LocalBackupPresence') === 0 && $old->fresh() === null
        && $new->fresh()->getRawOriginal() === $snapshot, 'same old message acknowledged without touching replacement');
    echo "F02_MANUAL_RESTART_REDELIVERY_OK (SYNTHETIC_ONLY)\n";
    $dispatch->run($app);
    $queued = $ready();
    manualCrashCheck(count($queued) === 1 && $payloadFor($queued[0], $new->id)['uuid'] !== $original['uuid'], 'new session independently dispatched');
    $runOnce();
    $assertPresent($new);
    manualCrashCheck($queue->size('LocalBackupPresence') === 0 && unlink($marker), 'restart queue drained');
    $restartTerminal = $new->fresh()->getRawOriginal();
    echo "F02_MANUAL_RESTART_NEW_OBSERVATION_OK (SYNTHETIC_ONLY)\n";

    // A genuinely expired queued session, with its old payload still ready.
    $expiryRule = $makeRule(60);
    $expired = $sessions->start((int) $expiryRule->id);
    $dispatch->run($app);
    $queued = $ready();
    manualCrashCheck(count($queued) === 1, 'one expiring payload queued');
    $expiryPayload = $payloadFor($queued[0], $expired->id);
    $until = microtime(true) + 65;
    while (time() < $expired->expires_at) {
        manualCrashCheck(microtime(true) < $until, 'real expiry deadline');
        usleep(100000);
    }
    $replacement = $sessions->start((int) $expiryRule->id);
    manualCrashCheck($replacement->id !== $expired->id && $expired->fresh() === null
        && $replacement->requested_at >= $expired->expires_at && $ready() === $queued, 'expired UUID replaced, old payload remains');
    $dispatch->run($app);
    $both = $ready();
    manualCrashCheck(count($both) === 2 && $both[0] === $queued[0]
        && $payloadFor($both[1], $replacement->id)['uuid'] !== $expiryPayload['uuid'], 'old and new payloads in FIFO order');
    $replacementSnapshot = $replacement->fresh()->getRawOriginal();
    $runOnce();
    manualCrashCheck($ready() === [$both[1]] && $queue->size('LocalBackupPresence') === 1
        && $redis->zrange($reservedKey, 0, -1) === [] && $expired->fresh() === null
        && $replacement->fresh()->getRawOriginal() === $replacementSnapshot, 'old queued message acknowledged without changing new UUID');
    $runOnce();
    $assertPresent($replacement);
    echo "F02_MANUAL_EXPIRY_REPLAY_OK (SYNTHETIC_ONLY)\n";

    $dispatch->run($app);
    manualCrashCheck($queue->size('LocalBackupPresence') === 0 && $ready() === []
        && $redis->zrange($reservedKey, 0, -1) === [] && $new->fresh()->getRawOriginal() === $restartTerminal
        && $integrity() === $before && file_get_contents($file) === $bytes, 'no extra work or evidence mutation');
    foreach ($rules as $ownedRule) {
        $fresh = $ownedRule->fresh();
        manualCrashCheck([$fresh->getRawOriginal(), $fresh->task()->firstOrFail()->getRawOriginal()] === $ruleStates[$ownedRule->id]
            && $fresh->runs()->count() === 0, 'dormant rule and task unchanged');
    }
    foreach ($nativeQueues as $nativeQueue) {
        manualCrashCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'no native work');
    }
} catch (Throwable $exception) {
    $failed = true;
    $message = $exception->getMessage();
    fwrite(STDERR, ($exception instanceof RuntimeException && str_starts_with($message, 'F02 manual crash probe failed: ')
        ? $message : 'F02 manual restart replay probe failed: ' . get_class($exception)) . PHP_EOL);
} finally {
    try {
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop(1, SIGKILL);
            }
        }
        foreach ($rules as $ownedRule) {
            app(LocalBackupPresenceRuleLifecycle::class)->delete((int) $ownedRule->id);
        }
        if ($device !== null) {
            $device->delete();
        }
        foreach ([$marker, $file] as $owned) {
            if (is_file($owned)) {
                unlink($owned);
            }
        }
        if (is_dir($folder)) {
            manualCrashCheck(rmdir($folder), 'owned directory removed');
        }
        manualCrashCheck([Device::count(), LocalBackupPresenceRule::count(), LocalBackupPresenceManualSession::count(), DB::table('tasks')->count()] === $baselineCounts
            && $integrity() === $before && $queue->size('LocalBackupPresence') === 0
            && scandir($fixture) === ['.', '..'], 'owned fixture cleanup');
    } catch (Throwable $exception) {
        $failed = true;
        fwrite(STDERR, 'F02 manual restart replay cleanup failed: ' . get_class($exception) . PHP_EOL);
    }
}
if (! $failed) {
    echo "F02_MANUAL_RESTART_REPLAY_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
}

exit($failed ? 1 : 0);
