<?php

/**
 * Disposable hosted CI only. Real reconciliation, Redis and queue worker,
 * with a fake WhatsApp HTTP boundary and no network requests.
 */

use App\Models\CustomBackupNotificationTemplate;
use App\Models\ExternalBackupCheckRun;
use App\Services\Notifications\ExternalBackupNotificationReconciler;
use App\Services\Notifications\ExternalBackupTerminalOutcome;
use App\Services\Notifications\ExternalBackupWhatsAppDispatcher;
use App\Services\Notifications\WhatsAppTemplateDraftRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function mixedWhatsAppCheck(bool $ok, string $stage): void
{
    if ($ok === false) {
        throw new RuntimeException('F03 mixed whatsapp probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_MIXED_RECEIPT') !== '1'
    || getenv('F02_SYNTHETIC_MULTI_RECEIPT') !== '1' || getenv('F02_SYNTHETIC_SFTP_RECEIPT') !== '1'
    || getenv('F03_SYNTHETIC_MIXED_WHATSAPP') !== '1' || getenv('DB_HOST') !== '127.0.0.1'
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
        && str_starts_with($message, 'F03 mixed whatsapp probe failed: ')
        ? $message : 'F03 mixed whatsapp probe failed: ' . get_class($exception)) . PHP_EOL);
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

mixedWhatsAppCheck($app->environment('testing') && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && DB::connection()->getDatabaseName() === 'v8core_test'
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.database') === '7'
    && (string) config('database.redis.cache.database') === '8'
    && config('cache.default') === 'redis' && config('mail.default') === 'log'
    && config('rConfig.is_demo', false) === false, 'disposable database and cache');
foreach (['custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-whatsapp.enabled', 'rconfig-backup-email.enabled'] as $flag) {
    mixedWhatsAppCheck(config($flag) === false, 'initial notification gates off');
}
mixedWhatsAppCheck(config('external-backups.runtime_enabled') === true
    && config('external-backups.queue_enabled') === true, 'synthetic F02 gates');

$success = ExternalBackupCheckRun::findOrFail($successId);
$failure = ExternalBackupCheckRun::findOrFail($failureId);
mixedWhatsAppCheck($success->status === 'success' && $success->reason === 'verified_file_receipt'
    && $failure->status === 'failed' && $failure->reason === 'undersized'
    && $success->device_id !== $failure->device_id
    && $success->device()->value('device_name') === 'F02-CI-A'
    && $failure->device()->value('device_name') === 'F02-CI-B', 'exact mixed fixtures');
$cutoff = $success->getRawOriginal('scheduled_at_utc');
mixedWhatsAppCheck($cutoff === $failure->getRawOriginal('scheduled_at_utc'), 'same original tick');
$ids = [$successId, $failureId];
sort($ids);
$eligible = ExternalBackupTerminalOutcome::query()->where('runs.scheduled_at_utc', '>=', $cutoff)
    ->orderBy('runs.id')->pluck('runs.id')->map(static fn ($id): int => (int) $id)->all();
mixedWhatsAppCheck($eligible === $ids, 'no unrelated eligible outcomes');
mixedWhatsAppCheck(config('rconfig-whatsapp.policy_approved', false) === false, 'initial policy gate off');
mixedWhatsAppCheck(CustomBackupNotificationTemplate::where('channel', 'whatsapp-draft')->exists() === false, 'empty synthetic draft scope');
$allDeliveries = static fn () => DB::table('custom_backup_notification_deliveries')
    ->where('source_type', 'external-file')->whereIn('source_id', $ids);
$priorRows = $allDeliveries()->orderBy('id')->get()->all();
mixedWhatsAppCheck(count($priorRows) === 4
    && $allDeliveries()->where('channel', 'email')->where('status', 'accepted')->count() === 2
    && $allDeliveries()->where('channel', 'telegram')->whereIn('status', ['accepted', 'uncertain'])->count() === 2, 'prior channel scope');
