<?php

use App\Jobs\ExternalBackupExpirePendingJob;
use App\Jobs\ExternalBackupFollowUpObservationJob;
use App\Jobs\ExternalBackupStartObservationJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->beginTransaction();
    Queue::fake();
    $this->device = Device::factory()->create();
    $this->task = Task::factory()->create([
        'task_command' => 'rconfig:check-external-backup',
        'is_paused' => 0,
    ]);
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

    $this->root = sys_get_temp_dir() . '/rconfig-f02-runner-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
    $this->inbox = $this->root . '/' . $this->device->id;
    mkdir($this->inbox, 0700);
    config()->set('external-backups.intake_root', $this->root);
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    $this->instant = CarbonImmutable::now('UTC')->startOfSecond();
    CarbonImmutable::setTestNow($this->instant);
});

afterEach(function () {
    foreach (glob($this->inbox . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($this->inbox);
    rmdir($this->root);
    CarbonImmutable::setTestNow();
    config()->set('external-backups.intake_root', '');
    config()->set('external-backups.runtime_enabled', false);
    config()->set('external-backups.queue_enabled', false);
    $this->rollBackTransaction();
});

function f02RunStartInstant(CarbonImmutable $now): string
{
    return $now->startOfMinute()->format('Y-m-d H:i:s');
}

test('F02 scheduled first observation claims a durable pending occurrence and queues follow up', function () {
    $file = $this->inbox . '/synthetic_today.cfg';
    file_put_contents($file, 'SYNTHETIC-CONTENTS');
    touch($file, $this->instant->getTimestamp());

    $start = new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant));
    app()->call([$start, 'handle']);

    $run = ExternalBackupCheckRun::firstOrFail();
    if ($run->reason === null) {
        throw new RuntimeException('F02 test diagnostic (synthetic): status=' . $run->status
            . ' deadline=' . $run->getRawOriginal('deadline_at_utc')
            . ' observed=' . (string) $run->getRawOriginal('last_observed_at_utc')
            . ' now=' . CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    }
    expect($run->status)->toBe('pending');
    expect($run->reason)->toBe('unstable');
    expect($run->device_id)->toBe($this->device->id);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
    Queue::assertPushed(ExternalBackupFollowUpObservationJob::class, 1);

    // Duplicate queue delivery must not create another occurrence.
    app()->call([$start, 'handle']);
    expect(ExternalBackupCheckRun::count())->toBe(1);
    Queue::assertPushed(ExternalBackupFollowUpObservationJob::class, 1);
});

test('F02 missing backup expires to durable FAILED without a receipt or SUCCESS', function () {
    $start = new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant));
    app()->call([$start, 'handle']);
    expect(ExternalBackupCheckRun::firstOrFail()->reason)->toBe('missing');

    CarbonImmutable::setTestNow($this->instant->addSeconds(121));
    app()->call([new ExternalBackupExpirePendingJob, 'handle']);

    expect(ExternalBackupCheckRun::firstOrFail()->status)->toBe('failed');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 second metadata observation never marks success without a trusted file receipt', function () {
    $file = $this->inbox . '/synthetic_recent.cfg';
    file_put_contents($file, 'SYNTHETIC-CONTENTS');
    touch($file, $this->instant->getTimestamp());

    $start = new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant));
    app()->call([$start, 'handle']);

    $follow = null;
    Queue::assertPushed(ExternalBackupFollowUpObservationJob::class, function ($job) use (&$follow) {
        $follow = $job;

        return true;
    });
    expect($follow)->not->toBeNull();

    CarbonImmutable::setTestNow($this->instant->addSeconds(4));
    app()->call([$follow, 'handle']);

    expect(ExternalBackupCheckRun::firstOrFail()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 runtime flag and per-rule pause disable all automatic observations', function () {
    config()->set('external-backups.runtime_enabled', false);
    app()->call([new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant)), 'handle']);
    expect(ExternalBackupCheckRun::count())->toBe(0);

    config()->set('external-backups.runtime_enabled', true);
    $this->task->update(['is_paused' => 1]);
    app()->call([new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant)), 'handle']);
    expect(ExternalBackupCheckRun::count())->toBe(0);

    $this->task->update(['is_paused' => 0]);
    $this->rule->update(['enabled' => false]);
    app()->call([new ExternalBackupStartObservationJob($this->rule->id, f02RunStartInstant($this->instant)), 'handle']);
    expect(ExternalBackupCheckRun::count())->toBe(0);
});
