<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceManualWorker;
use App\Services\ExternalBackups\LocalBackupPresenceRuleActivation;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->beginTransaction();
    config([
        'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.manual_probe_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only'],
    ]);
    $this->instant = CarbonImmutable::parse('2026-11-26 01:00:00', 'UTC');
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
    $this->lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $this->service = app(LocalBackupPresenceManualSessions::class);
    $this->rule = $this->lifecycle->create($this->device->id, 'Synthetic session', '* * * * *', $this->criteria);
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->rollBackTransaction();
});

test('manual admission is persistent and idempotent without scanning or dispatch', function () {
    $before = $this->rule->getRawOriginal();
    $session = $this->service->start($this->rule->id);
    expect($session->status)->toBe('queued')
        ->and($session->requested_at)->toBe($this->instant->getTimestamp())
        ->and($session->expires_at)->toBe($this->instant->addSeconds(180)->getTimestamp())
        ->and($session->next_observation_at)->toBe($session->requested_at);
    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    expect(app(LocalBackupPresenceManualSessions::class)->start($this->rule->id)->getRawOriginal())->toBe($session->getRawOriginal());
    expect($this->rule->fresh()->getRawOriginal())->toBe($before);
    expect($this->rule->runs()->count())->toBe(0);
    expect($session->toArray())->not->toHaveKeys(['continuation', 'result']);
    Bus::assertNothingDispatched();
});