$deliveries = static fn () => $allDeliveries()->where('channel', 'whatsapp')->orderBy('source_id');
$queue = Queue::connection('redis_backup_notifications');
mixedWhatsAppCheck($queue->size('BackupNotifications') === 0, 'empty isolated notification queue');

$drafts = [];
try {
    // Deliberately different draft field order from the configured approved
    // mapping: editing an unapproved local draft must never reroute a send.
    $draftTitle = 'CI draft not approved';
    $draftBody = 'Draft {{event.id}}: {{device.name}}';
    foreach (['backup.success', 'backup.failed'] as $kind) {
        $compiled = app(WhatsAppTemplateDraftRenderer::class)->compile($draftTitle, $draftBody, $kind);
        mixedWhatsAppCheck($compiled['provider_body'] === 'Draft {{1}}: {{2}}'
            && $compiled['fields'] === ['event.id', 'device.name'], 'draft positional compilation');
        $drafts[] = CustomBackupNotificationTemplate::create([
            'channel' => 'whatsapp-draft',
            'event_type' => $kind,
            'title' => $draftTitle,
            'body' => $draftBody,
        ]);
    }
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('custom-backup-notifications.f02_from_utc', $cutoff);
    $token = 'SYNTHETIC_NOT_A_REAL_META_TOKEN';
    $recipient = '15550100123';
    $endpoint = 'https://graph.facebook.com/v26.0/123456789012345/messages';
    config()->set('rconfig-whatsapp', [
        'enabled' => true,
        'policy_approved' => false,
        'from_utc' => $cutoff,
        'access_token' => $token,
        'phone_number_id' => '123456789012345',
        'recipient' => $recipient,
        'graph_version' => 'v26.0',
        'template_language' => 'it',
        'template_success' => 'ci_approved_success',
        'template_failed' => 'ci_approved_failed',
        'body_fields_success' => 'device.name,backup.status,event.id',
        'body_fields_failed' => 'backup.status,event.id,device.name',
    ]);
    $expected = [
        'ci_approved_success' => ['F02-CI-A', 'SUCCESS', 'external-file:' . $successId],
        'ci_approved_failed' => ['FAILED', 'external-file:' . $failureId, 'F02-CI-B'],
    ];
    $seen = [];
    Http::preventStrayRequests();
    Http::fake([$endpoint => static function (Request $request) use ($expected, $endpoint, $recipient, $token, &$seen) {
        $name = $request['template']['name'] ?? null;
        mixedWhatsAppCheck(is_string($name) && isset($expected[$name]), 'configured template name only');
        $parameters = array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $expected[$name]);
        mixedWhatsAppCheck($request->url() === $endpoint && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer ' . $token)
            && $request->data() == [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'template',
                'template' => [
                    'name' => $name,
                    'language' => ['code' => 'it'],
                    'components' => [['type' => 'body', 'parameters' => $parameters]],
                ],
            ], 'exact approved mapping payload');
        $seen[] = $name;

        return $name === 'ci_approved_success' ? Http::response(['messages' => [['id' => 'wamid.SYNTHETIC']]], 200)
            : Http::response(['error' => ['message' => 'synthetic']], 503);
    }]);
    $schedule = $kernel->resolveConsoleSchedule();
    $events = array_values(array_filter($schedule->events(), static fn ($event): bool => $event->description === 'f03-reconcile-external-backup-notifications'));
    mixedWhatsAppCheck(count($events) === 1 && $events[0]->isDue($app), 'actual notification schedule');
    $event = $events[0];
    $reconciler = app(ExternalBackupNotificationReconciler::class);
    $dispatcher = app(ExternalBackupWhatsAppDispatcher::class);
    $future = CarbonImmutable::parse($cutoff, 'UTC')->addSecond()->format('Y-m-d H:i:s');
    foreach ([[false, $cutoff], [true, 'invalid'], [true, $future]] as [$approved, $floor]) {
        config()->set('rconfig-whatsapp.policy_approved', $approved);
        config()->set('rconfig-whatsapp.from_utc', $floor);
        foreach ($ids as $id) {
            mixedWhatsAppCheck($reconciler->eligible($id, 'whatsapp') === false
                && $dispatcher->dispatch($id) === 'skipped', 'policy or activation floor refusal');
        }
        $event->run($app);
        mixedWhatsAppCheck($queue->size('BackupNotifications') === 0
            && $deliveries()->count() === 0 && $seen === [] && count(Http::recorded()) === 0, 'blocked scheduler and transport');
    }
    echo "F03_WHATSAPP_POLICY_CUTOFF_REFUSED_OK (SYNTHETIC_ONLY)\n";

    // Fixture attestation only; never evidence of Meta approval or consent.
    config()->set('rconfig-whatsapp.policy_approved', true);
    config()->set('rconfig-whatsapp.from_utc', $cutoff);
    $event->run($app);
    mixedWhatsAppCheck($queue->size('BackupNotifications') === 2 && $deliveries()->count() === 0 && $seen === [], 'two queued jobs before worker');
    $event->run($app);
    mixedWhatsAppCheck($queue->size('BackupNotifications') === 2, 'unique queued jobs');
    echo "F03_WHATSAPP_SCHEDULE_QUEUE_OK (SYNTHETIC_ONLY)\n";

    for ($index = 0; $index < 2; $index++) {
        mixedWhatsAppCheck($kernel->call('queue:work', [
            'connection' => 'redis_backup_notifications', '--queue' => 'BackupNotifications',
            '--once' => true, '--tries' => 1, '--timeout' => 60, '--sleep' => 1,
        ]) === 0, 'actual notification worker');
    }
    mixedWhatsAppCheck($queue->size('BackupNotifications') === 0, 'queue acknowledged');
    sort($seen);
    mixedWhatsAppCheck($seen === ['ci_approved_failed', 'ci_approved_success'] && count(Http::recorded()) === 2, 'exact two fake provider requests');
    $rows = $deliveries()->get();
    mixedWhatsAppCheck($rows->count() === 2
        && $rows->pluck('source_id')->map(static fn ($id): int => (int) $id)->all() === $ids, 'two distinct WhatsApp deliveries');
    foreach ($rows as $row) {
        $ok = (int) $row->source_id === $successId;
        mixedWhatsAppCheck($row->channel === 'whatsapp'
            && $row->status === ($ok ? 'accepted' : 'uncertain')
            && $row->event_type === ($ok ? 'backup.success' : 'backup.failed')
            && hash_equals(hash_hmac('sha256', 'whatsapp:' . $recipient, (string) config('app.key')), (string) $row->destination_key),
            'exact persisted WhatsApp identity');
    }
    echo "F04_WHATSAPP_DRAFT_ISOLATED_MAPPING_OK (SYNTHETIC_ONLY)\n";
    foreach ($ids as $id) {
        mixedWhatsAppCheck($reconciler->eligible($id, 'whatsapp') === false
            && $dispatcher->dispatch($id) === 'skipped', 'accepted and uncertain not eligible');
    }
    $event->run($app);
    mixedWhatsAppCheck($queue->size('BackupNotifications') === 0 && $deliveries()->get()->all() == $rows->all()
        && $allDeliveries()->whereIn('channel', ['email', 'telegram'])->orderBy('id')->get()->all() == $priorRows
        && $allDeliveries()->count() === 6 && count(Http::recorded()) === 2, 'replay and prior channels unchanged');
    echo "F03_WHATSAPP_ACCEPTED_UNCERTAIN_REPLAY_OK (SYNTHETIC_ONLY)\n";
} finally {
    foreach ($drafts as $draft) {
        $draft->delete();
    }
}
