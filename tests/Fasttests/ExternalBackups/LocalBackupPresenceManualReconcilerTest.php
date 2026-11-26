<?php

use App\Jobs\LocalBackupPresenceManualObserveJob;
use App\Models\Device;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualReconciler;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->beginTransaction();
    config([
        'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
        'local-backup-presence.manual_probe_enabled' => true,
        'local-backup-presence.manual_dispatch_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only'],
    ]);
    $this->instant = CarbonImmutable::parse('2026-11-26 02:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->instant);
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
    $this->makeSession = function (string $name): LocalBackupPresenceManualSession {
        $rule = app(LocalBackupPresenceRuleLifecycle::class)->create($this->device->id, $name, '* * * * *', $this->criteria);

        return app(LocalBackupPresenceManualSessions::class)->start($rule->id);
    };
    $this->service = app(LocalBackupPresenceManualReconciler::class);
    $this->queue = Mockery::mock(QueueContract::class);
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->rollBackTransaction();
});

test('manual reconciliation reserves at most two due sessions and preserves the observation time', function () {
    $first = ($this->makeSession)('Synthetic first');
    CarbonImmutable::setTestNow($this->instant->addSecond());
    $second = ($this->makeSession)('Synthetic second');
    CarbonImmutable::setTestNow($this->instant->addSeconds(2));
    $third = ($this->makeSession)('Synthetic third');
    $before = LocalBackupPresenceRun::count();
    Queue::shouldReceive('connection')->with('redis_local_backup_presence')->twice()->andReturn($this->queue);
    $this->queue->shouldReceive('size')->with('LocalBackupPresence')->twice()->andReturn(0);
    $this->service->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceManualObserveJob::class, 2);
    Bus::assertDispatched(LocalBackupPresenceManualObserveJob::class, fn ($job) => $job->sessionId === $first->id);
    Bus::assertDispatched(LocalBackupPresenceManualObserveJob::class, fn ($job) => $job->sessionId === $second->id);
    expect($first->fresh()->dispatch_not_before)->toBe($this->instant->addSeconds(57)->getTimestamp())
        ->and($first->fresh()->next_observation_at)->toBe($first->next_observation_at)
        ->and($third->fresh()->dispatch_not_before)->toBeNull();
    $this->service->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceManualObserveJob::class, 3);
    expect(LocalBackupPresenceRun::count())->toBe($before);
});

test('manual reconciliation skips future terminal and expired sessions', function () {
    $future = ($this->makeSession)('Synthetic future');
    $future->next_observation_at = $this->instant->addSeconds(90)->getTimestamp();
    $future->save();
    $terminal = ($this->makeSession)('Synthetic terminal');
    $terminal->status = 'present';
    $terminal->save();
    $expired = ($this->makeSession)('Synthetic expired');
    $expired->expires_at = $this->instant->getTimestamp();
    $expired->save();
    Queue::shouldReceive('connection')->with('redis_local_backup_presence')->once()->andReturn($this->queue);
    $this->queue->shouldReceive('size')->with('LocalBackupPresence')->once()->andReturn(0);
    $this->service->enqueue();
    expect($expired->fresh())->toBeNull()
        ->and($future->fresh()->dispatch_not_before)->toBeNull()
        ->and($terminal->fresh()->dispatch_not_before)->toBeNull();
    Bus::assertNothingDispatched();
});

test('manual reconciliation honors shared queue pressure', function (int $size, int $count) {
    ($this->makeSession)('Synthetic pressure one');
    ($this->makeSession)('Synthetic pressure two');
    Queue::shouldReceive('connection')->with('redis_local_backup_presence')->once()->andReturn($this->queue);
    $this->queue->shouldReceive('size')->with('LocalBackupPresence')->once()->andReturn($size);
    $this->service->enqueue();
    Bus::assertDispatchedTimes(LocalBackupPresenceManualObserveJob::class, $count);
})->with([[9, 1], [10, 0], [100, 0]]);

test('manual reconciliation retries a failed dispatch after cooldown without advancing observation', function () {
    $session = ($this->makeSession)('Synthetic failed transport');
    Queue::shouldReceive('connection')->with('redis_local_backup_presence')->times(3)->andReturn($this->queue);
    $this->queue->shouldReceive('size')->with('LocalBackupPresence')->times(3)->andReturn(0);
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(LocalBackupPresenceManualObserveJob::class))
        ->andThrow(new RuntimeException('synthetic transport failure'));
    $this->service->enqueue();
    expect($session->fresh()->dispatch_not_before)->toBe($this->instant->addSeconds(55)->getTimestamp());
    CarbonImmutable::setTestNow($this->instant->addSeconds(54));
    $this->service->enqueue();
    CarbonImmutable::setTestNow($this->instant->addSeconds(55));
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(LocalBackupPresenceManualObserveJob::class))->andReturnNull();
    $this->service->enqueue();
    expect($session->fresh()->dispatch_not_before)->toBe($this->instant->addSeconds(110)->getTimestamp())
        ->and($session->fresh()->next_observation_at)->toBe($session->next_observation_at);
});

test('manual reconciliation gates prevent cleanup and queue access', function (string $key, mixed $value) {
    $session = ($this->makeSession)('Synthetic gated dispatch');
    config([$key => $value]);
    Queue::shouldReceive('connection')->never();
    $this->service->enqueue();
    expect($session->fresh()->getRawOriginal())->toBe($session->getRawOriginal());
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.queue_enabled', false],
    ['local-backup-presence.scheduler_enabled', false],
    ['local-backup-presence.dispatch_enabled', false],
    ['local-backup-presence.manual_probe_enabled', false],
    ['local-backup-presence.manual_dispatch_enabled', false],
    ['local-backup-presence.manual_dispatch_enabled', 'true'],
    ['rConfig.is_demo', true],
]);
