<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresenceRunState;
use App\Services\ExternalBackups\LocalBackupPresenceSchedulePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

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
        'is_system' => 0,
        'is_paused' => 0,
        'task_cron' => '0 6 * * *',
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
    $this->rule->activated_at_utc = '2026-11-25 05:00:00';
    $this->rule->save();
    $this->instant = CarbonImmutable::parse('2026-11-25 06:00:00', 'UTC');
    $this->service = new LocalBackupPresenceOccurrenceClaim(new ExternalBackupFilenameMatcher);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->rollBackTransaction();
});

test('claims one pending UTC occurrence with authoritative criteria and no receipt', function () {
    $before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];
    $run = $this->service->claim($this->rule->id, $this->instant);
    expect($run->status)->toBe('pending');
    expect((int) $run->device_id)->toBe($this->device->id);
    expect($run->getRawOriginal('deadline_at_utc'))->toBe('2026-11-25 07:00:00');
    expect($run->criteria_snapshot)->toMatchArray([
        'version' => 1,
        'task_id' => $this->task->id,
        'directory_alias' => 'synthetic',
        'relative_directory' => 'firewalls/site-a',
        'filename_pattern' => 'backup_*.cfg',
        'maximum_age_seconds' => 86400,
        'quiet_seconds' => 30,
        'timezone' => 'UTC',
    ]);
    expect([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
    expect($run->isFillable('criteria_snapshot'))->toBeFalse();
});

test('replay preserves original criteria deadline and terminal outcome', function () {
    $first = $this->service->claim($this->rule->id, $this->instant);
    $first->status = 'present';
    $first->save();
    $this->rule->update(['filename_pattern' => 'different_*.cfg', 'deadline_seconds' => 1800]);
    $second = $this->service->claim($this->rule->id, $this->instant);
    expect($second->id)->toBe($first->id);
    expect($second->status)->toBe('present');
    expect($second->criteria_snapshot)->toEqual($first->criteria_snapshot);
    expect($second->getRawOriginal('deadline_at_utc'))->toBe('2026-11-25 07:00:00');
    expect($this->rule->runs()->count())->toBe(1);
});

test('configured timezone determines due minute while equivalent UTC instants deduplicate', function () {
    config(['app.timezone' => 'Europe/Rome']);
    $this->task->update(['task_cron' => '0 7 * * *']);
    $first = $this->service->claim($this->rule->id, $this->instant);
    $second = $this->service->claim($this->rule->id, $this->instant->setTimezone('Europe/Rome'));
    expect($first->id)->toBe($second->id);
    expect($first->criteria_snapshot['timezone'])->toBe('Europe/Rome');
});

test('runtime disabled and demo mode refuse claims', function () {
    config(['local-backup-presence.runtime_enabled' => false]);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    config(['local-backup-presence.runtime_enabled' => true, 'rConfig.is_demo' => true]);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect($this->rule->runs()->count())->toBe(0);
});

test('disabled or preactivation rule cannot create an occurrence', function () {
    $this->rule->enabled = false;
    $this->rule->save();
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    $this->rule->enabled = true;
    $this->rule->activated_at_utc = '2026-11-25 06:01:00';
    $this->rule->save();
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect($this->rule->runs()->count())->toBe(0);
});

test('native command paused task or extra device cannot claim a presence run', function () {
    $this->task->update(['task_command' => 'rconfig:download-device']);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    $this->task->update(['task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND, 'is_paused' => 1]);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    $this->task->update(['is_paused' => 0]);
    $this->task->device()->attach(Device::factory()->create()->id);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect($this->rule->runs()->count())->toBe(0);
});

test('off-schedule and fractional instants are refused', function () {
    expect(fn () => $this->service->claim($this->rule->id, $this->instant->addMinute()))->toThrow(DomainException::class);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant->addSecond()))->toThrow(InvalidArgumentException::class);
    expect($this->rule->runs()->count())->toBe(0);
});

test('invalid criteria fail before any occurrence is created', function () {
    $this->rule->update(['quiet_seconds' => 3600]);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    $this->rule->update(['quiet_seconds' => null, 'relative_directory' => '../escape']);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(InvalidArgumentException::class);
    $this->rule->update(['relative_directory' => '', 'pattern_mode' => 'regex', 'filename_pattern' => '(']);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(InvalidArgumentException::class);
    expect($this->rule->runs()->count())->toBe(0);
});

test('a legacy schema-only occurrence cannot be retroactively assigned criteria', function () {
    LocalBackupPresenceRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-25 06:00:00',
        'deadline_at_utc' => '2026-11-25 07:00:00',
    ]);
    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect($this->rule->runs()->first()->criteria_snapshot)->toBeNull();
});

