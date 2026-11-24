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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

function scheduledReceiptFailure(Throwable $exception): void
{
    $message = $exception->getMessage();
    $label = $exception instanceof RuntimeException && str_starts_with($message, 'F02 scheduled receipt probe failed: ')
        ? $message : 'F02 scheduled receipt probe failed: ' . get_class($exception);
    file_put_contents('php://stderr', $label . PHP_EOL);
}

function scheduledReceiptCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F02 scheduled receipt probe failed: ' . $stage);
    }
}

$sftp = getenv('F02_SYNTHETIC_SFTP_RECEIPT') === '1';
$redisDb = $sftp ? '11' : '13';
$cacheDb = $sftp ? '12' : '14';
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing'
    || getenv('F02_SYNTHETIC_HORIZON') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== $redisDb
    || getenv('REDIS_CACHE_DB') !== $cacheDb) {
    exit(2);
}

// Laravel's CLI renderer can return zero for uncaught exceptions outside
// Artisan. Install our fail-closed handler again after framework bootstrap.
$failClosed = static function (Throwable $exception): never {
    scheduledReceiptFailure($exception);
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

$inbox = config('external-backups.intake_root');
$helper = config('external-backups.safe_digest_binary');
scheduledReceiptCheck($app->environment('testing')
    && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === $redisDb
    && (string) config('database.redis.cache.database') === $cacheDb
    && config('mail.default') === 'log', 'disposable configuration');
scheduledReceiptCheck(is_string($inbox) && is_dir($inbox) && ! is_link($inbox)
    && realpath($inbox) === $inbox && str_starts_with(basename($inbox), 'rconfig-f02-horizon.')
    && (fileperms($inbox) & 0777) === ($sftp ? 0750 : 0700)
    && is_string($helper) && is_executable($helper), 'private fixture and native helper');

if ($sftp) {
    scheduledReceiptCheck(getenv('RUNNER_ENVIRONMENT') === 'github-hosted', 'hosted SFTP runner');
    scheduledReceiptCheck(dirname($inbox) === '/var/lib' && posix_geteuid() !== 0, 'SFTP reader identity and location');
    foreach (['/', '/var', '/var/lib', $inbox] as $ancestor) {
        scheduledReceiptCheck(is_dir($ancestor) && is_link($ancestor) === false
            && realpath($ancestor) === $ancestor && fileowner($ancestor) === 0
            && (fileperms($ancestor) & 0022) === 0
            && is_writable($ancestor) === false, 'protected publication ancestor ' . ($ancestor === $inbox ? 'inbox' : $ancestor));
    }
    scheduledReceiptCheck(filegroup($inbox) === posix_getegid(), 'publication reader group');
}

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
$publishedIdentity = null;
$deliveryCount = $sftp ? DB::table('custom_backup_notification_deliveries')->count() : 0;

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
        'filename_pattern' => $sftp ? 'backup.cfg' : 'synthetic_*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 2,
        'deadline_seconds' => 180,
        'clock_skew_seconds' => 5,
        'enabled' => true,
    ]);

    $folder = $inbox . '/' . $device->id;
    if ($sftp) {
        // Same published inode crosses the mount; PHP cannot rewrite it.
        $file = $folder . '/backup.cfg';
        $transport = new Process([
            'docker', 'run', '--rm', '--init', '--network', 'none',
            '--security-opt', 'no-new-privileges',
            '--env', 'GITHUB_ACTIONS=true', '--env', 'F02_SFTP_DISPOSABLE=1',
            '--env', 'F02_SFTP_EXPORT=1',
            '--env', 'F02_SFTP_DEVICE=' . $device->id,
            '--env', 'F02_SFTP_READER_GID=' . posix_getegid(),
            '--mount', 'type=bind,source=' . $inbox . ',target=/handoff-export',
            'rconfig-f02-sftp-ci',
        ], $root, null, null, 60);
        $transport->mustRun();
        $output = $transport->getOutput();
        foreach ([
            'F02_REAL_SFTP_QUARANTINE_OK (SYNTHETIC_ONLY)',
            'F02_SFTP_NATIVE_DESCRIPTOR_OK (NO_BACKUP_SUCCESS)',
            'F02_SFTP_ISOLATED_HANDOFF_OK (DESCRIPTOR_ONLY_NOT_BACKUP_SUCCESS)',
        ] as $marker) {
            scheduledReceiptCheck(in_array($marker, explode("\n", $output), true), 'actual SFTP fixture marker');
        }
        clearstatcache();
        scheduledReceiptCheck(is_dir($folder) && is_link($folder) === false
            && fileowner($folder) === 0 && filegroup($folder) === posix_getegid()
            && (fileperms($folder) & 0777) === 0750 && is_writable($folder) === false
            && is_file($file) && is_link($file) === false
            && fileowner($file) === 0 && filegroup($file) === posix_getegid()
            && (fileperms($file) & 0777) === 0440 && is_readable($file)
            && is_writable($file) === false, 'published file read-only for worker');
        $publishedIdentity = lstat($file);
        scheduledReceiptCheck(is_array($publishedIdentity) && $publishedIdentity['nlink'] === 1, 'single published inode');
        echo "F02_SFTP_PUBLISHED_INODE_READY (SYNTHETIC_ONLY)\n";
    } else {
        scheduledReceiptCheck(mkdir($folder, 0700), 'synthetic device directory');
        $file = $folder . '/synthetic_final.cfg';
        $bytes = "synthetic finalized backup\n";
        scheduledReceiptCheck(file_put_contents($folder . '/upload.tmp', $bytes) === strlen($bytes)
            && rename($folder . '/upload.tmp', $file), 'synthetic closed-file publish');
    }

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

    if ($sftp) {
        clearstatcache(true, $file);
        $current = lstat($file);
        scheduledReceiptCheck(is_array($current) && is_array($publishedIdentity), 'published inode still present');
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            scheduledReceiptCheck($current[$key] === $publishedIdentity[$key], 'published inode unchanged');
        }
        scheduledReceiptCheck($run->matched_filename === 'backup.cfg'
            && DB::table('custom_backup_notification_deliveries')->count() === $deliveryCount, 'SFTP receipt and no new notification');
        echo "F02_REAL_SFTP_SCHEDULE_HORIZON_RECEIPT_OK (SYNTHETIC_ONLY)\n";
    }
    echo "F02 REAL_SCHEDULE_EVENT_HORIZON_NATIVE_RECEIPT_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    scheduledReceiptFailure($exception);
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
    if ($sftp === false && $file !== null && is_file($file)) {
        unlink($file);
    }
    if ($sftp === false && $folder !== null && is_dir($folder)) {
        if (is_file($folder . '/upload.tmp')) {
            unlink($folder . '/upload.tmp');
        }
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
