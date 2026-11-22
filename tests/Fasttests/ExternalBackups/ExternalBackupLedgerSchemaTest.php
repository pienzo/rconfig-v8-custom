<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->beginTransaction();
    $this->task = Task::factory()->create();
    $this->device = Device::factory()->create();

    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
    ]);
});

afterEach(function () {
    $this->rollBackTransaction();
});

function externalBackupLedgerRun(ExternalBackupRule $rule, string $scheduled = '2026-11-22 15:00:00'): ExternalBackupCheckRun
{
    return ExternalBackupCheckRun::create([
        'rule_id' => $rule->id,
        'device_id' => $rule->device_id,
        'scheduled_at_utc' => $scheduled,
        'deadline_at_utc' => Carbon::parse($scheduled, 'UTC')->addHour()->format('Y-m-d H:i:s'),
    ]);
}

test('additive F02 tables exist without altering native Core task tables', function () {
    foreach (['external_backup_rules', 'external_backup_check_runs', 'external_backup_file_receipts'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('external_backup_check_runs', ['rule_id', 'device_id', 'scheduled_at_utc', 'deadline_at_utc', 'status']))->toBeTrue();
    expect(Schema::hasColumns('tasks', ['task_command', 'task_cron']))->toBeTrue();
});

test('a new rule is disabled by default and links to an existing task and device', function () {
    expect($this->rule->fresh()->enabled)->toBeFalse();
    expect($this->rule->task->id)->toBe($this->task->id);
    expect($this->rule->device->id)->toBe($this->device->id);
    expect($this->rule->fresh()->quiet_seconds)->toBe(30);
});

test('a run defaults to pending and has stable date casts', function () {
    $run = externalBackupLedgerRun($this->rule);

    expect($run->fresh()->status)->toBe('pending');
    expect($run->rule->id)->toBe($this->rule->id);
    expect($run->device->id)->toBe($this->device->id);
    expect($run->scheduled_at_utc)->not->toBeNull();
});

test('duplicate scheduled occurrences for the same rule are rejected at database level', function () {
    externalBackupLedgerRun($this->rule);

    expect(fn () => externalBackupLedgerRun($this->rule))->toThrow(QueryException::class);
});

test('distinct scheduled occurrences may exist for the same rule', function () {
    $first = externalBackupLedgerRun($this->rule);
    $second = externalBackupLedgerRun($this->rule, '2026-11-23 15:00:00');

    expect($first->id)->not->toBe($second->id);
});

test('run cannot claim a different device from its parent rule', function () {
    $otherDevice = Device::factory()->create();

    expect(fn () => ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $otherDevice->id,
        'scheduled_at_utc' => '2026-11-22 15:00:00',
        'deadline_at_utc' => '2026-11-22 16:00:00',
    ]))->toThrow(QueryException::class);
});

test('one run can hold only one accepted file receipt', function () {
    $run = externalBackupLedgerRun($this->rule);
    ExternalBackupFileReceipt::create([
        'check_run_id' => $run->id,
        'device_id' => $this->device->id,
        'verified_file_token' => str_repeat('a', 64),
        'accepted_at_utc' => '2026-11-22 15:01:00',
    ]);

    expect(fn () => ExternalBackupFileReceipt::create([
        'check_run_id' => $run->id,
        'device_id' => $this->device->id,
        'verified_file_token' => str_repeat('b', 64),
        'accepted_at_utc' => '2026-11-22 15:05:00',
    ]))->toThrow(QueryException::class);
});

test('same verified file token cannot be reused across two runs for one device', function () {
    $first = externalBackupLedgerRun($this->rule);
    $second = externalBackupLedgerRun($this->rule, '2026-11-23 15:00:00');
    $token = str_repeat('c', 64);

    ExternalBackupFileReceipt::create([
        'check_run_id' => $first->id,
        'device_id' => $this->device->id,
        'verified_file_token' => $token,
        'accepted_at_utc' => '2026-11-22 15:01:00',
    ]);

    expect(fn () => ExternalBackupFileReceipt::create([
        'check_run_id' => $second->id,
        'device_id' => $this->device->id,
        'verified_file_token' => $token,
        'accepted_at_utc' => '2026-11-23 15:01:00',
    ]))->toThrow(QueryException::class);
});

test('receipt cannot claim a different device from its run', function () {
    $run = externalBackupLedgerRun($this->rule);
    $otherDevice = Device::factory()->create();

    expect(fn () => ExternalBackupFileReceipt::create([
        'check_run_id' => $run->id,
        'device_id' => $otherDevice->id,
        'verified_file_token' => str_repeat('d', 64),
        'accepted_at_utc' => '2026-11-22 15:01:00',
    ]))->toThrow(QueryException::class);
});

test('a file token is scoped by device rather than globally', function () {
    $run = externalBackupLedgerRun($this->rule);
    $device2 = Device::factory()->create();
    $rule2 = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $device2->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
    ]);
    $run2 = externalBackupLedgerRun($rule2);
    $token = str_repeat('e', 64);

    foreach ([[$run, $this->device], [$run2, $device2]] as [$currentRun, $device]) {
        ExternalBackupFileReceipt::create([
            'check_run_id' => $currentRun->id,
            'device_id' => $device->id,
            'verified_file_token' => $token,
            'accepted_at_utc' => '2026-11-22 15:01:00',
        ]);
    }

    expect(ExternalBackupFileReceipt::where('verified_file_token', $token)->count())->toBe(2);
});

test('receipt token is hidden when model is serialized', function () {
    $run = externalBackupLedgerRun($this->rule);
    $receipt = ExternalBackupFileReceipt::create([
        'check_run_id' => $run->id,
        'device_id' => $this->device->id,
        'verified_file_token' => str_repeat('f', 64),
        'accepted_at_utc' => '2026-11-22 15:01:00',
    ]);

    expect($receipt->toArray())->not->toHaveKey('verified_file_token');
});
