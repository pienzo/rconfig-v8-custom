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
    $binary = getenv('F02_NATIVE_E2E_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('Real F02 Linux helper E2E runs only in the isolated CI step.');
    }

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

    $this->root = sys_get_temp_dir().'/rconfig-f02-real-native-'.bin2hex(random_bytes(8));
    $this->inbox = $this->root.'/'.$this->device->id;
    mkdir($this->root, 0700);
    mkdir($this->inbox, 0700);
    $this->file = $this->inbox.'/synthetic_today.cfg';
    file_put_contents($this->file, 'SYNTHETIC BACKUP DATA');

    $this->instant = CarbonImmutable::now('UTC')->startOfSecond();
    touch($this->file, $this->instant->getTimestamp() - 3);
    CarbonImmutable::setTestNow($this->instant);

    config()->set('external-backups.intake_root', $this->root);
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('external-backups.receipt_acceptance_enabled', true);
    config()->set('external-backups.receipt_hmac_key', 'base64:'.base64_encode(str_repeat('S', 32)));
    config()->set('external-backups.safe_digest_binary', $binary);

    $this->run = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => $this->instant->subMinute()->format('Y-m-d H:i:s'),
        'deadline_at_utc' => $this->instant->addMinute()->format('Y-m-d H:i:s'),
        'status' => 'pending',
        'reason' => 'unstable',
        'matched_filename' => basename($this->file),
        'first_observed_at_utc' => $this->instant->format('Y-m-d H:i:s'),
        'last_observed_at_utc' => $this->instant->format('Y-m-d H:i:s'),
    ]);

    $this->prior = app(ExternalBackupDirectoryScanner::class)->scan(
        $this->root, $this->device->id, $this->rule->filename_pattern, $this->rule->pattern_mode
    );
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    if (isset($this->file) && (is_file($this->file) || is_link($this->file))) {
        unlink($this->file);
    }
    if (isset($this->outside) && is_file($this->outside)) {
        unlink($this->outside);
    }
    if (isset($this->inbox) && is_dir($this->inbox)) {
        rmdir($this->inbox);
    }
    if (isset($this->root) && is_dir($this->root)) {
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
        config()->set('external-backups.'.$key, $value);
    }

    if (isset($this->run)) {
        $this->rollBackTransaction();
    }
});

function f02RunWithRealNative(ExternalBackupCheckRun $run, array $prior, CarbonImmutable $instant): void
{
    CarbonImmutable::setTestNow($instant->addSeconds(4));
    app()->call([new ExternalBackupFollowUpObservationJob(
        $run->id,
        $prior,
        $instant->format('Y-m-d H:i:s')
    ), 'handle']);
}

test('real compiled Linux verifier reaches exactly one persisted receipt and success', function () {
    f02RunWithRealNative($this->run, $this->prior, $this->instant);

    expect($this->run->fresh()->status)->toBe('success');
    expect($this->run->fresh()->reason)->toBe('verified_file_receipt');
    expect($this->run->fresh()->receipt)->not->toBeNull();
    expect(ExternalBackupFileReceipt::count())->toBe(1);

    f02RunWithRealNative($this->run, $this->prior, $this->instant);
    expect($this->run->fresh()->status)->toBe('success');
    expect(ExternalBackupFileReceipt::count())->toBe(1);
});

test('real compiled Linux verifier cannot produce success with receipt gate disabled', function () {
    config()->set('external-backups.receipt_acceptance_enabled', false);
    f02RunWithRealNative($this->run, $this->prior, $this->instant);

    expect($this->run->fresh()->status)->toBe('pending');
    expect($this->run->fresh()->reason)->toBe('requires_safe_open');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('replacing the observed file with an untrusted symlink cannot produce success', function () {
    $this->outside = $this->root.'/outside.cfg';
    file_put_contents($this->outside, 'UNTRUSTED TEST CONTENT');
    unlink($this->file);
    symlink($this->outside, $this->file);

    f02RunWithRealNative($this->run, $this->prior, $this->instant);

    expect($this->run->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
