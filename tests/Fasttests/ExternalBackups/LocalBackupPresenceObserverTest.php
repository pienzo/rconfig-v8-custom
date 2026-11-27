<?php

use App\Jobs\LocalBackupPresenceObserveJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupDirectoryMap;
use App\Services\ExternalBackups\LocalBackupPresenceObserver;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresencePendingReconciler;
use App\Services\ExternalBackups\LocalBackupPresencePolicy;
use App\Services\ExternalBackups\LocalBackupPresenceRunState;
use App\Services\ExternalBackups\LocalBackupPresenceStabilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->beginTransaction();
    config([
        'local-backup-presence.runtime_enabled' => true,
        'rConfig.is_demo' => false,
        'app.timezone' => 'UTC',
    ]);
    $this->device = Device::factory()->create();
    $this->task = Task::factory()->create([
        'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
        'task_devices' => 1,
        'task_categories' => 0,
        'task_tags' => 0,
        'task_email_notify' => false,
        'download_report_notify' => false,
        'verbose_download_report_notify' => false,
        'is_system' => 0,
        'is_paused' => 0,
        'task_cron' => '* * * * *',
    ]);
    $this->task->device()->attach($this->device->id);
    $this->rule = LocalBackupPresenceRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'directory_alias' => 'synthetic',
        'relative_directory' => 'firewalls/site-a',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
    ]);
    $this->rule->enabled = true;
    $this->instant = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(2);
    $this->rule->activated_at_utc = $this->instant->subMinute()->format('Y-m-d H:i:s');
    $this->rule->save();
    $this->service = new LocalBackupPresenceOccurrenceClaim(new ExternalBackupFilenameMatcher);
    $this->state = new LocalBackupPresenceRunState(new ExternalBackupFilenameMatcher);
    $this->sandbox = realpath(sys_get_temp_dir()) . '/rconfig_presence_' . bin2hex(random_bytes(8));
    $this->folder = $this->sandbox . '/firewalls/site-a';
    File::ensureDirectoryExists($this->folder);
    $this->mapping = new LocalBackupDirectoryMap(['synthetic' => $this->sandbox]);
    $this->observer = new LocalBackupPresenceObserver(new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher), new LocalBackupPresenceStabilityPolicy(new LocalBackupPresencePolicy), $this->state);
    $this->run = $this->service->claim($this->rule->id, $this->instant);
    CarbonImmutable::setTestNow($this->instant);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->sandbox);
    $this->rollBackTransaction();
});

test('actual preexisting file is present after quiet period and early retries preserve baseline', function () {
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->status)->toBe('pending');
    expect($first->reason)->toBe('stability_wait');
    $baseline = $first->stability_baseline;
    expect($first->toArray())->not->toHaveKey('directory_identity');
    expect($first->toArray())->not->toHaveKey('stability_baseline');

    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    $early = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($early->stability_baseline)->toEqual($baseline);

    // New service instance simulates losing process-local state.
    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    $observer = new LocalBackupPresenceObserver(new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher), new LocalBackupPresenceStabilityPolicy(new LocalBackupPresencePolicy), $this->state);
    $final = $observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('present');
    expect($final->matched_filename)->toBe('backup_a.cfg');
    expect($final->stability_baseline)->toBeNull();
    expect($final->directory_identity)->toBeNull();
    expect(File::get($this->folder . '/backup_a.cfg'))->toBe('synthetic');
});

test('file arrival after an empty scan and a growing file need a new stable observation', function () {
    $empty = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($empty->reason)->toBe('no_matching_file');
    File::put($this->folder . '/backup_a.cfg', 'partial');

    CarbonImmutable::setTestNow($this->instant->addMinute());
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('stability_wait');
    File::append($this->folder . '/backup_a.cfg', ' more');

    CarbonImmutable::setTestNow($this->instant->addSeconds(90));
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->status)->toBe('pending');
    expect($second->stability_baseline['observed_at'])->toBe($this->instant->addSeconds(90)->getTimestamp());

    CarbonImmutable::setTestNow($this->instant->addMinutes(2));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('present');
});

test('same size replacement changes the inode and restarts the quiet baseline', function () {
    $file = $this->folder . '/backup_a.cfg';
    File::put($file, 'original');
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('stability_wait');
    $original = $first->stability_baseline['scan']['files'][0];

    // Keep the old inode allocated under a nonmatching name.
    rename($file, $this->folder . '/previous.cfg');
    File::put($file, 'replaced');
    touch($file, $original['mtime']);

    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->status)->toBe('pending');
    expect($second->stability_baseline['observed_at'])->toBe($this->instant->addSeconds(10)->getTimestamp());
    $replacement = $second->stability_baseline['scan']['files'][0];
    expect($replacement['size'])->toBe($original['size']);
    expect($replacement['mtime'])->toBe($original['mtime']);
    expect($replacement['inode'])->not->toBe($original['inode']);

    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('pending');
    CarbonImmutable::setTestNow($this->instant->addSeconds(40));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('present');
});

