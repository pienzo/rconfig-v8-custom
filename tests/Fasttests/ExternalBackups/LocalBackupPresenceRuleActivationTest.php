<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresenceRuleActivation;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->beginTransaction();
    config([
        'rConfig.is_demo' => false,
        'app.timezone' => 'Europe/Rome',
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
        'local-backup-presence.catchup_enabled' => false,
        'local-backup-presence.max_rules_per_tick' => 100,
        'local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only'],
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 06:00:15', 'UTC'));
    $this->device = Device::factory()->create();
    $this->lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $this->service = app(LocalBackupPresenceRuleActivation::class);
    $this->criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'site/device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => 3600,
        'quiet_seconds' => null,
        'deadline_seconds' => 180,
        'max_entries' => 1000,
        'max_matches' => 100,
        'max_scan_millis' => 2000,
    ];
    $this->rule = $this->lifecycle->create($this->device->id, 'Synthetic activation', '* * * * *', $this->criteria);
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->rollBackTransaction();
});

test('activates only from the next UTC minute and preserves exact idempotent replay', function () {
    $rule = $this->service->change($this->rule->id, true)->fresh();
    expect($rule->enabled)->toBeTrue()
        ->and($rule->getRawOriginal('activated_at_utc'))->toBe('2026-11-25 06:01:00')
        ->and((bool) $rule->task->is_paused)->toBeFalse()
        ->and($rule->runs()->count())->toBe(0);
    $before = $rule->getRawOriginal();
    $taskBefore = $rule->task->getRawOriginal();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 06:00:40', 'UTC'));
    $same = $this->service->change($rule->id, true)->fresh();
    expect($same->getRawOriginal())->toBe($before)
        ->and($same->task->getRawOriginal())->toBe($taskBefore);
    $claimer = app(LocalBackupPresenceOccurrenceClaim::class);
    expect(fn () => $claimer->claim($rule->id, CarbonImmutable::parse('2026-11-25 06:00:00', 'UTC')))->toThrow(DomainException::class);
    Bus::assertNothingDispatched();
});

test('activation gates are strict and demo forbids changes', function (string $gate, mixed $value) {
    config([$gate => $value]);
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(DomainException::class);
    expect($this->rule->fresh()->enabled)->toBeFalse()
        ->and((bool) $this->rule->task()->firstOrFail()->is_paused)->toBeTrue();
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.queue_enabled', false],
    ['local-backup-presence.scheduler_enabled', false],
    ['local-backup-presence.dispatch_enabled', false],
    ['local-backup-presence.dispatch_enabled', 'true'],
    ['rConfig.is_demo', true],
]);

test('unapproved or unsafe mapping refuses activation without touching the filesystem', function (array $roots) {
    config(['local-backup-presence.roots' => $roots]);
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(InvalidArgumentException::class);
    expect($this->rule->fresh()->enabled)->toBeFalse();
})->with([
    [[]],
    [['different' => '/synthetic']],
    [['synthetic' => '/']],
    [['synthetic' => '/synthetic/../escape']],
    [['synthetic' => 'relative']],
    [['synthetic' => 123]],
]);

test('invalid persisted criteria or corrupted linkage fails closed', function () {
    $this->rule->update(['quiet_seconds' => 180]);
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(DomainException::class);
    $this->rule->update(['quiet_seconds' => null]);
    $this->rule->task->device()->attach(Device::factory()->create()->id);
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(DomainException::class);
    expect($this->rule->fresh()->enabled)->toBeFalse();
});

test('pending occurrences block suspension and completed history survives reactivation', function () {
    $rule = $this->service->change($this->rule->id, true)->fresh();
    $minute = CarbonImmutable::parse('2026-11-25 06:01:00', 'UTC');
    CarbonImmutable::setTestNow($minute);
    $run = app(LocalBackupPresenceOccurrenceClaim::class)->claim($rule->id, $minute);
    expect(fn () => $this->service->change($rule->id, false))->toThrow(DomainException::class);
    expect($rule->fresh()->enabled)->toBeTrue();
    $run->status = LocalBackupPresenceRun::PRESENT;
    $run->save();
    $before = $run->fresh()->getRawOriginal();

    $rule->scheduler_checked_minute_utc = '2026-11-25 06:01:00';
    $rule->scheduler_revision = str_repeat('a', 64);
    $rule->save();
    config(['local-backup-presence.runtime_enabled' => false]);
    $disabled = $this->service->change($rule->id, false)->fresh();
    expect($disabled->enabled)->toBeFalse()
        ->and((bool) $disabled->task->is_paused)->toBeTrue()
        ->and($disabled->getRawOriginal('activated_at_utc'))->toBe('2026-11-25 06:01:00')
        ->and($disabled->scheduler_checked_minute_utc)->toBeNull()
        ->and($disabled->scheduler_revision)->toBeNull();
    config(['local-backup-presence.runtime_enabled' => true]);
    CarbonImmutable::setTestNow($minute->subMinute());
    expect(fn () => $this->service->change($rule->id, true))->toThrow(DomainException::class);
    CarbonImmutable::setTestNow($minute->addMinutes(10));
    $enabled = $this->service->change($rule->id, true)->fresh();
    expect($enabled->getRawOriginal('activated_at_utc'))->toBe('2026-11-25 06:12:00')
        ->and($enabled->scheduler_checked_minute_utc)->toBeNull()
        ->and($enabled->scheduler_revision)->toBeNull()
        ->and($run->fresh()->getRawOriginal())->toBe($before);
    Bus::assertNothingDispatched();
});

test('capacity includes future enabled rules but never limits creation of disabled drafts', function () {
    config(['local-backup-presence.max_rules_per_tick' => 1]);
    $second = $this->lifecycle->create($this->device->id, 'Synthetic second', '* * * * *', $this->criteria);
    $first = $this->service->change($this->rule->id, true);
    expect(fn () => $this->service->change($second->id, true))->toThrow(DomainException::class);
    expect($second->fresh()->enabled)->toBeFalse();
    $this->service->change($first->id, false);
    expect($this->service->change($second->id, true)->enabled)->toBeTrue();
    expect(LocalBackupPresenceRule::where('enabled', true)->count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('the actual one hundred rule admission boundary rejects the next activation', function () {
    for ($index = 1; $index < 100; $index++) {
        $draft = $this->lifecycle->create($this->device->id, 'Synthetic capacity ' . $index, '* * * * *', $this->criteria);
        $this->service->change($draft->id, true);
    }
    $this->service->change($this->rule->id, true);
    $extra = $this->lifecycle->create($this->device->id, 'Synthetic excess', '* * * * *', $this->criteria);
    expect(fn () => $this->service->change($extra->id, true))->toThrow(DomainException::class);
    expect($extra->fresh()->enabled)->toBeFalse()
        ->and(LocalBackupPresenceRule::where('enabled', true)->count())->toBe(100);
    Bus::assertNothingDispatched();
});

test('invalid capacity and missing singleton leave rule and task unchanged', function () {
    config(['local-backup-presence.max_rules_per_tick' => 101]);
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(InvalidArgumentException::class);
    config(['local-backup-presence.max_rules_per_tick' => 100]);
    DB::table('local_backup_presence_activation_lock')->where('id', 1)->delete();
    expect(fn () => $this->service->change($this->rule->id, true))->toThrow(DomainException::class);
    expect($this->rule->fresh()->enabled)->toBeFalse()
        ->and((bool) $this->rule->task()->firstOrFail()->is_paused)->toBeTrue();
    Bus::assertNothingDispatched();
});
