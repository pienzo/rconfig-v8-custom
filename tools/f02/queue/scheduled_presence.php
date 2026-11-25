<?php

/**
 * Hosted CI only: real scheduler callbacks and Horizon, synthetic files.
 * No production access, providers, native downloads or verified receipts.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

function presenceCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F02 presence probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('F02_SYNTHETIC_PRESENCE_HORIZON') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== '5' || getenv('REDIS_CACHE_DB') !== '6') {
    exit(2);
}

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
$master = null;
$failed = false;

try {
    foreach ([1, 2] as $index) {
        $folders[$index] = $fixture . '/device-' . $index;
        presenceCheck(mkdir($folders[$index], 0700), 'synthetic directory');
        $devices[$index] = Device::factory()->create();
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
            'quiet_seconds' => $index === 1 ? 2 : null,
            'deadline_seconds' => $index === 1 ? 180 : 60,
        ]);
        $rules[$index]->enabled = true;
        $rules[$index]->activated_at_utc = CarbonImmutable::now('UTC')->subMinute()->startOfMinute()->format('Y-m-d H:i:s');
        $rules[$index]->save();
    }
    $file = $folders[1] . '/backup_final.cfg';
    $bytes = "synthetic presence fixture\n";
    presenceCheck(file_put_contents($file, $bytes) === strlen($bytes), 'synthetic file');

    $schedule = $kernel->resolveConsoleSchedule();
    $find = static function (string $name) use ($schedule, $app) {
        $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === $name));
        presenceCheck(count($events) === 1 && $events[0]->isDue($app), 'actual named due event');

        return $events[0];
    };
    $reserve = $find('f02-local-presence-reserve-due');
    $dispatch = $find('f02-local-presence-reconcile-pending');

    // Start close to a real minute boundary, leaving time for the first
    // observation before the shorter 60-second deadline. No frozen clock.
    $until = microtime(true) + 65;
    while (CarbonImmutable::now('UTC')->second > 3) {
        presenceCheck(microtime(true) < $until, 'wait for real minute boundary');
        usleep(100000);
    }
    $reserve->run($app);
    $runs = [];
    foreach ($rules as $index => $rule) {
        $runs[$index] = $rule->runs()->sole();
        presenceCheck($runs[$index]->status === 'pending'
            && $runs[$index]->getRawOriginal('last_observed_at_utc') === null
            && (int) $runs[$index]->device_id === (int) $devices[$index]->id, 'durable unobserved occurrence');
    }
    presenceCheck($runs[1]->getRawOriginal('scheduled_at_utc') === $runs[2]->getRawOriginal('scheduled_at_utc')
        && $queue->size('LocalBackupPresence') === 0, 'same tick before queue handoff');
    $reserve->run($app);
    foreach ($rules as $rule) {
        presenceCheck($rule->runs()->count() === 1, 'reservation replay');
    }
    $dispatch->run($app);
    $dispatch->run($app);
    presenceCheck($queue->size('LocalBackupPresence') === 2, 'two jobs and cooldown replay');
    echo "F02_PRESENCE_DURABLE_SCHEDULE_OK (SYNTHETIC_ONLY)\n";

    $master = new Process([PHP_BINARY, 'artisan', 'horizon', '--environment=production'], $root, null, null, 150);
    $master->start();
    $await = static function (Closure $condition, string $label, int $timeout = 40) use ($master): void {
        $until = microtime(true) + $timeout;
        while (! $condition()) {
            presenceCheck($master->isRunning() && microtime(true) < $until, $label);
            usleep(200000);
        }
    };
    $await(static fn (): bool => $runs[1]->fresh()->reason === 'stability_wait'
        && $runs[2]->fresh()->reason === 'no_matching_file'
        && $queue->size('LocalBackupPresence') === 0, 'actual initial worker observations');
    presenceCheck($runs[1]->fresh()->status === 'pending' && $runs[2]->fresh()->status === 'pending', 'initial outcomes pending');
    $deadline = CarbonImmutable::parse($runs[2]->getRawOriginal('deadline_at_utc'), 'UTC');
    $await(static fn (): bool => CarbonImmutable::now('UTC')->greaterThanOrEqualTo($deadline->addSecond()), 'real deadline elapsed', 70);
    $dispatch->run($app);
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
    foreach ($rules as $rule) {
        presenceCheck($rule->runs()->count() === 1, 'no extra occurrence');
    }
    foreach ($nativeQueues as $nativeQueue) {
        presenceCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'no native work');
    }
    presenceCheck([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count()] === $before
        && file_get_contents($file) === $bytes, 'no receipt notification or file mutation');
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
    foreach ($devices as $device) {
        $device->delete();
    }
    foreach ($folders as $folder) {
        if (is_file($folder . '/backup_final.cfg')) {
            unlink($folder . '/backup_final.cfg');
        }
        if (is_dir($folder)) {
            rmdir($folder);
        }
    }
}

exit($failed ? 1 : 0);