test('scheduler reserves only due UTC occurrences using application cron timezone without dispatch', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'app.timezone' => 'Europe/Rome',
    ]);
    $this->task->update(['task_cron' => '0 7 * * *']);
    Bus::fake();
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    CarbonImmutable::setTestNow($this->instant->subMinute());
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(0);

    CarbonImmutable::setTestNow($this->instant->addSeconds(20));
    $planner->reserveDue();
    $planner->reserveDue();
    $run = $this->rule->runs()->sole();
    expect($run->status)->toBe('pending');
    expect($run->getRawOriginal('scheduled_at_utc'))->toBe('2026-11-25 06:00:00');
    expect($run->getRawOriginal('last_observed_at_utc'))->toBeNull();
    expect($run->criteria_snapshot['timezone'])->toBe('Europe/Rome');

    CarbonImmutable::setTestNow($this->instant->addDay()->addMinute());
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(1);
    Bus::assertNothingDispatched();
});

test('scheduler refuses incomplete gates demo and paused or disabled tasks', function () {
    CarbonImmutable::setTestNow($this->instant);
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    expect(config('local-backup-presence.scheduler_enabled'))->toBeFalse();
    config(['local-backup-presence.queue_enabled' => true]);
    $planner->reserveDue();
    config(['local-backup-presence.scheduler_enabled' => true, 'local-backup-presence.runtime_enabled' => false]);
    $planner->reserveDue();
    config(['local-backup-presence.runtime_enabled' => true, 'rConfig.is_demo' => true]);
    $planner->reserveDue();
    config(['rConfig.is_demo' => false, 'local-backup-presence.queue_enabled' => false]);
    $planner->reserveDue();
    config(['local-backup-presence.queue_enabled' => true]);
    $this->task->update(['is_paused' => 1]);
    $planner->reserveDue();
    $this->task->update(['is_paused' => 0]);
    $this->rule->enabled = false;
    $this->rule->save();
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(0);
});

test('scheduler refuses overcapacity without partial reservation and isolates an invalid rule', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.max_rules_per_tick' => 1,
    ]);
    CarbonImmutable::setTestNow($this->instant);
    $task = Task::factory()->create([
        'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
        'task_devices' => 1,
        'task_categories' => 0,
        'task_tags' => 0,
        'is_system' => 0,
        'is_paused' => 0,
        'task_cron' => '0 6 * * *',
    ]);
    $task->device()->attach($this->device->id);
    $other = $this->rule->replicate();
    $other->task_id = $task->id;
    $other->save();
    $this->rule->update(['quiet_seconds' => 3600]);
    Log::spy();
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(0);
    expect($other->runs()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Local backup presence scheduler capacity exceeded.', ['limit' => 1])->once();

    config(['local-backup-presence.max_rules_per_tick' => 2]);
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(0);
    expect($other->runs()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->with('Local backup presence scheduler refused a rule.', ['rule_id' => $this->rule->id])->once();
});

