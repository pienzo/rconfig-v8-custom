<?php

/**
 * Disposable hosted CI only. Real reconciliation, Redis and queue worker,
 * with the only enabled channel targeting an ephemeral loopback TLS fixture.
 */

use App\Models\CustomBackupNotificationTemplate;
use App\Models\ExternalBackupCheckRun;
use App\Services\Notifications\ExternalBackupNotificationReconciler;
use App\Services\Notifications\ExternalBackupTerminalOutcome;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function mixedEmailCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F03 mixed email probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_MIXED_RECEIPT') !== '1'
    || getenv('F02_SYNTHETIC_MULTI_RECEIPT') !== '1' || getenv('F02_SYNTHETIC_SFTP_RECEIPT') !== '1'
    || getenv('F03_SYNTHETIC_MIXED_EMAIL') !== '1' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'v8core_test' || getenv('REDIS_HOST') !== '127.0.0.1'
    || getenv('REDIS_DB') !== '7' || getenv('REDIS_CACHE_DB') !== '8' || $argc !== 4) {
    exit(2);
}

$port = filter_var($argv[1], FILTER_VALIDATE_INT);
$successId = filter_var($argv[2], FILTER_VALIDATE_INT);
$failureId = filter_var($argv[3], FILTER_VALIDATE_INT);
if ($port === false || $port < 1024 || $port > 65535 || $successId === false
    || $failureId === false || $successId < 1 || $failureId < 1 || $successId === $failureId) {
    exit(2);
}

$failClosed = static function (Throwable $exception): never {
    $message = $exception->getMessage();
    file_put_contents('php://stderr', ($exception instanceof RuntimeException
        && str_starts_with($message, 'F03 mixed email probe failed: ')
        ? $message : 'F03 mixed email probe failed: ' . get_class($exception)) . PHP_EOL);
    exit(1);
};
set_exception_handler($failClosed);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler($failClosed);

mixedEmailCheck($app->environment('testing') && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === '7'
    && (string) config('database.redis.cache.database') === '8'
    && config('cache.default') === 'redis' && config('mail.default') === 'log'
    && ! config('rConfig.is_demo', false), 'disposable database and cache');
foreach (['custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-whatsapp.enabled', 'rconfig-backup-email.enabled'] as $flag) {
    mixedEmailCheck(config($flag) === false, 'initial notification gates off');
}
mixedEmailCheck(config('external-backups.runtime_enabled') === true
    && config('external-backups.queue_enabled') === true, 'synthetic F02 gates');

$success = ExternalBackupCheckRun::findOrFail($successId);
$failure = ExternalBackupCheckRun::findOrFail($failureId);
mixedEmailCheck($success->status === 'success' && $success->reason === 'verified_file_receipt'
    && $failure->status === 'failed' && $failure->reason === 'undersized'
    && $success->device_id !== $failure->device_id
    && $success->device()->value('device_name') === 'F02-CI-A'
    && $failure->device()->value('device_name') === 'F02-CI-B', 'exact mixed fixtures');
$cutoff = $success->getRawOriginal('scheduled_at_utc');
mixedEmailCheck($cutoff === $failure->getRawOriginal('scheduled_at_utc'), 'same original tick');
$ids = [$successId, $failureId];
sort($ids);
$eligible = ExternalBackupTerminalOutcome::query()->where('runs.scheduled_at_utc', '>=', $cutoff)
    ->orderBy('runs.id')->pluck('runs.id')->map(static fn ($id): int => (int) $id)->all();
mixedEmailCheck($eligible === $ids, 'no unrelated eligible outcomes');
mixedEmailCheck(! CustomBackupNotificationTemplate::where('channel', 'email')->exists(), 'default email templates');
$deliveries = static fn () => DB::table('custom_backup_notification_deliveries')
    ->where('source_type', 'external-file')->whereIn('source_id', $ids)->orderBy('source_id');
mixedEmailCheck($deliveries()->count() === 0, 'no initial delivery rows');
$queue = Queue::connection('redis_backup_notifications');
mixedEmailCheck($queue->size('BackupNotifications') === 0, 'empty isolated notification queue');

config()->set('custom-backup-notifications.enabled', true);
config()->set('custom-backup-notifications.f02_from_utc', $cutoff);
config()->set('rconfig-backup-email', [
    'enabled' => true,
    'host' => '127.0.0.1',
    'port' => $port,
    'mode' => 'smtps',
    'username' => 'synthetic-user',
    'password' => 'synthetic-password',
    'from' => 'sender@example.test',
    'recipient' => 'recipient@example.test',
]);
$schedule = $kernel->resolveConsoleSchedule();
$events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === 'f03-reconcile-external-backup-notifications'));
mixedEmailCheck(count($events) === 1 && $events[0]->isDue($app), 'actual notification schedule');
$event = $events[0];
$event->run($app);
mixedEmailCheck($queue->size('BackupNotifications') === 2 && $deliveries()->count() === 0, 'two queued jobs before worker');
$event->run($app);
mixedEmailCheck($queue->size('BackupNotifications') === 2, 'unique queued jobs');
echo "F03_MIXED_SCHEDULE_QUEUE_OK (SYNTHETIC_ONLY)\n";

// The real worker resolves actual jobs/dispatchers in this guarded process.
// --once is bounded and retains the in-memory loopback-only configuration.
for ($index = 0; $index < 2; $index++) {
    mixedEmailCheck($kernel->call('queue:work', [
        'connection' => 'redis_backup_notifications', '--queue' => 'BackupNotifications',
        '--once' => true, '--tries' => 1, '--timeout' => 60, '--sleep' => 1,
    ]) === 0, 'actual notification worker');
}
mixedEmailCheck($queue->size('BackupNotifications') === 0, 'notification queue acknowledged');
$rows = $deliveries()->get();
mixedEmailCheck($rows->count() === 2, 'two persisted deliveries');
foreach ($rows as $row) {
    mixedEmailCheck($row->channel === 'email' && $row->status === 'accepted'
        && $row->event_type === ((int) $row->source_id === $successId ? 'backup.success' : 'backup.failed')
        && hash_equals(hash_hmac('sha256', 'email:recipient@example.test', (string) config('app.key')), (string) $row->destination_key),
        'exact accepted delivery identity');
}
$reconciler = app(ExternalBackupNotificationReconciler::class);
mixedEmailCheck(! $reconciler->eligible($successId, 'email') && ! $reconciler->eligible($failureId, 'email'), 'accepted not eligible');
$event->run($app);
mixedEmailCheck($queue->size('BackupNotifications') === 0 && $deliveries()->get()->all() == $rows->all(), 'accepted replay unchanged');
echo "F03_MIXED_EMAIL_LEDGER_REPLAY_OK (SYNTHETIC_ONLY)\n";