test('snapshot disables quiet checking without requiring a second observation', function () {
    $this->rule->update(['quiet_seconds' => null]);
    $instant = $this->instant->addMinute();
    $run = $this->service->claim($this->rule->id, $instant);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    CarbonImmutable::setTestNow($instant);

    $before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];
    $final = $this->observer->observe($this->rule->id, $run->id, $this->mapping);
    expect($final->status)->toBe('present');
    expect($final->reason)->toBe('matching_file_observed');
    expect([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
});

test('old file does not satisfy freshness and expiry needs final-minute evidence', function () {
    File::put($this->folder . '/backup_a.cfg', 'old');
    touch($this->folder . '/backup_a.cfg', $this->instant->subDays(2)->getTimestamp());
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('no_eligible_file');

    CarbonImmutable::setTestNow($this->instant->addHour());
    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('observation_gap_before_deadline');
    expect($final->directory_identity)->toBeNull();
});

test('recent absence at expiry is distinct from unobserved monitoring error', function () {
    CarbonImmutable::setTestNow($this->instant->addMinutes(59));
    $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    CarbonImmutable::setTestNow($this->instant->addHour());

    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('absent');
    expect($final->reason)->toBe('no_matching_file');
});

test('replaced directory is not accepted as the same backup source', function () {
    $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    rename($this->folder, $this->folder . '-old');
    File::ensureDirectoryExists($this->folder);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    CarbonImmutable::setTestNow($this->instant->addMinute());

    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('directory_identity_changed');
    expect($final->matched_filename)->toBeNull();
});

test('missing directory is a sanitized scan error and terminal replay does not scan', function () {
    File::deleteDirectory($this->folder);
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->status)->toBe('error');
    expect($first->reason)->toBe('scan_failed');
    CarbonImmutable::setTestNow($this->instant->addMinute());
    $second = $this->observer->observe($this->rule->id, $this->run->id, new LocalBackupDirectoryMap([]));
    expect($second->getAttributes())->toEqual($first->getAttributes());
});

test('same second replay does not rescan and paused task or disabled gate refuses new work', function () {
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->getAttributes())->toEqual($first->getAttributes());

    CarbonImmutable::setTestNow($this->instant->addMinute());
    $this->task->update(['is_paused' => 1]);
    expect(fn () => $this->observer->observe($this->rule->id, $this->run->id, $this->mapping))->toThrow(DomainException::class);
    config(['local-backup-presence.runtime_enabled' => false]);
    expect(fn () => $this->observer->observe($this->rule->id, $this->run->id, $this->mapping))->toThrow(DomainException::class);
});

test('isolated one-observation job uses only configured roots and persisted run evidence', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => $this->sandbox],
    ]);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $job = new LocalBackupPresenceObserveJob($this->rule->id, $this->run->id);
    expect($job->connection)->toBe('redis_local_backup_presence');
    expect($job->queue)->toBe('LocalBackupPresence');
    expect($job->timeout)->toBeLessThan(config('queue.connections.redis_local_backup_presence.retry_after'));
    expect(serialize($job))->not->toContain($this->sandbox);
    $before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];

    $job->handle($this->observer);
    expect($this->run->fresh()->reason)->toBe('stability_wait');
    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    $job->handle($this->observer);
    $final = $this->run->fresh();
    expect($final->status)->toBe('present');
    expect($final->matched_filename)->toBe('backup_a.cfg');

    File::deleteDirectory($this->folder);
    $job->handle($this->observer);
    expect($this->run->fresh()->getAttributes())->toEqual($final->getAttributes());
    expect([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
});

test('local presence job refuses disabled gates demo and unrelated run identifiers', function () {
    config(['local-backup-presence.roots' => ['synthetic' => $this->sandbox]]);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $job = new LocalBackupPresenceObserveJob($this->rule->id, $this->run->id);
    $before = $this->run->fresh()->getAttributes();

    expect(config('local-backup-presence.queue_enabled'))->toBeFalse();
    $job->handle($this->observer);
    expect($this->run->fresh()->getAttributes())->toEqual($before);

    config(['local-backup-presence.queue_enabled' => true, 'local-backup-presence.runtime_enabled' => false]);
    $job->handle($this->observer);
    expect($this->run->fresh()->getAttributes())->toEqual($before);

    config(['local-backup-presence.runtime_enabled' => true, 'rConfig.is_demo' => true]);
    $job->handle($this->observer);
    expect($this->run->fresh()->getAttributes())->toEqual($before);

    config(['rConfig.is_demo' => false]);
    (new LocalBackupPresenceObserveJob($this->rule->id + 1, $this->run->id))->handle($this->observer);
    expect($this->run->fresh()->getAttributes())->toEqual($before);
});

