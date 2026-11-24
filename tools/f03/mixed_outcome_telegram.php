<?php

/**
 * Disposable hosted CI only. Real reconciliation, Redis and queue worker,
 * with a fake Telegram HTTP boundary and no network requests.
 */

use App\Models\CustomBackupNotificationTemplate;
use App\Models\ExternalBackupCheckRun;
use App\Services\Notifications\ExternalBackupNotificationReconciler;
use App\Services\Notifications\ExternalBackupTerminalOutcome;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Queue;

function mixedTelegramCheck(bool $ok, string $stage): void
{
    if (! $ok) {
        throw new RuntimeException('F03 mixed telegram probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_MIXED_RECEIPT') !== '1'
    || getenv('F02_SYNTHETIC_MULTI_RECEIPT') !== '1' || getenv('F02_SYNTHETIC_SFTP_RECEIPT') !== '1'
    || getenv('F03_SYNTHETIC_MIXED_TELEGRAM') !== '1' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'v8core_test' || getenv('REDIS_HOST') !== '127.0.0.1'
    || getenv('REDIS_DB') !== '7' || getenv('REDIS_CACHE_DB') !== '8' || $argc !== 3) {
    exit(2);
}

$successId = filter_var($argv[1], FILTER_VALIDATE_INT);
$failureId = filter_var($argv[2], FILTER_VALIDATE_INT);
if ($successId === false
    || $failureId === false || $successId < 1 || $failureId < 1 || $successId === $failureId) {
    exit(2);
}

$failClosed = static function (Throwable $exception): never {
    $message = $exception->getMessage();
    file_put_contents('php://stderr', ($exception instanceof RuntimeException
        && str_starts_with($message, 'F03 mixed telegram probe failed: ')
        ? $message : 'F03 mixed telegram probe failed: ' . get_class($exception)) . PHP_EOL);
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

mixedTelegramCheck($app->environment('testing') && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === '7'
    && (string) config('database.redis.cache.database') === '8'
    && config('cache.default') === 'redis' && config('mail.default') === 'log'
    && ! config('rConfig.is_demo', false), 'disposable database and cache');
foreach (['custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-whatsapp.enabled', 'rconfig-backup-email.enabled'] as $flag) {
    mixedTelegramCheck(config($flag) === false, 'initial notification gates off');
}
mixedTelegramCheck(config('external-backups.runtime_enabled') === true
    && config('external-backups.queue_enabled') === true, 'synthetic F02 gates');

$success = ExternalBackupCheckRun::findOrFail($successId);
$failure = ExternalBackupCheckRun::findOrFail($failureId);
mixedTelegramCheck($success->status === 'success' && $success->reason === 'verified_file_receipt'
    && $failure->status === 'failed' && $failure->reason === 'undersized'
    && $success->device_id !== $failure->device_id
    && $success->device()->value('device_name') === 'F02-CI-A'
    && $failure->device()->value('device_name') === 'F02-CI-B', 'exact mixed fixtures');
$cutoff = $success->getRawOriginal('scheduled_at_utc');
mixedTelegramCheck($cutoff === $failure->getRawOriginal('scheduled_at_utc'), 'same original tick');
$ids = [$successId, $failureId];
sort($ids);
$eligible = ExternalBackupTerminalOutcome::query()->where('runs.scheduled_at_utc', '>=', $cutoff)
    ->orderBy('runs.id')->pluck('runs.id')->map(static fn ($id): int => (int) $id)->all();
mixedTelegramCheck($eligible === $ids, 'no unrelated eligible outcomes');
mixedTelegramCheck(! CustomBackupNotificationTemplate::where('channel', 'telegram')->exists(), 'empty synthetic template scope');
$allDeliveries = static fn () => DB::table('custom_backup_notification_deliveries')
    ->where('source_type', 'external-file')->whereIn('source_id', $ids);
$emailRows = $allDeliveries()->where('channel', 'email')->orderBy('source_id')->get()->all();
mixedTelegramCheck(count($emailRows) === 2 && $allDeliveries()->count() === 2, 'prior email scope');
$deliveries = static fn () => $allDeliveries()->where('channel', 'telegram')->orderBy('source_id');
$queue = Queue::connection('redis_backup_notifications');
mixedTelegramCheck($queue->size('BackupNotifications') === 0, 'empty isolated notification queue');

$templates = [];
try {
    foreach (['backup.success', 'backup.failed'] as $kind) {
        $templates[] = CustomBackupNotificationTemplate::create([
            'channel' => 'telegram',
            'event_type' => $kind,
            'title' => '<ci> {{device.name}} {{backup.status}}',
            'body' => '{{event.id}} | {{backup.filename}} | {{device.ip}} | {{backup.error}}',
        ]);
    }
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('custom-backup-notifications.f02_from_utc', $cutoff);
    $token = '123456789:' . str_repeat('X', 35);
    $chat = '-1000000000001';
    config()->set('rconfig-telegram', [
        'enabled' => true,
        'bot_token' => $token,
        'chat_id' => $chat,
    ]);
    $endpoint = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $expected = [
        "<b>&lt;ci&gt; F02-CI-A SUCCESS</b>\nexternal-file:" . $successId . ' | backup.cfg | — | —' => 'success',
        "<b>&lt;ci&gt; F02-CI-B FAILED</b>\nexternal-file:" . $failureId . ' | backup.cfg | — | External backup check failed' => 'failed',
    ];
    $seen = [];
    // Exact synthetic URL only. No external HTTP is permitted by this client.
    Http::preventStrayRequests();
    Http::fake([$endpoint => static function (Request $request) use ($expected, $endpoint, $chat, &$seen) {
        $text = $request['text'];
        mixedTelegramCheck($request->url() === $endpoint && $request->method() === 'POST'
            && $request['chat_id'] === $chat && $request['parse_mode'] === 'HTML'
            && $request['link_preview_options'] === ['is_disabled' => true]
            && $request['protect_content'] === true
            && is_string($text) && isset($expected[$text]), 'exact safe Telegram request');
        $kind = $expected[$text];
        $seen[] = $kind;

        return $kind === 'success' ? Http::response(['ok' => true], 200)
            : Http::response(['ok' => false], 503);
    }]);
    $schedule = $kernel->resolveConsoleSchedule();
    $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === 'f03-reconcile-external-backup-notifications'));
    mixedTelegramCheck(count($events) === 1 && $events[0]->isDue($app), 'actual notification schedule');
    $event = $events[0];
    $event->run($app);
    mixedTelegramCheck($queue->size('BackupNotifications') === 2 && $deliveries()->count() === 0 && $seen === [], 'two queued jobs before worker');
    $event->run($app);
    mixedTelegramCheck($queue->size('BackupNotifications') === 2, 'unique queued jobs');
    echo "F03_TELEGRAM_SCHEDULE_QUEUE_OK (SYNTHETIC_ONLY)\n";

    for ($index = 0; $index < 2; $index++) {
        mixedTelegramCheck($kernel->call('queue:work', [
            'connection' => 'redis_backup_notifications', '--queue' => 'BackupNotifications',
            '--once' => true, '--tries' => 1, '--timeout' => 60, '--sleep' => 1,
        ]) === 0, 'actual notification worker');
    }
    mixedTelegramCheck($queue->size('BackupNotifications') === 0, 'queue acknowledged');
    sort($seen);
    mixedTelegramCheck($seen === ['failed', 'success'] && count(Http::recorded()) === 2, 'exact two fake provider requests');
    $rows = $deliveries()->get();
    mixedTelegramCheck($rows->count() === 2
        && $rows->pluck('source_id')->map(static fn ($id): int => (int) $id)->all() === $ids, 'two distinct Telegram deliveries');
    foreach ($rows as $row) {
        $ok = (int) $row->source_id === $successId;
        mixedTelegramCheck($row->channel === 'telegram'
            && $row->status === ($ok ? 'accepted' : 'uncertain')
            && $row->event_type === ($ok ? 'backup.success' : 'backup.failed')
            && hash_equals(hash_hmac('sha256', 'telegram:' . $chat, (string) config('app.key')), (string) $row->destination_key),
            'exact persisted Telegram identity');
    }
    echo "F04_TELEGRAM_STORED_TEMPLATE_PAYLOAD_OK (SYNTHETIC_ONLY)\n";
    $reconciler = app(ExternalBackupNotificationReconciler::class);
    mixedTelegramCheck(! $reconciler->eligible($successId, 'telegram') && ! $reconciler->eligible($failureId, 'telegram'), 'accepted and uncertain not eligible');
    $event->run($app);
    mixedTelegramCheck($queue->size('BackupNotifications') === 0 && $deliveries()->get()->all() == $rows->all()
        && $allDeliveries()->where('channel', 'email')->orderBy('source_id')->get()->all() == $emailRows
        && $allDeliveries()->count() === 4 && count(Http::recorded()) === 2, 'replay and other channel unchanged');
    echo "F03_TELEGRAM_ACCEPTED_UNCERTAIN_REPLAY_OK (SYNTHETIC_ONLY)\n";
} finally {
    foreach ($templates as $template) {
        $template->delete();
    }
}
