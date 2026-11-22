<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->beginTransaction();

    $this->task = Task::factory()->create();
    $this->device = Device::factory()->create();
    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'deadline_seconds' => 3600,
        'enabled' => true,
    ]);
    $this->service = new ExternalBackupOccurrenceClaimService;
    $this->instant = CarbonImmutable::parse('2026-11-22 15:00:00', 'UTC');
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('claim creates a pending UTC occurrence linked to authoritative rule and device', function () {
    $run = $this->service->claim($this->rule->id, $this->instant);

    expect((int) $run->rule_id)->toBe($this->rule->id);
    expect((int) $run->device_id)->toBe($this->device->id);
    expect($run->fresh()->status)->toBe('pending');
    expect($run->scheduled_at_utc->format('Y-m-d H:i:s'))->toBe('2026-11-22 15:00:00');
    expect($run->deadline_at_utc->format('Y-m-d H:i:s'))->toBe('2026-11-22 16:00:00');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('retrying the same scheduled instant returns the same row without creating a duplicate', function () {
    $first = $this->service->claim($this->rule->id, $this->instant);
    $second = $this->service->claim($this->rule->id, $this->instant);

    expect($second->id)->toBe($first->id);
    expect(ExternalBackupCheckRun::count())->toBe(1);
});

test('two separate UTC instants produce distinct occurrences', function () {
    $first = $this->service->claim($this->rule->id, $this->instant);
    $second = $this->service->claim($this->rule->id, $this->instant->addHour());

    expect($second->id)->not->toBe($first->id);
    expect(ExternalBackupCheckRun::count())->toBe(2);
});

test('equivalent timezone-offset instants cannot create duplicate occurrences', function () {
    $first = $this->service->claim($this->rule->id, $this->instant);
    $romeTime = CarbonImmutable::parse('2026-11-22 16:00:00', 'Europe/Rome');
    $second = $this->service->claim($this->rule->id, $romeTime);

    expect($second->id)->toBe($first->id);
    expect(ExternalBackupCheckRun::count())->toBe(1);
});

test('retries do not reset a terminal status, reason, deadline or observation', function () {
    $first = $this->service->claim($this->rule->id, $this->instant);
    $first->update([
        'status' => 'failed',
        'reason' => 'missing',
        'matched_filename' => 'synthetic_first.cfg',
    ]);
    $this->rule->update(['deadline_seconds' => 1800]);

    $retried = $this->service->claim($this->rule->id, $this->instant);

    expect($retried->id)->toBe($first->id);
    expect($retried->status)->toBe('failed');
    expect($retried->reason)->toBe('missing');
    expect($retried->matched_filename)->toBe('synthetic_first.cfg');
    expect($retried->deadline_at_utc->format('Y-m-d H:i:s'))->toBe('2026-11-22 16:00:00');
});

test('a disabled rule cannot create a new occurrence', function () {
    $this->rule->update(['enabled' => false]);

    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect(ExternalBackupCheckRun::count())->toBe(0);
});

test('missing rule and invalid IDs fail without creating a row', function () {
    expect(fn () => $this->service->claim(0, $this->instant))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->claim(PHP_INT_MAX, $this->instant))->toThrow(ModelNotFoundException::class);
    expect(ExternalBackupCheckRun::count())->toBe(0);
});

test('subsecond instants are refused rather than being silently rounded into a duplicate', function () {
    $fractional = CarbonImmutable::parse('2026-11-22 15:00:00.123456', 'UTC');

    expect(fn () => $this->service->claim($this->rule->id, $fractional))->toThrow(InvalidArgumentException::class);
    expect(ExternalBackupCheckRun::count())->toBe(0);
});

test('an invalid deadline configuration fails closed without inserting', function () {
    $this->rule->update(['deadline_seconds' => 0]);

    expect(fn () => $this->service->claim($this->rule->id, $this->instant))->toThrow(DomainException::class);
    expect(ExternalBackupCheckRun::count())->toBe(0);
});

test('device assignment always comes from locked DB rule rather than caller-supplied stale model', function () {
    $other = Device::factory()->create();
    $this->rule->device_id = $other->id;

    $run = $this->service->claim($this->rule->id, $this->instant);

    expect((int) $run->device_id)->toBe($this->device->id);
});

test('different rules may claim the same UTC instant without sharing an occurrence', function () {
    $other = Device::factory()->create();
    $secondRule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $other->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'config_*.cfg',
        'enabled' => true,
    ]);

    $first = $this->service->claim($this->rule->id, $this->instant);
    $second = $this->service->claim($secondRule->id, $this->instant);

    expect($first->id)->not->toBe($second->id);
    expect(ExternalBackupCheckRun::count())->toBe(2);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
