<?php

use App\Jobs\LocalBackupPresenceManualObserveJob;
use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceManualWorker;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->beginTransaction();
    $this->sandbox = realpath(sys_get_temp_dir()) . '/rconfig_manual_worker_' . bin2hex(random_bytes(8));
    $this->folder = $this->sandbox . '/device';
    File::ensureDirectoryExists($this->folder);
    $this->instant = CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(10);
    CarbonImmutable::setTestNow($this->instant);
    config([
        'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.manual_probe_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => $this->sandbox],
    ]);
    $this->device = Device::factory()->create();
    $this->criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => 30,
        'deadline_seconds' => 180,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ];
    $this->lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $this->rule = $this->lifecycle->create($this->device->id, 'Synthetic manual worker', '* * * * *', $this->criteria);
    $this->sessions = app(LocalBackupPresenceManualSessions::class);
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->sandbox);
    $this->rollBackTransaction();
});

test('manual worker persists stability state and completes across independent instances', function () {
    $path = $this->folder . '/backup_a.cfg';
    File::put($path, 'synthetic');
    $before = [LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count(), $this->rule->fresh()->getRawOriginal()];
    $session = $this->sessions->start($this->rule->id);
    $job = new LocalBackupPresenceManualObserveJob($this->rule->id, $session->id);
    expect($job->connection)->toBe('redis_local_backup_presence')
        ->and($job->queue)->toBe('LocalBackupPresence')
        ->and($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(2);
    $job->handle(app(LocalBackupPresenceManualWorker::class));
    $pending = $session->fresh();
    expect($pending->status)->toBe('pending')
        ->and($pending->continuation)->not->toBeNull()
        ->and($pending->next_observation_at)->toBe($this->instant->addSeconds(30)->getTimestamp())
        ->and($pending->toArray())->not->toHaveKeys(['continuation', 'result']);

    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    rename($this->folder, $this->sandbox . '/temporarily-unavailable');
    $job->handle(app(LocalBackupPresenceManualWorker::class));
    expect($session->fresh()->getRawOriginal())->toBe($pending->getRawOriginal());
    rename($this->sandbox . '/temporarily-unavailable', $this->folder);
    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    $job->handle(app(LocalBackupPresenceManualWorker::class));
    $terminal = $session->fresh();
    expect($terminal->status)->toBe('present')
        ->and($terminal->result['reason'])->toBe('stable_matching_file_observed')
        ->and($terminal->continuation)->toBeNull()
        ->and($terminal->next_observation_at)->toBeNull();
    File::delete($path);
    $job->handle(app(LocalBackupPresenceManualWorker::class));
    expect($session->fresh()->getRawOriginal())->toBe($terminal->getRawOriginal());
    expect([LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count(), $this->rule->fresh()->getRawOriginal()])->toBe($before);
    Bus::assertNothingDispatched();
});

test('manual worker ignores stale UUIDs and wrong rules after invalidation', function () {
    $old = $this->sessions->start($this->rule->id);
    $this->lifecycle->update($this->rule->id, 'Synthetic manual worker edited', '* * * * *', $this->criteria);
    $new = $this->sessions->start($this->rule->id);
    $before = $new->getRawOriginal();
    $worker = app(LocalBackupPresenceManualWorker::class);
    $worker->process($this->rule->id, $old->id);
    $worker->process($this->rule->id, (string) Str::uuid());
    $other = $this->lifecycle->create($this->device->id, 'Synthetic other manual worker', '* * * * *', $this->criteria);
    $worker->process($other->id, $new->id);
    expect($old->fresh())->toBeNull()
        ->and($new->fresh()->getRawOriginal())->toBe($before);
    Bus::assertNothingDispatched();
});

test('manual worker respects admission expiry including queue latency', function () {
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $session = $this->sessions->start($this->rule->id);
    $worker = app(LocalBackupPresenceManualWorker::class);
    CarbonImmutable::setTestNow($this->instant->addSeconds(170));
    $worker->process($this->rule->id, $session->id);
    $pending = $session->fresh();
    expect($pending->status)->toBe('pending')
        ->and($pending->next_observation_at)->toBe($session->expires_at)
        ->and($pending->expires_at)->toBe($session->expires_at);
    CarbonImmutable::setTestNow($this->instant->addSeconds(180));
    $worker->process($this->rule->id, $session->id);
    $last = $session->fresh();
    expect($last->status)->toBe('error')
        ->and($last->result['reason'])->toBe('manual_probe_expired')
        ->and($last->continuation)->toBeNull()
        ->and($last->next_observation_at)->toBeNull();
    Bus::assertNothingDispatched();
});

test('manual worker stores immediate diagnostic absence without a scheduled run', function () {
    $session = $this->sessions->start($this->rule->id);
    app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $session->id);
    $last = $session->fresh();
    expect($last->status)->toBe('absent')
        ->and($last->result['reason'])->toBe('no_matching_file')
        ->and($last->result['verified_success'])->toBeFalse()
        ->and($last->next_observation_at)->toBeNull();
    expect($this->rule->runs()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('manual worker gates leave the persisted session unchanged', function (string $key, mixed $value) {
    $session = $this->sessions->start($this->rule->id);
    config([$key => $value]);
    app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $session->id);
    expect($session->fresh()->getRawOriginal())->toBe($session->getRawOriginal());
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.queue_enabled', false],
    ['local-backup-presence.manual_probe_enabled', false],
    ['local-backup-presence.manual_probe_enabled', 'true'],
    ['rConfig.is_demo', true],
]);

test('manual worker rollback restores the exact queued session for retry', function () {
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $session = $this->sessions->start($this->rule->id);
    DB::beginTransaction();
    try {
        app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $session->id);
        expect($session->fresh()->status)->toBe('pending');
    } finally {
        DB::rollBack();
    }
    expect($session->fresh()->getRawOriginal())->toBe($session->getRawOriginal());
    app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $session->id);
    expect($session->fresh()->status)->toBe('pending');
    Bus::assertNothingDispatched();
});

test('manual worker refuses invalid persisted continuation before scanning', function () {
    $session = $this->sessions->start($this->rule->id);
    $session->status = 'pending';
    $session->save();
    app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $session->id);
    $last = $session->fresh();
    expect($last->status)->toBe('error')
        ->and($last->result['reason'])->toBe('manual_probe_changed_or_invalid')
        ->and($last->continuation)->toBeNull();
    Bus::assertNothingDispatched();
});
