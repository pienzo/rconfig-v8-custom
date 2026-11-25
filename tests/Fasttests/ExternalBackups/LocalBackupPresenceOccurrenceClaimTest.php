<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use Carbon\CarbonImmutable;

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
