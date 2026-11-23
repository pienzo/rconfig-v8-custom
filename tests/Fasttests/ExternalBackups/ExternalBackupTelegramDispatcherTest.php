<?php

use App\Models\CustomBackupNotificationTemplate;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\Notifications\ExternalBackupTelegramDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('rconfig-telegram.enabled', true);
    config()->set('rconfig-telegram.bot_token', '123456:' . str_repeat('x', 35));
    config()->set('rconfig-telegram.chat_id', '-1001234567890');

    $this->device = Device::factory()->create(['device_name' => 'DEMO <switch>']);
    $this->task = Task::factory()->create(['task_command' => 'rconfig:check-external-backup']);
    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
        'enabled' => true,
    ]);
    $this->run = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-23 20:00:00',
        'deadline_at_utc' => '2026-11-23 20:02:00',
        'last_observed_at_utc' => '2026-11-23 20:02:00',
        'status' => 'failed',
        'reason' => 'deadline_elapsed',
    ]);
    $this->dispatcher = app(ExternalBackupTelegramDispatcher::class);
});

afterEach(function () {
    config()->set('custom-backup-notifications.enabled', false);
    config()->set('rconfig-telegram.enabled', false);
    config()->set('rconfig-telegram.bot_token', '');
    config()->set('rconfig-telegram.chat_id', '');
    if (isset($this->template)) {
        $this->template->delete();
    }
    if (isset($this->run)) {
        DB::table('custom_backup_notification_deliveries')->where('source_type', 'external-file')->where('source_id', $this->run->id)->delete();
        $this->run->receipt()->delete();
        $this->run->delete();
    }
    if (isset($this->rule)) {
        $this->rule->delete();
    }
    if (isset($this->task)) {
        $this->task->delete();
    }
    if (isset($this->device)) {
        $this->device->delete();
    }
});

test('terminal failure is rendered and accepted only once by the fake Telegram provider', function () {
    $this->template = CustomBackupNotificationTemplate::create([
        'channel' => 'telegram',
        'event_type' => 'backup.failed',
        'title' => 'Demo {{device.name}}',
        'body' => '{{backup.status}} {{device.ip}} {{backup.error}}',
    ]);
    expect($this->dispatcher->dispatch($this->run->id))->toBe('accepted');
    expect($this->dispatcher->dispatch($this->run->id))->toBe('skipped');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['text'] === "<b>Demo DEMO &lt;switch&gt;</b>\nFAILED — External backup check failed"
        && $request['parse_mode'] === 'HTML'
        && $request['chat_id'] === '-1001234567890');
});

test('success without receipt cannot send, but a matching receipt allows success', function () {
    $this->run->update(['status' => 'success', 'reason' => 'verified_file_receipt', 'matched_filename' => 'demo.cfg']);
    expect(fn () => $this->dispatcher->dispatch($this->run->id))->toThrow(DomainException::class);
    Http::assertNothingSent();

    ExternalBackupFileReceipt::create([
        'check_run_id' => $this->run->id,
        'device_id' => $this->run->device_id,
        'verified_file_token' => str_repeat('b', 64),
        'accepted_at_utc' => '2026-11-23 20:01:00',
    ]);
    expect($this->dispatcher->dispatch($this->run->id))->toBe('accepted');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'SUCCESS')
        && str_contains($request['text'], 'demo.cfg')
        && ! str_contains($request['text'], str_repeat('b', 64)));
});

test('pending or disabled dispatch never contacts Telegram', function () {
    config()->set('custom-backup-notifications.enabled', false);
    expect($this->dispatcher->dispatch($this->run->id))->toBe('skipped');
    config()->set('custom-backup-notifications.enabled', true);
    $this->run->update(['status' => 'pending']);
    expect($this->dispatcher->dispatch($this->run->id))->toBe('skipped');
    Http::assertNothingSent();
});

test('mutable username destination is refused before creating an intent', function () {
    config()->set('rconfig-telegram.chat_id', '@demo_channel');
    expect(fn () => $this->dispatcher->dispatch($this->run->id))->toThrow(RuntimeException::class);
    expect(DB::table('custom_backup_notification_deliveries')->where('source_type', 'external-file')->where('source_id', $this->run->id)->count())->toBe(0);
    Http::assertNothingSent();
});
