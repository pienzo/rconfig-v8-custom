<?php

use App\Models\CustomBackupNotificationTemplate;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\Notifications\ExternalBackupTelegramDispatcher;
use App\Services\Notifications\ExternalBackupWhatsAppDispatcher;
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
        'first_observed_at_utc' => '2026-11-23 20:00:30',
        'last_observed_at_utc' => '2026-11-23 20:02:00',
        'status' => 'failed',
        'reason' => 'deadline_elapsed',
    ]);
    $this->dispatcher = app(ExternalBackupTelegramDispatcher::class);
});

afterEach(function () {
    config()->set('rconfig-whatsapp.enabled', false);
    config()->set('rconfig-whatsapp.policy_approved', false);
    config()->set('rconfig-whatsapp.from_utc', '');
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
    $this->run->update([
        'status' => 'success', 'reason' => 'verified_file_receipt', 'matched_filename' => 'demo.cfg',
        'last_observed_at_utc' => '2026-11-23 20:01:00',
    ]);
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

test('external WhatsApp dispatch selects failure template and never retries an uncertain outcome', function () {
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
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('custom-backup-notifications.f02_from_utc', '2026-01-01 00:00:00');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'synthetic']], 503)]);
    $dispatcher = app(ExternalBackupWhatsAppDispatcher::class);

    expect($dispatcher->dispatch($this->run->id))->toBe('uncertain');
    expect($dispatcher->dispatch($this->run->id))->toBe('skipped');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
        && $request['template']['name'] === 'backup_failed_alert'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'FAILED');
});

test('external WhatsApp success requires a matching receipt', function () {
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
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('custom-backup-notifications.f02_from_utc', '2026-01-01 00:00:00');
    $this->run->update([
        'status' => 'success', 'reason' => 'verified_file_receipt', 'matched_filename' => 'demo.cfg',
        'last_observed_at_utc' => '2026-11-23 20:01:00',
    ]);
    $dispatcher = app(ExternalBackupWhatsAppDispatcher::class);
    expect($dispatcher->dispatch($this->run->id))->toBe('skipped');
    Http::assertNothingSent();

    ExternalBackupFileReceipt::create([
        'check_run_id' => $this->run->id,
        'device_id' => $this->run->device_id,
        'verified_file_token' => str_repeat('c', 64),
        'accepted_at_utc' => '2026-11-23 20:01:00',
    ]);
    expect($dispatcher->dispatch($this->run->id))->toBe('accepted');
    expect($dispatcher->dispatch($this->run->id))->toBe('skipped');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['template']['name'] === 'backup_success_alert'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'SUCCESS');
});

test('a premature terminal failure is refused before transport or intent creation', function () {
    $this->run->update(['last_observed_at_utc' => '2026-11-23 20:01:00']);
    expect(fn () => $this->dispatcher->dispatch($this->run->id))->toThrow(DomainException::class);
    Http::assertNothingSent();
    expect(DB::table('custom_backup_notification_deliveries')->where('source_type', 'external-file')->where('source_id', $this->run->id)->count())->toBe(0);
});