test('pending reconciliation reserves a durable cooldown and retries lost jobs without inventing evidence', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => $this->sandbox],
    ]);
    Queue::fake();
    Bus::fake();
    $reconciler = app(LocalBackupPresencePendingReconciler::class);
    $reconciler->enqueue();
    $reconciler->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceObserveJob::class, 1);
    Bus::assertDispatched(LocalBackupPresenceObserveJob::class, fn ($job) => $job->ruleId === $this->rule->id && $job->runId === $this->run->id);
    $run = $this->run->fresh();
    expect($run->getRawOriginal('dispatch_not_before_utc'))->toBe($this->instant->addSeconds(55)->format('Y-m-d H:i:s'));
    expect($run->getRawOriginal('last_observed_at_utc'))->toBeNull();
    expect($run->toArray())->not->toHaveKey('dispatch_not_before_utc');
    expect($run->isFillable('dispatch_not_before_utc'))->toBeFalse();

    CarbonImmutable::setTestNow($this->instant->addSeconds(54));
    $reconciler->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceObserveJob::class, 1);
    CarbonImmutable::setTestNow($this->instant->addMinute());
    $reconciler->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceObserveJob::class, 2);
    expect($this->run->fresh()->status)->toBe('pending');

    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $job = new LocalBackupPresenceObserveJob($this->rule->id, $this->run->id);
    $job->handle($this->observer);
    CarbonImmutable::setTestNow($this->instant->addSeconds(90));
    $job->handle($this->observer);
    expect($this->run->fresh()->status)->toBe('present');

    CarbonImmutable::setTestNow($this->instant->addMinutes(2));
    $reconciler->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceObserveJob::class, 2);
});

test('pending reconciliation does not dispatch through disabled gates or before schedule', function () {
    Queue::fake();
    Bus::fake();
    $reconciler = app(LocalBackupPresencePendingReconciler::class);
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
    ]);
    expect(config('local-backup-presence.dispatch_enabled'))->toBeFalse();
    $reconciler->enqueue();
    config(['local-backup-presence.dispatch_enabled' => true, 'rConfig.is_demo' => true]);
    $reconciler->enqueue();
    config(['rConfig.is_demo' => false]);
    CarbonImmutable::setTestNow($this->instant->subSecond());
    $reconciler->enqueue();
    Bus::assertNothingDispatched();
    expect($this->run->fresh()->getRawOriginal('dispatch_not_before_utc'))->toBeNull();
});

test('paused and disabled runs are not dispatched before deadline but are recoverable for expiry', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => $this->sandbox],
    ]);
    Queue::fake();
    Bus::fake();
    $reconciler = app(LocalBackupPresencePendingReconciler::class);
    $this->task->update(['is_paused' => 1]);
    $this->rule->enabled = false;
    $this->rule->save();
    $reconciler->enqueue();
    Bus::assertNothingDispatched();

    CarbonImmutable::setTestNow($this->instant->addHour());
    $reconciler->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceObserveJob::class, 1);
    (new LocalBackupPresenceObserveJob($this->rule->id, $this->run->id))->handle($this->observer);
    expect($this->run->fresh()->status)->toBe('error');
    expect($this->run->fresh()->reason)->toBe('no_observation_before_deadline');
});

test('pending reconciliation respects queue pressure and does not leak transport errors', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
    ]);
    $queue = Mockery::mock(QueueContract::class);
    Queue::shouldReceive('connection')->with('redis_local_backup_presence')->andReturn($queue);
    $queue->shouldReceive('size')->with('LocalBackupPresence')->andReturn(100, 0, 0);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic private transport diagnostic'));
    Log::spy();
    $reconciler = app(LocalBackupPresencePendingReconciler::class);
    $reconciler->enqueue();
    expect($this->run->fresh()->getRawOriginal('dispatch_not_before_utc'))->toBeNull();

    $reconciler->enqueue();
    expect($this->run->fresh()->getRawOriginal('dispatch_not_before_utc'))->toBe($this->instant->addSeconds(55)->format('Y-m-d H:i:s'));
    $reconciler->enqueue();
    expect($this->run->fresh()->status)->toBe('pending');
    expect($this->run->fresh()->getRawOriginal('last_observed_at_utc'))->toBeNull();
    Log::shouldHaveReceived('warning')->with('Local backup presence dispatch unavailable.', ['run_id' => $this->run->id])->once();
});

test('scheduled observer refuses corrupted notification flags before scanning and preserves pending row', function (string $field) {
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $this->task->update([$field => true]);
    $before = $this->run->fresh()->getRawOriginal();
    expect(fn () => $this->observer->observe($this->rule->id, $this->run->id, $this->mapping))->toThrow(DomainException::class);
    expect($this->run->fresh()->getRawOriginal())->toBe($before)
        ->and(File::get($this->folder . '/backup_a.cfg'))->toBe('synthetic');
})->with(['task_email_notify', 'download_report_notify', 'verbose_download_report_notify']);
