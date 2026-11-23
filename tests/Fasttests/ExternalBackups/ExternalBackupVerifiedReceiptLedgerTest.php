<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupVerifiedReceiptLedger;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->beginTransaction();
    config()->set('external-backups.receipt_acceptance_enabled', false);

    $this->device = Device::factory()->create();
    $this->task = Task::factory()->create();
    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'enabled' => true,
    ]);
    $this->ledger = new ExternalBackupVerifiedReceiptLedger;
    $this->acceptedAt = CarbonImmutable::parse('2026-11-23 21:01:00', 'UTC');
    $this->token = str_repeat('a', 64);
});

afterEach(function () {
    config()->set('external-backups.receipt_acceptance_enabled', false);
    $this->rollBackTransaction();
});

function f02ReceiptFixtureRun(ExternalBackupRule $rule, string $scheduled = '2026-11-23 21:00:00'): ExternalBackupCheckRun
{
    $start = CarbonImmutable::parse($scheduled, 'UTC');

    return ExternalBackupCheckRun::create([
        'rule_id' => $rule->id,
        'device_id' => $rule->device_id,
        'status' => 'pending',
        'reason' => 'requires_safe_open',
        'matched_filename' => 'synthetic.cfg',
        'scheduled_at_utc' => $start->format('Y-m-d H:i:s'),
        'deadline_at_utc' => $start->addMinutes(15)->format('Y-m-d H:i:s'),
        'first_observed_at_utc' => $start->addSeconds(10)->format('Y-m-d H:i:s'),
        'last_observed_at_utc' => $start->addSeconds(40)->format('Y-m-d H:i:s'),
    ]);
}

test('F02 receipt acceptance is disabled by default and writes no success', function () {
    $run = f02ReceiptFixtureRun($this->rule);

    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', $this->token))
        ->toThrow(DomainException::class, 'disabled');
    expect($run->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('trusted receipt and success are inserted atomically, identical retry is idempotent', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $run = f02ReceiptFixtureRun($this->rule);

    $first = $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', $this->token);
    $second = $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', $this->token);

    expect($first->status)->toBe('success');
    expect($second->status)->toBe('success');
    expect(ExternalBackupFileReceipt::count())->toBe(1);
    expect($run->fresh()->receipt->verified_file_token)->toBe($this->token);
});

test('same file token cannot be consumed by another occurrence for the same device', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $first = f02ReceiptFixtureRun($this->rule);
    $this->ledger->accept($first->id, $this->acceptedAt, 'synthetic.cfg', $this->token);

    $second = f02ReceiptFixtureRun($this->rule, '2026-11-23 21:20:00');
    expect(fn () => $this->ledger->accept($second->id, $this->acceptedAt->addMinutes(20), 'synthetic.cfg', $this->token))
        ->toThrow(RuntimeException::class, 'already been claimed');
    expect($second->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(1);
});

test('failed run never transitions back to success', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $run = f02ReceiptFixtureRun($this->rule);
    $run->update(['status' => 'failed', 'reason' => 'missing']);

    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', $this->token))
        ->toThrow(DomainException::class);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('unobserved run and mismatched filename cannot claim a receipt', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $run = f02ReceiptFixtureRun($this->rule);
    $run->update(['reason' => 'unstable']);

    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', $this->token))
        ->toThrow(DomainException::class);

    $run->update(['reason' => 'requires_safe_open']);
    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, 'another.cfg', $this->token))
        ->toThrow(DomainException::class);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('receipt cannot be accepted on or after deadline', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $run = f02ReceiptFixtureRun($this->rule);
    $late = CarbonImmutable::parse('2026-11-23 21:15:00', 'UTC');

    expect(fn () => $this->ledger->accept($run->id, $late, 'synthetic.cfg', $this->token))
        ->toThrow(DomainException::class);
    expect($run->fresh()->status)->toBe('pending');
});

test('invalid receipt token and unsafe basename are rejected before DB write', function () {
    config()->set('external-backups.receipt_acceptance_enabled', true);
    $run = f02ReceiptFixtureRun($this->rule);

    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, 'synthetic.cfg', 'untrusted'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->ledger->accept($run->id, $this->acceptedAt, '../synthetic.cfg', $this->token))
        ->toThrow(InvalidArgumentException::class);
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
