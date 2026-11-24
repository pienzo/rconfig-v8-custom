<?php

/**
 * Hosted CI only. Select actual scheduler events, then let real Horizon
 * consume them and their delayed follow-ups. No production or provider access.
 */

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupReceiptTokenFactory;
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
$multi = getenv('F02_SYNTHETIC_MULTI_RECEIPT') === '1';
$redisDb = $multi ? '9' : ($sftp ? '11' : '13');
$cacheDb = $multi ? '10' : ($sftp ? '12' : '14');
if (($multi && ! $sftp) || getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing'
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

$tasks = [];
$devices = [];
$rules = [];
$files = [];
$identities = [];
$expectedTokens = [];
$master = null;
$failed = false;
$deliveryCount = $sftp ? DB::table('custom_backup_notification_deliveries')->count() : 0;

try {
    foreach (range(1, $multi ? 2 : 1) as $index) {
        $device = Device::factory()->create();
        $devices[$index] = $device;
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
        $tasks[$index] = $task;
        $task->device()->attach($device->id);
        $rules[$index] = ExternalBackupRule::create([
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
        $files[$index] = $inbox . '/' . $device->id . '/' . ($sftp ? 'backup.cfg' : 'synthetic_final.cfg');
    }

    // Resolve the real scheduler tick before transport, so crossing a minute
    // boundary during the two uploads cannot make the first file stale.
    $schedule = $kernel->resolveConsoleSchedule();
    $events = [];
    foreach ($rules as $index => $rule) {
        $name = 'f02-external-backup-' . $rule->id;
        $matches = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === $name));
        scheduledReceiptCheck(count($matches) === 1 && $matches[0]->isDue($app), 'actual due F02 event');
        $events[$index] = $matches[0];
    }

    if ($sftp) {
        $arguments = [
            'docker', 'run', '--rm', '--init', '--network', 'none',
            '--security-opt', 'no-new-privileges',
            '--env', 'GITHUB_ACTIONS=true', '--env', 'F02_SFTP_DISPOSABLE=1',
            '--env', 'F02_SFTP_EXPORT=1',
            '--env', 'F02_SFTP_DEVICE=' . $devices[1]->id,
            '--env', 'F02_SFTP_READER_GID=' . posix_getegid(),
        ];
        if ($multi) {
            array_push($arguments, '--env', 'F02_SFTP_LIVE_LOCK=1',
                '--env', 'F02_SFTP_MULTI_DEVICE=1', '--env', 'F02_SFTP_MULTI_EXPORT=1',
                '--env', 'F02_SFTP_DEVICE_B=' . $devices[2]->id);
        }
        array_push($arguments, '--mount', 'type=bind,source=' . $inbox . ',target=/handoff-export',
            'rconfig-f02-sftp-ci');
        $transport = new Process($arguments, $root, null, null, 90);
        $transport->mustRun();
        $lines = explode("\n", $transport->getOutput());
        foreach ([
            'F02_REAL_SFTP_QUARANTINE_OK (SYNTHETIC_ONLY)',
            'F02_SFTP_ISOLATED_HANDOFF_OK (DESCRIPTOR_ONLY_NOT_BACKUP_SUCCESS)',
        ] as $marker) {
            scheduledReceiptCheck(in_array($marker, $lines, true), 'actual SFTP fixture marker');
        }
        scheduledReceiptCheck(count(array_keys($lines, 'F02_SFTP_NATIVE_DESCRIPTOR_OK (NO_BACKUP_SUCCESS)', true)) === count($devices), 'actual native transport descriptor count');
        if ($multi) {
            foreach ([
                'F02_SFTP_MULTI_DEVICE_INDEPENDENT_TRANSFER_OK (SYNTHETIC_ONLY)',
                'F02_SFTP_MULTI_DEVICE_ISOLATED_PUBLICATION_OK (SYNTHETIC_ONLY)',
            ] as $marker) {
                scheduledReceiptCheck(in_array($marker, $lines, true), 'actual multi-device transport marker');
            }
        }
        foreach ($files as $index => $file) {
            $folder = dirname($file);
            clearstatcache();
            scheduledReceiptCheck(is_dir($folder) && is_link($folder) === false
                && fileowner($folder) === 0 && filegroup($folder) === posix_getegid()
                && (fileperms($folder) & 0777) === 0750 && is_writable($folder) === false
                && is_file($file) && is_link($file) === false
                && fileowner($file) === 0 && filegroup($file) === posix_getegid()
                && (fileperms($file) & 0777) === 0440 && is_readable($file)
                && is_writable($file) === false, 'published file read-only for worker');
            $identity = lstat($file);
            scheduledReceiptCheck(is_array($identity) && $identity['nlink'] === 1, 'single published inode');
            $identities[$index] = $identity;
            $expected = $index === 1 ? "SYNTHETIC-F02-FINAL-CONTENT\n" : "SYNTHETIC-F02-SECOND-DEVICE-DISTINCT-CONTENT\n";
            // Independently derive the exact expected receipt token from the
            // real protected descriptor and known synthetic bytes. Do not log it.
            $native = new Process([$helper, $inbox, (string) $devices[$index]->id, 'backup.cfg',
                (string) $identity['dev'], (string) $identity['ino'], (string) $identity['size'],
                (string) $identity['mtime'], (string) $identity['ctime'], '1', '1048576'], $root, null, null, 12);
            $native->mustRun();
            $evidence = json_decode($native->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            scheduledReceiptCheck(is_array($evidence) && ($evidence['sha256'] ?? null) === hash('sha256', $expected)
                && ($evidence['size'] ?? null) === strlen($expected), 'exact native fixture evidence');
            $expectedTokens[$index] = $app->make(ExternalBackupReceiptTokenFactory::class)
                ->fromDescriptorEvidence((int) $devices[$index]->id, 'backup.cfg', $evidence);
        }
        if ($multi) {
            scheduledReceiptCheck($expectedTokens[1] !== $expectedTokens[2]
                && [$identities[1]['dev'], $identities[1]['ino']] !== [$identities[2]['dev'], $identities[2]['ino']], 'distinct device tokens and inodes');
        }
        echo "F02_SFTP_PUBLISHED_INODE_READY (SYNTHETIC_ONLY)\n";
    } else {
        $file = $files[1];
        $folder = dirname($file);
        scheduledReceiptCheck(mkdir($folder, 0700), 'synthetic device directory');
        $bytes = "synthetic finalized backup\n";
        scheduledReceiptCheck(file_put_contents($folder . '/upload.tmp', $bytes) === strlen($bytes)
            && rename($folder . '/upload.tmp', $file), 'synthetic closed-file publish');
    }

    $ruleIds = array_values(array_map(static fn ($rule): int => (int) $rule->id, $rules));
    foreach ($events as $event) {
        $event->run($app);
    }
    scheduledReceiptCheck($queue->size('ExternalBackupChecks') === count($rules)
        && ExternalBackupCheckRun::whereIn('rule_id', $ruleIds)->count() === 0, 'events queued, not executed inline');
    if ($multi) {
        echo "F02_MULTI_DEVICE_EVENTS_QUEUED_OK (SYNTHETIC_ONLY)\n";
    }

    // Existing single-worker supervisor configuration, dedicated test Redis.
    $master = new Process([PHP_BINARY, 'artisan', 'horizon', '--environment=production'], $root, null, null, 100);
    $master->start();
    $await = static function (Closure $condition, string $label) use ($master): void {
        $deadline = microtime(true) + 45;
        while (! $condition()) {
            scheduledReceiptCheck($master->isRunning() && microtime(true) < $deadline, $label);
            usleep(200000);
        }
    };
    $await(static fn (): bool => ExternalBackupCheckRun::whereIn('rule_id', $ruleIds)->where('status', 'success')->count() === count($rules), 'actual Horizon worker receipts');
    $await(static fn (): bool => $queue->size('ExternalBackupChecks') === 0, 'queue acknowledgement');

    $verify = static function () use ($rules, $devices, $sftp, $expectedTokens, $files, $identities, $deliveryCount): array {
        $receiptIds = [];
        foreach ($rules as $index => $rule) {
            $run = ExternalBackupCheckRun::where('rule_id', $rule->id)->sole();
            $receipt = ExternalBackupFileReceipt::where('check_run_id', $run->id)->sole();
            scheduledReceiptCheck($run->status === 'success' && $run->reason === 'verified_file_receipt'
                && (int) $run->device_id === (int) $devices[$index]->id
                && (int) $receipt->device_id === (int) $devices[$index]->id, 'verified device receipt persisted');
            $receiptIds[$index] = [(int) $run->id, (int) $receipt->id];
            if ($sftp) {
                clearstatcache(true, $files[$index]);
                $current = lstat($files[$index]);
                scheduledReceiptCheck(is_array($current), 'published inode still present');
                foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
                    scheduledReceiptCheck($current[$key] === $identities[$index][$key], 'published inode unchanged');
                }
                scheduledReceiptCheck($run->matched_filename === 'backup.cfg'
                    && hash_equals($expectedTokens[$index], (string) $receipt->verified_file_token), 'exact SFTP device receipt token');
            }
        }
        if ($sftp) {
            scheduledReceiptCheck(DB::table('custom_backup_notification_deliveries')->count() === $deliveryCount, 'no new notification');
        }

        return $receiptIds;
    };
    $receiptIds = $verify();
    if ($multi) {
        echo "F02_MULTI_DEVICE_EXACT_RECEIPTS_OK (SYNTHETIC_ONLY)\n";
    }

    // Replay original scheduler event instances with original UTC ticks.
    foreach ($events as $event) {
        $event->run($app);
    }
    $await(static fn (): bool => $queue->size('ExternalBackupChecks') === 0, 'replay acknowledgement');
    scheduledReceiptCheck($verify() === $receiptIds, 'no duplicated receipt or occurrence');
    if ($multi) {
        echo "F02_MULTI_DEVICE_RECEIPT_REPLAY_OK (SYNTHETIC_ONLY)\n";
    }
    if ($sftp) {
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

    foreach ($rules as $rule) {
        $runIds = ExternalBackupCheckRun::where('rule_id', $rule->id)->pluck('id');
        ExternalBackupFileReceipt::whereIn('check_run_id', $runIds)->delete();
        ExternalBackupCheckRun::where('rule_id', $rule->id)->delete();
        $rule->delete();
    }
    foreach ($tasks as $task) {
        $task->device()->detach();
        $task->delete();
    }
    foreach ($devices as $device) {
        $device->delete();
    }
    if (! $sftp) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
            $folder = dirname($file);
            if (is_dir($folder)) {
                if (is_file($folder . '/upload.tmp')) {
                    unlink($folder . '/upload.tmp');
                }
                rmdir($folder);
            }
        }
    }
}

exit($failed ? 1 : 0);
