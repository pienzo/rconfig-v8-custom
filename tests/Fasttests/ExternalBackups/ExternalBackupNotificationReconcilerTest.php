<?php

use App\Jobs\ExternalBackupNotificationJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\Notifications\ExternalBackupNotificationLedger;
use App\Services\Notifications\ExternalBackupNotificationReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->beginTransaction();
    Queue::fake();
    config()->set('rConfig.is_demo', false);
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('custom-backup-notifications.f02_from_utc', '2026-01-01 00:00:00');
    config()->set('rconfig-telegram.enabled', true);
    config()->set('rconfig-backup-email.enabled', false);
    $device = Device::factory()->create();
    $task = Task::factory()->create(['task_command' => 'rconfig:check-external-backup']);
    $this->rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'enabled' => true,
    ]);
    $this->run = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $device->id,
        'scheduled_at_utc' => '2026-01-01 00:00:00',
        'deadline_at_utc' => '2026-01-01 00:02:00',
        'last_observed_at_utc' => '2026-01-01 00:02:00',
        'status' => 'failed',
        'reason' => 'deadline_elapsed',
    ]);
    $this->reconciler = app(ExternalBackupNotificationReconciler::class);
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('only opted in channels enqueue terminal occurrences on the dedicated queue', function () {
    $this->reconciler->enqueue();
    Queue::assertPushed(ExternalBackupNotificationJob::class, fn ($job): bool => $job->runId === $this->run->id
        && $job->channel === 'telegram' && $job->connection === 'redis_backup_notifications'
        && $job->queue === 'BackupNotifications');
    Queue::assertNotPushed(ExternalBackupNotificationJob::class, fn ($job): bool => $job->channel === 'email');
});

test('invalid cutoff disabled feature and pending runs are not eligible', function () {
    config()->set('custom-backup-notifications.f02_from_utc', '2026-02-30 00:00:00');
    expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeFalse();
    config()->set('custom-backup-notifications.f02_from_utc', '2026-01-02 00:00:00');
    expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeFalse();
    config()->set('custom-backup-notifications.f02_from_utc', '2026-01-01 00:00:00');
    config()->set('custom-backup-notifications.enabled', false);
    expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeFalse();
    config()->set('custom-backup-notifications.enabled', true);
    $this->run->update(['status' => 'pending']);
    expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeFalse();
    $this->reconciler->enqueue();
    Queue::assertNothingPushed();
});

test('pending intent is recoverable but claimed or uncertain delivery never requeues', function () {
    $ledger = app(ExternalBackupNotificationLedger::class);
    $id = $ledger->reconcile($this->run->id, 'telegram', 'primary', singleDestination: true);
    expect($id)->not->toBeNull();
    expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeTrue();
    expect($ledger->reconcile($this->run->id, 'telegram', 'rotated', singleDestination: true))->toBeNull();
    foreach (['sending', 'uncertain', 'accepted'] as $status) {
        DB::table('custom_backup_notification_deliveries')->where('id', $id)->update(['status' => $status]);
        expect($this->reconciler->eligible($this->run->id, 'telegram'))->toBeFalse();
    }
});
