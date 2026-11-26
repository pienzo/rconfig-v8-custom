<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
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
