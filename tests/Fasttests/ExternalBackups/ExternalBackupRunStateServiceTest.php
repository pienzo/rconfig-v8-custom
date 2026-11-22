<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
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
        'enabled' => true,
    ]);

    $this->scheduled = CarbonImmutable::parse('2026-11-22 15:00:00', 'UTC');
    $this->deadline = $this->scheduled->addHour();
    $this->first = $this->scheduled->addMinutes(5);
    $this->second = $this->first->addMinutes(2);
    $this->run = (new ExternalBackupOccurrenceClaimService)->claim($this->rule->id, $this->scheduled);
    $this->service = new ExternalBackupRunStateService;
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('pending observations preserve the first UTC instant and advance the last', function () {
    $first = $this->service->recordPending($this->run->id, $this->first, 'missing');
    $second = $this->service->recordPending($this->run->id, $this->second, 'unstable', 'synthetic_01.cfg');

    expect($second->status)->toBe('pending');
    expect($second->reason)->toBe('unstable');
    expect($second->matched_filename)->toBe('synthetic_01.cfg');
    expect($second->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-22 15:05:00');
    expect($second->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-22 15:07:00');
    expect($first->id)->toBe($second->id);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('replaying an identical pending observation is idempotent', function () {
    $first = $this->service->recordPending($this->run->id, $this->first, 'stale', 'synthetic_old.cfg');
    $updatedAt = $first->getRawOriginal('updated_at');
    $replay = $this->service->recordPending($this->run->id, $this->first, 'stale', 'synthetic_old.cfg');

    expect($replay->id)->toBe($first->id);
    expect($replay->getRawOriginal('updated_at'))->toBe($updatedAt);
    expect(ExternalBackupCheckRun::count())->toBe(1);
});

test('timezone-offset observations normalize to the same UTC instant', function () {
    $romeTime = CarbonImmutable::parse('2026-11-22 16:05:00', 'Europe/Rome');
    $result = $this->service->recordPending($this->run->id, $romeTime, 'missing');

    expect($result->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-22 15:05:00');
});

test('out-of-order observations and contradictory same-second retries fail closed', function () {
    $this->service->recordPending($this->run->id, $this->second, 'unstable', 'synthetic.cfg');

    expect(fn () => $this->service->recordPending($this->run->id, $this->first, 'missing'))->toThrow(DomainException::class);
    expect(fn () => $this->service->recordPending($this->run->id, $this->second, 'missing'))->toThrow(DomainException::class);
    expect(fn () => $this->service->recordPending($this->run->id, $this->second, 'unstable', 'different.cfg'))->toThrow(DomainException::class);
    expect($this->run->fresh()->reason)->toBe('unstable');
});

test('an observation cannot precede the scheduled window', function () {
    expect(fn () => $this->service->recordPending($this->run->id, $this->scheduled->subSecond(), 'missing'))->toThrow(DomainException::class);
    expect($this->run->fresh()->first_observed_at_utc)->toBeNull();
});

test('a pending observation cannot occur at or after its deadline', function () {
    expect(fn () => $this->service->recordPending($this->run->id, $this->deadline, 'missing'))->toThrow(DomainException::class);
    expect(fn () => $this->service->recordPending($this->run->id, $this->deadline->addSecond(), 'unstable'))->toThrow(DomainException::class);
    expect($this->run->fresh()->status)->toBe('pending');
});

test('expiration is forbidden before the deadline', function () {
    expect(fn () => $this->service->expire($this->run->id, $this->second, 'missing'))->toThrow(DomainException::class);
    expect($this->run->fresh()->status)->toBe('pending');
});

test('expiration at the deadline records terminal failure without receipt', function () {
    $this->service->recordPending($this->run->id, $this->first, 'stale', 'old.cfg');
    $terminal = $this->service->expire($this->run->id, $this->deadline, 'stale', 'old.cfg');

    expect($terminal->status)->toBe('failed');
    expect($terminal->reason)->toBe('stale');
    expect($terminal->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-22 15:05:00');
    expect($terminal->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-22 16:00:00');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('expiration without a previous observation records first and last at deadline', function () {
    $terminal = $this->service->expire($this->run->id, $this->deadline);

    expect($terminal->status)->toBe('failed');
    expect($terminal->reason)->toBe('deadline_elapsed');
    expect($terminal->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-22 16:00:00');
    expect($terminal->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-22 16:00:00');
});

test('terminal retries cannot overwrite the original failure or observation times', function () {
    $terminal = $this->service->expire($this->run->id, $this->deadline, 'missing');
    $updatedAt = $terminal->getRawOriginal('updated_at');

    $replay = $this->service->expire($this->run->id, $this->deadline->addHour(), 'unsafe_file', 'replacement.cfg');

    expect($replay->id)->toBe($terminal->id);
    expect($replay->reason)->toBe('missing');
    expect($replay->matched_filename)->toBeNull();
    expect($replay->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-22 16:00:00');
    expect($replay->getRawOriginal('updated_at'))->toBe($updatedAt);
});

test('terminal failure can never return to pending', function () {
    $this->service->expire($this->run->id, $this->deadline);

    expect(fn () => $this->service->recordPending($this->run->id, $this->deadline->addHour(), 'missing'))->toThrow(DomainException::class);
    expect($this->run->fresh()->status)->toBe('failed');
});

test('unknown success-like reasons and premature deadline markers are forbidden', function () {
    foreach (['success', 'verified', 'receipt_claimed', 'arbitrary_reason', ''] as $reason) {
        expect(fn () => $this->service->recordPending($this->run->id, $this->first, $reason))->toThrow(InvalidArgumentException::class);
        expect(fn () => $this->service->expire($this->run->id, $this->deadline, $reason))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => $this->service->recordPending($this->run->id, $this->first, 'deadline_elapsed'))->toThrow(InvalidArgumentException::class);
});

test('invalid basenames never enter the run ledger', function () {
    foreach (['../outside.cfg', 'nested/backup.cfg', 'nested\backup.cfg', '.', '..', '', "name\n.cfg", "bad_\xFF.cfg", str_repeat('a', 256)] as $filename) {
        expect(fn () => $this->service->recordPending($this->run->id, $this->first, 'unstable', $filename))->toThrow(InvalidArgumentException::class);
    }

    expect($this->run->fresh()->matched_filename)->toBeNull();
});

test('invalid run identifiers and subsecond instants are rejected', function () {
    expect(fn () => $this->service->recordPending(0, $this->first, 'missing'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->recordPending(PHP_INT_MAX, $this->first, 'missing'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->service->recordPending($this->run->id, $this->first->addMicrosecond(), 'missing'))->toThrow(InvalidArgumentException::class);
});

test('an unexpected run status refuses metadata transitions', function () {
    $this->run->update(['status' => 'success']);

    expect(fn () => $this->service->recordPending($this->run->id, $this->first, 'missing'))->toThrow(DomainException::class);
    expect(fn () => $this->service->expire($this->run->id, $this->deadline))->toThrow(DomainException::class);
    expect($this->run->fresh()->status)->toBe('success'); // Existing corruption is not modified by this service.
});

test('a receipt present on an occurrence blocks all metadata transitions', function () {
    ExternalBackupFileReceipt::create([
        'check_run_id' => $this->run->id,
        'device_id' => $this->device->id,
        'verified_file_token' => str_repeat('f', 64),
        'accepted_at_utc' => '2026-11-22 15:05:00',
    ]);

    expect(fn () => $this->service->recordPending($this->run->id, $this->first, 'missing'))->toThrow(DomainException::class);
    expect(fn () => $this->service->expire($this->run->id, $this->deadline))->toThrow(DomainException::class);
});

test('two devices retain independent failure timelines and no file receipts', function () {
    $device2 = Device::factory()->create();
    $rule2 = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $device2->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'enabled' => true,
    ]);
    $run2 = (new ExternalBackupOccurrenceClaimService)->claim($rule2->id, $this->scheduled);

    $this->service->recordPending($this->run->id, $this->first, 'stale', 'one.cfg');
    $this->service->expire($run2->id, $this->deadline, 'missing');

    expect($this->run->fresh()->status)->toBe('pending');
    expect($run2->fresh()->status)->toBe('failed');
    expect($this->run->fresh()->reason)->toBe('stale');
    expect($run2->fresh()->reason)->toBe('missing');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
