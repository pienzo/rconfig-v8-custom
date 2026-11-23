<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\Notifications\ExternalBackupNotificationLedger;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->beginTransaction();
    $device = Device::factory()->create();
    $task = Task::factory()->create(['task_command' => 'rconfig:check-external-backup']);
    $rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
        'enabled' => true,
    ]);
    $this->run = ExternalBackupCheckRun::create([
        'rule_id' => $rule->id,
        'device_id' => $device->id,
        'scheduled_at_utc' => '2026-11-23 20:00:00',
        'deadline_at_utc' => '2026-11-23 20:02:00',
        'status' => 'pending',
    ]);
    $this->ledger = app(ExternalBackupNotificationLedger::class);
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('pending and success without receipt cannot create delivery intents', function () {
    expect($this->ledger->reconcile($this->run->id, 'email', 'primary'))->toBeNull();
    $this->run->update(['status' => 'success', 'reason' => 'verified_file_receipt']);
    expect($this->ledger->reconcile($this->run->id, 'email', 'primary'))->toBeNull();
    expect(DB::table('custom_backup_notification_deliveries')->count())->toBe(0);
});

test('terminal failure is reconciled once per channel and destination', function () {
    $this->run->update(['status' => 'failed', 'reason' => 'deadline_elapsed']);
    $id = $this->ledger->reconcile($this->run->id, 'email', 'primary');
    expect($id)->not->toBeNull();
    expect($this->ledger->reconcile($this->run->id, 'email', 'primary'))->toBe($id);
    expect($this->ledger->reconcile($this->run->id, 'telegram', 'primary'))->not->toBe($id);
    expect($this->ledger->reconcile($this->run->id, 'email', 'secondary'))->not->toBe($id);
    expect(DB::table('custom_backup_notification_deliveries')->count())->toBe(3);
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $id)->value('event_type'))->toBe('backup.failed');
});

test('verified success creates one pending intent without copying receipt evidence', function () {
    $this->run->update(['status' => 'success', 'reason' => 'verified_file_receipt']);
    ExternalBackupFileReceipt::create([
        'check_run_id' => $this->run->id,
        'device_id' => $this->run->device_id,
        'verified_file_token' => str_repeat('a', 64),
        'accepted_at_utc' => '2026-11-23 20:01:00',
    ]);
    $id = $this->ledger->reconcile($this->run->id, 'telegram', 'primary');
    expect($id)->not->toBeNull();
    expect($this->ledger->reconcile($this->run->id, 'telegram', 'primary'))->toBe($id);
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $id)->value('status'))->toBe('pending');
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $id)->value('event_type'))->toBe('backup.success');
});

test('unsupported channels and recipient addresses are rejected', function () {
    expect(fn () => $this->ledger->reconcile($this->run->id, 'whatsapp', 'primary'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->ledger->reconcile($this->run->id, 'email', 'demo@example.test'))->toThrow(InvalidArgumentException::class);
});
