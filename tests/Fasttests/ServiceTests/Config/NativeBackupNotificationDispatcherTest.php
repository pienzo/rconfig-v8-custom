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
