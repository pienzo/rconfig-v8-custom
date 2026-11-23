<?php

use App\Jobs\ExternalBackupFollowUpObservationJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->beginTransaction();
    Queue::fake();

    $this->task = Task::factory()->create([
        'task_command' => 'rconfig:check-external-backup',
        'is_paused' => 0,
    ]);
    $this->device = Device::factory()->create();
    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
        'enabled' => true,
    ]);
    $this->root = sys_get_temp_dir() . '/rconfig-f02-receipt-wire-' . bin2hex(random_bytes(8));
    $this->inbox = $this->root . '/' . $this->device->id;
    mkdir($this->root, 0700);
    mkdir($this->inbox, 0700);
    $this->file = $this->inbox . '/synthetic_today.cfg';
    file_put_contents($this->file, 'SYNTHETIC BACKUP DATA');
    $this->instant = CarbonImmutable::now('UTC')->startOfSecond();
    touch($this->file, $this->instant->getTimestamp() - 3);
    CarbonImmutable::setTestNow($this->instant);

    config()->set('external-backups.intake_root', $this->root);
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('external-backups.receipt_acceptance_enabled', true);
    config()->set('external-backups.receipt_hmac_key', 'base64:' . base64_encode(str_repeat('S', 32)));

    $observedAt = $this->instant;
    $start = $observedAt->subMinute();
    $this->run = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => $start->format('Y-m-d H:i:s'),
        'deadline_at_utc' => $start->addSeconds(120)->format('Y-m-d H:i:s'),
        'status' => 'pending',
        'reason' => 'unstable',
        'matched_filename' => basename($this->file),
        'first_observed_at_utc' => $observedAt->format('Y-m-d H:i:s'),
        'last_observed_at_utc' => $observedAt->format('Y-m-d H:i:s'),
    ]);

    $this->prior = app(ExternalBackupDirectoryScanner::class)->scan(
        $this->root, $this->device->id, $this->rule->filename_pattern, $this->rule->pattern_mode
    );
    $stat = lstat($this->file);
    $evidence = [
        'version' => 1,
        'state' => 'descriptor_checked_only',
        'sha256' => hash('sha256', 'SYNTHETIC BACKUP DATA'),
        'size' => $stat['size'],
        'device' => $stat['dev'],
        'inode' => $stat['ino'],
        'mtime' => $stat['mtime'],
        'ctime' => $stat['ctime'],
        'mtime_ns' => 0,
        'ctime_ns' => 0,
    ];
    $this->helper = tempnam(sys_get_temp_dir(), 'f02-proof-');
    file_put_contents($this->helper, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg(json_encode($evidence, JSON_THROW_ON_ERROR)) . "\n");
    chmod($this->helper, 0700);
    config()->set('external-backups.safe_digest_binary', $this->helper);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    foreach ([$this->helper, $this->file] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($this->inbox)) {
        rmdir($this->inbox);
    }
    if (is_dir($this->root)) {
        rmdir($this->root);
    }
    foreach ([
        'intake_root' => '',
        'runtime_enabled' => false,
        'queue_enabled' => false,
        'receipt_acceptance_enabled' => false,
        'receipt_hmac_key' => '',
        'safe_digest_binary' => '',
    ] as $key => $value) {
        config()->set('external-backups.' . $key, $value);
    }
    $this->rollBackTransaction();
});

function f02RunVerifiedFollowUp(ExternalBackupCheckRun $run, array $scan, CarbonImmutable $at): void
{
    CarbonImmutable::setTestNow($at->addSeconds(4));
    app()->call([new ExternalBackupFollowUpObservationJob(
        $run->id,
        $scan,
        $at->format('Y-m-d H:i:s')
    ), 'handle']);
}

test('F02 follow-up connects two stable observations to one receipt and durable SUCCESS only behind gate', function () {
    f02RunVerifiedFollowUp($this->run, $this->prior, $this->instant);

    $saved = $this->run->fresh();
    expect($saved->status)->toBe('success');
    expect($saved->reason)->toBe('verified_file_receipt');
    expect($saved->receipt)->not->toBeNull();
    expect(ExternalBackupFileReceipt::count())->toBe(1);

    // Replayed queue delivery cannot claim a second receipt or change status.
    f02RunVerifiedFollowUp($this->run, $this->prior, $this->instant);
    expect(ExternalBackupFileReceipt::count())->toBe(1);
    expect($this->run->fresh()->status)->toBe('success');
});

test('F02 follow-up leaves a stable candidate PENDING while receipt gate is OFF', function () {
    config()->set('external-backups.receipt_acceptance_enabled', false);
    f02RunVerifiedFollowUp($this->run, $this->prior, $this->instant);

    expect($this->run->fresh()->status)->toBe('pending');
    expect($this->run->fresh()->reason)->toBe('requires_safe_open');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 follow-up cannot mark success with invalid native descriptor evidence', function () {
    // Force the adapter to refuse a descriptor mismatch before any receipt claim.
    $evidence = [
        'version' => 1,
        'state' => 'descriptor_checked_only',
        'sha256' => str_repeat('a', 64),
        'size' => 9999999,
        'device' => 1,
        'inode' => 1,
        'mtime' => 1,
        'ctime' => 1,
        'mtime_ns' => 0,
        'ctime_ns' => 0,
    ];
    file_put_contents($this->helper, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg(json_encode($evidence, JSON_THROW_ON_ERROR)) . "\n");
    f02RunVerifiedFollowUp($this->run, $this->prior, $this->instant);

    expect($this->run->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
