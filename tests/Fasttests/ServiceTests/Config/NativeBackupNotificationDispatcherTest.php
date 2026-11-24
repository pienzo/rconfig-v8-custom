<?php

use App\Jobs\NativeBackupNotificationJob;
use App\Services\Notifications\NativeBackupNotificationDispatcher;
use App\Services\Notifications\NativeBackupNotificationReconciler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    config()->set('rConfig.is_demo', false);
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('rconfig-native-backup-notifications.enabled', true);
    config()->set('rconfig-native-backup-notifications.from_utc', '2026-01-01 00:00:00');
    config()->set('rconfig-backup-email.enabled', false);
    config()->set('rconfig-telegram.enabled', true);
    config()->set('rconfig-telegram.bot_token', '123456:' . str_repeat('x', 35));
    config()->set('rconfig-telegram.chat_id', '-1001234567890');
    $this->outcomeId = DB::table('custom_native_backup_outcomes')->insertGetId([
        'source_config_id' => -1,
        'device_id' => 1,
        'device_name' => 'Demo <native>',
        'event_type' => 'backup.success',
        'filename' => 'synthetic.cfg',
        'duration_seconds' => 2,
        'occurred_at_utc' => '2026-01-01 00:00:00',
    ]);
    $this->dispatcher = app(NativeBackupNotificationDispatcher::class);
});

afterEach(function () {
    config()->set('rconfig-whatsapp.enabled', false);
    config()->set('rconfig-whatsapp.policy_approved', false);
    config()->set('rconfig-whatsapp.from_utc', '');
    if (isset($this->outcomeId)) {
        DB::table('custom_backup_notification_deliveries')->where('source_type', 'native-config')->where('source_id', $this->outcomeId)->delete();
        DB::table('custom_native_backup_outcomes')->where('id', $this->outcomeId)->delete();
    }
    config()->set('custom-backup-notifications.enabled', false);
    config()->set('rconfig-native-backup-notifications.enabled', false);
    config()->set('rconfig-telegram.enabled', false);
});

test('native persisted outcome sends through the shared renderer and claim only once', function () {
    expect($this->dispatcher->dispatch($this->outcomeId, 'telegram'))->toBe('accepted');
    expect($this->dispatcher->dispatch($this->outcomeId, 'telegram'))->toBe('skipped');
    config()->set('rconfig-telegram.chat_id', '-1001234567891');
    expect($this->dispatcher->dispatch($this->outcomeId, 'telegram'))->toBe('skipped');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Demo &lt;native&gt;')
        && str_contains($request['text'], 'SUCCESS') && str_contains($request['text'], 'synthetic.cfg'));
    expect(DB::table('custom_backup_notification_deliveries')->where('source_type', 'native-config')->where('source_id', $this->outcomeId)->count())->toBe(1);
});

test('native activation cutoff prevents old outcomes and disabled channels from sending', function () {
    config()->set('rconfig-native-backup-notifications.from_utc', '');
    expect($this->dispatcher->dispatch($this->outcomeId, 'telegram'))->toBe('skipped');
    config()->set('rconfig-native-backup-notifications.from_utc', '2026-01-02 00:00:00');
    expect($this->dispatcher->dispatch($this->outcomeId, 'telegram'))->toBe('skipped');
    expect($this->dispatcher->dispatch($this->outcomeId, 'email'))->toBe('skipped');
    Http::assertNothingSent();
});

test('scheduler enqueues only eligible native outcomes on the isolated queue', function () {
    Queue::fake();
    app(NativeBackupNotificationReconciler::class)->enqueue();
    Queue::assertPushed(NativeBackupNotificationJob::class, fn ($job): bool => $job->outcomeId === $this->outcomeId
        && $job->channel === 'telegram' && $job->queue === 'BackupNotifications');
    Queue::assertNotPushed(NativeBackupNotificationJob::class, fn ($job): bool => $job->channel === 'email');
    Http::assertNothingSent();
});

test('native WhatsApp requires its own activation floor and sends an approved template once', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    config()->set('rconfig-whatsapp.policy_approved', true);
    config()->set('rconfig-whatsapp.from_utc', '2026-01-01 00:00:00');
    config()->set('rconfig-whatsapp.access_token', 'EAA' . str_repeat('x', 55));
    config()->set('rconfig-whatsapp.phone_number_id', '123456789012345');
    config()->set('rconfig-whatsapp.recipient', '15550100123');
    config()->set('rconfig-whatsapp.graph_version', 'v26.0');
    config()->set('rconfig-whatsapp.template_language', 'it');
    config()->set('rconfig-whatsapp.template_success', 'backup_success_alert');
    config()->set('rconfig-whatsapp.template_failed', 'backup_failed_alert');
    config()->set('rconfig-whatsapp.body_fields_success', 'backup.status,event.id');
    config()->set('rconfig-whatsapp.body_fields_failed', 'backup.status,event.id');
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SYNTHETIC']]], 200)]);
    config()->set('rconfig-whatsapp.policy_approved', false);
    expect($this->dispatcher->dispatch($this->outcomeId, 'whatsapp'))->toBe('skipped');
    config()->set('rconfig-whatsapp.policy_approved', true);
    config()->set('rconfig-whatsapp.from_utc', '2026-01-02 00:00:00');
    expect($this->dispatcher->dispatch($this->outcomeId, 'whatsapp'))->toBe('skipped');
    Http::assertNothingSent();

    config()->set('rconfig-whatsapp.from_utc', '2026-01-01 00:00:00');
    expect($this->dispatcher->dispatch($this->outcomeId, 'whatsapp'))->toBe('accepted');
    expect($this->dispatcher->dispatch($this->outcomeId, 'whatsapp'))->toBe('skipped');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
        && $request['template']['name'] === 'backup_success_alert'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'SUCCESS'
        && $request['template']['components'][0]['parameters'][1]['text'] === 'native-config:' . $this->outcomeId);
});