test('manual session capacity is independent of scheduled rules and expires without reusing UUID', function () {
    $ids = [];
    for ($index = 0; $index < LocalBackupPresenceManualSessions::CAPACITY; $index++) {
        $rule = $index === 0 ? $this->rule : $this->lifecycle->create($this->device->id, 'Synthetic session ' . $index, '* * * * *', $this->criteria);
        $ids[] = $this->service->start($rule->id)->id;
    }
    $extra = $this->lifecycle->create($this->device->id, 'Synthetic extra session', '* * * * *', $this->criteria);
    expect(fn () => $this->service->start($extra->id))->toThrow(DomainException::class);
    expect($this->service->start($this->rule->id)->id)->toBe($ids[0]);
    CarbonImmutable::setTestNow($this->instant->addSeconds(180));
    $new = $this->service->start($extra->id);
    expect($new->id)->not->toBeIn($ids);
    expect(LocalBackupPresenceManualSession::whereIn('id', $ids)->count())->toBe(0);
    expect(LocalBackupPresenceManualSession::count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('manual admission caps the lifetime at two hours and can prune with execution disabled', function () {
    $this->rule->update(['deadline_seconds' => 10000]);
    $session = $this->service->start($this->rule->id);
    expect($session->expires_at - $session->requested_at)->toBe(7200);
    config(['local-backup-presence.runtime_enabled' => false, 'local-backup-presence.manual_probe_enabled' => false]);
    CarbonImmutable::setTestNow($this->instant->addSeconds(7199));
    expect($this->service->pruneExpired())->toBe(0);
    CarbonImmutable::setTestNow($this->instant->addSeconds(7200));
    expect($this->service->pruneExpired())->toBe(1);
    expect($session->fresh())->toBeNull();
    Bus::assertNothingDispatched();
});

test('manual session invalidation follows rule edit activation and deletion', function () {
    $session = $this->service->start($this->rule->id);
    $this->lifecycle->update($this->rule->id, 'Synthetic edited session', '* * * * *', $this->criteria);
    expect($session->fresh())->toBeNull();
    $second = $this->service->start($this->rule->id);
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
    ]);
    app(LocalBackupPresenceRuleActivation::class)->change($this->rule->id, true);
    expect($second->fresh())->toBeNull();
    $third = $this->service->start($this->rule->id);
    app(LocalBackupPresenceRuleActivation::class)->change($this->rule->id, true);
    expect($third->fresh())->not->toBeNull();
    app(LocalBackupPresenceRuleActivation::class)->change($this->rule->id, false);
    expect($third->fresh())->toBeNull();

    $draft = $this->lifecycle->create($this->device->id, 'Synthetic deletable session', '* * * * *', $this->criteria);
    $fourth = $this->service->start($draft->id);
    $this->lifecycle->delete($draft->id);
    expect($fourth->fresh())->toBeNull();
    Bus::assertNothingDispatched();
});

test('manual admission rollback removes its temporary session without altering scheduled history', function () {
    $before = LocalBackupPresenceRun::count();
    DB::beginTransaction();
    try {
        $id = $this->service->start($this->rule->id)->id;
    } finally {
        DB::rollBack();
    }
    expect(LocalBackupPresenceManualSession::find($id))->toBeNull();
    expect(LocalBackupPresenceRun::count())->toBe($before);
    Bus::assertNothingDispatched();
});

test('manual session admission rejects unapproved configuration', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(fn () => $this->service->start($this->rule->id))->toThrow(DomainException::class);
    expect(LocalBackupPresenceManualSession::where('rule_id', $this->rule->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.manual_probe_enabled', false],
    ['local-backup-presence.manual_probe_enabled', 'true'],
    ['rConfig.is_demo', true],
]);

test('manual admission rejects unknown mapping and missing admission mutex', function () {
    config(['local-backup-presence.roots' => []]);
    expect(fn () => $this->service->start($this->rule->id))->toThrow(InvalidArgumentException::class);
    config(['local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only']]);
    DB::table('local_backup_presence_activation_lock')->where('id', 1)->delete();
    expect(fn () => $this->service->start($this->rule->id))->toThrow(DomainException::class);
    expect(LocalBackupPresenceManualSession::where('rule_id', $this->rule->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

test('explicit restart replaces only selected terminal diagnostic with a fresh UUID', function (string $status) {
    $old = $this->service->start($this->rule->id);
    $old->status = $status;
    $old->result = ['status' => $status];
    $old->next_observation_at = null;
    $old->dispatch_not_before = $old->requested_at + 55;
    $old->save();
    $before = $this->rule->fresh()->getRawOriginal();
    $taskBefore = $this->rule->task()->firstOrFail()->getRawOriginal();
    CarbonImmutable::setTestNow($this->instant->addSeconds(60));
    expect($this->service->start($this->rule->id)->getRawOriginal())->toBe($old->getRawOriginal());
    $new = $this->service->restart($this->rule->id, $old->id);
    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh())->toBeNull()
        ->and($new->status)->toBe('queued')
        ->and($new->requested_at)->toBe($this->instant->addSeconds(60)->getTimestamp())
        ->and($new->expires_at)->toBe($this->instant->addSeconds(240)->getTimestamp())
        ->and($new->next_observation_at)->toBe($new->requested_at)
        ->and($new->result)->toBeNull()
        ->and($new->continuation)->toBeNull()
        ->and($new->dispatch_not_before)->toBeNull();
    $snapshot = $new->getRawOriginal();
    expect(fn () => $this->service->restart($this->rule->id, $old->id))->toThrow(DomainException::class);
    config(['local-backup-presence.queue_enabled' => true]);
    app(LocalBackupPresenceManualWorker::class)->process($this->rule->id, $old->id);
    expect($new->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($this->rule->fresh()->getRawOriginal())->toBe($before)
        ->and($this->rule->task()->firstOrFail()->getRawOriginal())->toBe($taskBefore)
        ->and($this->rule->runs()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with(['present', 'absent', 'error']);

test('manual restart refuses queued and pending states, invalid and wrong rule UUIDs', function () {
    $old = $this->service->start($this->rule->id);
    $other = $this->lifecycle->create($this->device->id, 'Other restart rule', '* * * * *', $this->criteria);
    $otherSession = $this->service->start($other->id);
    expect(fn () => $this->service->restart($this->rule->id, 'not-a-uuid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->restart($this->rule->id, $old->id))->toThrow(DomainException::class);
    $old->status = 'pending';
    $old->save();
    $snapshot = $old->getRawOriginal();
    expect(fn () => $this->service->restart($this->rule->id, $old->id))->toThrow(DomainException::class);
    expect(fn () => $this->service->restart($this->rule->id, $otherSession->id))->toThrow(DomainException::class);
    expect($old->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($otherSession->fresh()->getRawOriginal())->toBe($otherSession->getRawOriginal());
    Bus::assertNothingDispatched();
});

test('manual restart at capacity replaces one slot and outer rollback restores the original', function () {
    $old = $this->service->start($this->rule->id);
    $old->status = 'present';
    $old->next_observation_at = null;
    $old->save();
    for ($index = 1; $index < LocalBackupPresenceManualSessions::CAPACITY; $index++) {
        $other = $this->lifecycle->create($this->device->id, 'Restart capacity ' . $index, '* * * * *', $this->criteria);
        $this->service->start($other->id);
    }
    $snapshot = $old->getRawOriginal();
    DB::beginTransaction();
    try {
        $new = $this->service->restart($this->rule->id, $old->id);
        expect($new->id)->not->toBe($old->id)
            ->and(LocalBackupPresenceManualSession::count())->toBe(10);
    } finally {
        DB::rollBack();
    }
    expect($old->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($new->fresh())->toBeNull()
        ->and(LocalBackupPresenceManualSession::count())->toBe(10);
    Bus::assertNothingDispatched();
});

test('manual restart refuses expired or unapproved terminal diagnostic', function () {
    $old = $this->service->start($this->rule->id);
    $old->status = 'error';
    $old->next_observation_at = null;
    $old->save();
    $snapshot = $old->getRawOriginal();
    config(['local-backup-presence.manual_probe_enabled' => false]);
    expect(fn () => $this->service->restart($this->rule->id, $old->id))->toThrow(DomainException::class);
    config(['local-backup-presence.manual_probe_enabled' => true]);
    CarbonImmutable::setTestNow($this->instant->addSeconds(180));
    expect(fn () => $this->service->restart($this->rule->id, $old->id))->toThrow(DomainException::class);
    expect($old->fresh()->getRawOriginal())->toBe($snapshot);
    $new = $this->service->start($this->rule->id);
    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh())->toBeNull();
    Bus::assertNothingDispatched();
});