test('catchup restores missed due minutes once and expired runs have no historical evidence', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.catchup_enabled' => true,
    ]);
    $this->task->update(['task_cron' => '* * * * *']);
    $this->rule->update(['deadline_seconds' => 60, 'quiet_seconds' => null]);
    Bus::fake();
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    CarbonImmutable::setTestNow($this->instant);
    $planner->reserveDue();
    CarbonImmutable::setTestNow($this->instant->addMinutes(3));
    app(LocalBackupPresenceSchedulePlanner::class)->reserveDue();
    $planner->reserveDue();

    expect($this->rule->runs()->orderBy('scheduled_at_utc')->get()->map(fn ($run) => $run->getRawOriginal('scheduled_at_utc'))->all())->toBe([
        '2026-11-25 06:00:00',
        '2026-11-25 06:01:00',
        '2026-11-25 06:02:00',
        '2026-11-25 06:03:00',
    ]);
    $rule = $this->rule->fresh();
    expect($rule->getRawOriginal('scheduler_checked_minute_utc'))->toBe('2026-11-25 06:03:00');
    expect($rule->toArray())->not->toHaveKey('scheduler_revision');
    expect($rule->isFillable('scheduler_checked_minute_utc'))->toBeFalse();
    $run = $rule->runs()->orderBy('scheduled_at_utc')->firstOrFail();
    expect($run->getRawOriginal('last_observed_at_utc'))->toBeNull();
    $final = app(LocalBackupPresenceRunState::class)->expire($rule->id, $run->id, $this->instant->addMinutes(3));
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('no_observation_before_deadline');
    Bus::assertNothingDispatched();
});

test('catchup bounds long gaps and never moves its checkpoint backwards', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.catchup_enabled' => true,
    ]);
    $this->task->update(['task_cron' => '* * * * *']);
    Log::spy();
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    CarbonImmutable::setTestNow($this->instant);
    $planner->reserveDue();
    CarbonImmutable::setTestNow($this->instant->addMinutes(10));
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(6);
    expect($this->rule->runs()->where('scheduled_at_utc', '2026-11-25 06:05:00')->exists())->toBeFalse();
    expect($this->rule->runs()->where('scheduled_at_utc', '2026-11-25 06:06:00')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->with('Local backup presence catch-up window exceeded.', [
        'rule_id' => $this->rule->id,
        'omitted_from_utc' => '2026-11-25 06:01:00',
        'omitted_through_utc' => '2026-11-25 06:05:00',
    ])->once();
    CarbonImmutable::setTestNow($this->instant->addMinutes(9));
    $planner->reserveDue();
    expect($this->rule->fresh()->getRawOriginal('scheduler_checked_minute_utc'))->toBe('2026-11-25 06:10:00');
    expect($this->rule->runs()->count())->toBe(6);
});

test('catchup starts at current minute on changed criteria and does not backfill before activation', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.catchup_enabled' => true,
    ]);
    $this->task->update(['task_cron' => '* * * * *']);
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    CarbonImmutable::setTestNow($this->instant);
    $planner->reserveDue();
    $this->task->update(['is_paused' => 1]);
    CarbonImmutable::setTestNow($this->instant->addMinute());
    $planner->reserveDue();
    $this->task->update(['is_paused' => 0]);
    $this->rule->update(['filename_pattern' => 'new_*.cfg']);
    CarbonImmutable::setTestNow($this->instant->addMinutes(3));
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(2);
    expect($this->rule->runs()->orderByDesc('scheduled_at_utc')->first()->criteria_snapshot['filename_pattern'])->toBe('new_*.cfg');

    $this->rule->activated_at_utc = '2026-11-25 06:05:00';
    $this->rule->save();
    CarbonImmutable::setTestNow($this->instant->addMinutes(6));
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(3);
    expect($this->rule->runs()->where('scheduled_at_utc', '2026-11-25 06:05:00')->exists())->toBeFalse();
});

test('failed catchup reservation does not advance the checkpoint or commit partial claims', function () {
    config([
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.catchup_enabled' => true,
    ]);
    $this->task->update(['task_cron' => '* * * * *']);
    $planner = app(LocalBackupPresenceSchedulePlanner::class);
    CarbonImmutable::setTestNow($this->instant);
    $planner->reserveDue();
    // A schema-only occurrence is refused by the claim boundary.
    LocalBackupPresenceRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-25 06:02:00',
        'deadline_at_utc' => '2026-11-25 07:02:00',
    ]);
    CarbonImmutable::setTestNow($this->instant->addMinutes(3));
    $planner->reserveDue();
    expect($this->rule->runs()->count())->toBe(2);
    expect($this->rule->runs()->where('scheduled_at_utc', '2026-11-25 06:01:00')->exists())->toBeFalse();
    expect($this->rule->fresh()->getRawOriginal('scheduler_checked_minute_utc'))->toBe('2026-11-25 06:00:00');
});
