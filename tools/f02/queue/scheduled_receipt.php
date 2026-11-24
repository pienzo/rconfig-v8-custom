<?php

/**
 * Hosted CI only. Select one actual scheduler event, then let real Horizon
 * consume it and its delayed follow-up. No production or provider access.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

function scheduledReceiptCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F02 scheduled receipt probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing'
    || getenv('F02_SYNTHETIC_HORIZON') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== '13'
    || getenv('REDIS_CACHE_DB') !== '14') {
    exit(2);
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$inbox = config('external-backups.intake_root');
$helper = config('external-backups.safe_digest_binary');
scheduledReceiptCheck($app->environment('testing')
    && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (int) config('database.redis.default.database') === 13
    && (int) config('database.redis.cache.database') === 14
    && config('mail.default') === 'log', 'disposable configuration');
scheduledReceiptCheck(is_string($inbox) && is_dir($inbox) && ! is_link($inbox)
    && realpath($inbox) === $inbox && str_starts_with(basename($inbox), 'rconfig-f02-horizon.')
    && (fileperms($inbox) & 0777) === 0700
    && is_string($helper) && is_executable($helper), 'private fixture and native helper');

foreach (['external-backups.runtime_enabled', 'external-backups.queue_enabled', 'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved'] as $flag) {
    scheduledReceiptCheck(config($flag) === true, 'synthetic F02 gate');
}
foreach (['custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled', 'rconfig-telegram.enabled', 'rconfig-backup-email.enabled', 'rconfig-whatsapp.enabled'] as $flag) {
    scheduledReceiptCheck(config($flag) === false, 'notification gate off');
}

$queue = Queue::connection('redis_external_backups');
scheduledReceiptCheck($queue->size('ExternalBackupChecks') === 0, 'isolated F02 queue empty');
foreach (['PolicyCompliance', 'rConfigDefault', 'TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'] as $nativeQueue) {
    scheduledReceiptCheck(Queue::connection('redis')->size($nativeQueue) === 0, 'isolated native queue empty');
}

$task = null;
$device = null;
$rule = null;
$folder = null;
$file = null;
$master = null;
$failed = false;

try {
    $device = Device::factory()->create();
    $task = Task::factory()->create([
        'task_command' => 'rconfig:check-external-backup',
        'task_cron' => '* * * * *',
        'task_devices' => true,
        'task_categories' => false,
        'task_tags' => false,
        'task_email_notify' => false,
        'download_report_notify' => false,
        'is_system' => false,
        'is_paused' => 0,
    ]);
    $task->device()->attach($device->id);
    $rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 2,
        'deadline_seconds' => 180,
        'clock_skew_seconds' => 5,
        'enabled' => true,
    ]);

    $folder = $inbox . '/' . $device->id;
    scheduledReceiptCheck(mkdir($folder, 0700), 'synthetic device directory');
    $file = $folder . '/synthetic_final.cfg';
    $bytes = "synthetic finalized backup\n";
    scheduledReceiptCheck(file_put_contents($folder . '/upload.tmp', $bytes) === strlen($bytes)
        && rename($folder . '/upload.tmp', $file), 'synthetic closed-file publish');

    // Resolve the real Kernel schedule, but execute ONLY the named F02 event.
    // Do not run unrelated native maintenance/download schedules.
    $schedule = $kernel->resolveConsoleSchedule();
    $name = 'f02-external-backup-' . $rule->id;
    $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === $name));
    scheduledReceiptCheck(count($events) === 1 && $events[0]->isDue($app), 'actual due F02 event');
    $event = $events[0];
    $event->run($app);
    scheduledReceiptCheck($queue->size('ExternalBackupChecks') === 1
        && ExternalBackupCheckRun::where('rule_id', $rule->id)->count() === 0, 'event queued, not executed inline');

    // The environment remains testing; select the actual production Horizon
    // supervisor configuration against the dedicated disposable Redis DBs.
    $master = new Process([PHP_BINARY, 'artisan', 'horizon', '--environment=production'], $root, null, null, 100);
    $master->start();

    $await = static function (Closure $condition, string $label) use ($master): void {
        $deadline = microtime(true) + 35;
        while (! $condition()) {
            scheduledReceiptCheck($master->isRunning() && microtime(true) < $deadline, $label);
            usleep(200000);
        }
    };
    $await(static fn (): bool => ExternalBackupCheckRun::where('rule_id', $rule->id)->where('status', 'success')->exists(), 'actual Horizon worker receipt');
    $await(static fn (): bool => $queue->size('ExternalBackupChecks') === 0, 'queue acknowledgement');

    $run = ExternalBackupCheckRun::where('rule_id', $rule->id)->sole();
    scheduledReceiptCheck($run->reason === 'verified_file_receipt'
        && ExternalBackupFileReceipt::where('check_run_id', $run->id)->count() === 1, 'verified receipt persisted');

    // Replay the same event instance, which retains its original UTC tick.
    $event->run($app);
    $await(static fn (): bool => $queue->size('ExternalBackupChecks') === 0, 'replay acknowledgement');
    scheduledReceiptCheck(ExternalBackupCheckRun::where('rule_id', $rule->id)->count() === 1
        && ExternalBackupFileReceipt::where('check_run_id', $run->id)->count() === 1
        && $run->fresh()->status === 'success', 'no duplicated receipt or occurrence');

    echo "F02 REAL_SCHEDULE_EVENT_HORIZON_NATIVE_RECEIPT_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'F02 scheduled receipt probe failed: ' . get_class($exception) . "\n");
} finally {
    if ($master !== null && $master->isRunning()) {
        $master->signal(SIGINT);
        $deadline = microtime(true) + 15;
        while ($master->isRunning() && microtime(true) < $deadline) {
            usleep(200000);
        }
        if ($master->isRunning()) {
            $master->stop(2);
            $failed = true;
        }
    }

    // Only remove fixtures owned by this probe after its worker shuts down.
    if ($rule !== null) {
        $runIds = ExternalBackupCheckRun::where('rule_id', $rule->id)->pluck('id');
        ExternalBackupFileReceipt::whereIn('check_run_id', $runIds)->delete();
        ExternalBackupCheckRun::where('rule_id', $rule->id)->delete();
        $rule->delete();
    }
    if ($task !== null) {
        $task->device()->detach();
        $task->delete();
    }
    if ($device !== null) {
        $device->delete();
    }
    if ($file !== null && is_file($file)) {
        unlink($file);
    }
    if ($folder !== null && is_dir($folder)) {
        if (is_file($folder . '/upload.tmp')) {
            unlink($folder . '/upload.tmp');
        }
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
