<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use App\Services\ExternalBackups\ExternalBackupReceiptAcceptanceService;
use App\Services\ExternalBackups\ExternalBackupReceiptTokenFactory;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->beginTransaction();
    $this->device = Device::factory()->create();
    $this->task = Task::factory()->create([
        'task_command' => 'rconfig:check-external-backup',
        'is_paused' => 0,
    ]);
    $this->rule = ExternalBackupRule::query()->create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
        'enabled' => true,
    ]);
    $this->scheduled = CarbonImmutable::parse('2026-11-23 14:00:00', 'UTC');
    $this->evidence = [
        'state' => 'descriptor_checked_only',
        'sha256' => str_repeat('a', 64),
        'size' => 24,
        'device' => 12345,
        'inode' => 67890,
        'mtime' => $this->scheduled->getTimestamp() + 8,
        'ctime' => $this->scheduled->getTimestamp() + 8,
        'mtime_ns' => 123456789,
        'ctime_ns' => 789123456,
    ];
    config()->set('external-backups.receipt_acceptance_enabled', true);
    config()->set('external-backups.receipt_hmac_key', 'base64:' . base64_encode(str_repeat('T', 32)));
    $this->acceptance = new ExternalBackupReceiptAcceptanceService(new ExternalBackupReceiptTokenFactory);
    $this->states = new ExternalBackupRunStateService;
    $this->claimer = new ExternalBackupOccurrenceClaimService;
});

afterEach(function () {
    config()->set('external-backups.receipt_acceptance_enabled', false);
    config()->set('external-backups.receipt_hmac_key', '');
    $this->rollBackTransaction();
});

function f02SyntheticCandidate(
    ExternalBackupOccurrenceClaimService $claimer,
    ExternalBackupRunStateService $states,
    int $ruleId,
    CarbonImmutable $scheduled
): ExternalBackupCheckRun {
    $run = $claimer->claim($ruleId, $scheduled);
    $states->recordPending($run->id, $scheduled->addSeconds(10), 'unstable', 'backup_20261123.cfg');
    $states->recordPending($run->id, $scheduled->addSeconds(13), 'requires_safe_open', 'backup_20261123.cfg');

    return $run->fresh();
}

test('F02 commits receipt and SUCCESS atomically and identical replays are idempotent', function () {
    $run = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $this->scheduled);
    $when = $this->scheduled->addSeconds(14);
    $success = $this->acceptance->accept($run->id, $when, 'backup_20261123.cfg', $this->evidence);

    expect($success->status)->toBe('success');
    expect($success->reason)->toBe('verified_file_receipt');
    expect(ExternalBackupFileReceipt::count())->toBe(1);
    expect($success->receipt)->not->toBeNull();

    $originalUpdatedAt = $success->getRawOriginal('updated_at');
    $repeat = $this->acceptance->accept($run->id, $when->addSecond(), 'backup_20261123.cfg', $this->evidence);
    expect($repeat->id)->toBe($run->id);
    expect($repeat->getRawOriginal('updated_at'))->toBe($originalUpdatedAt);
    expect(ExternalBackupFileReceipt::count())->toBe(1);
});

test('F02 same file cannot satisfy two distinct scheduled occurrences', function () {
    $first = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $this->scheduled);
    $this->acceptance->accept($first->id, $this->scheduled->addSeconds(14), 'backup_20261123.cfg', $this->evidence);

    $secondTime = $this->scheduled->addMinute();
    $second = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $secondTime);

    expect(fn () => $this->acceptance->accept(
        $second->id, $secondTime->addSeconds(14), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class, 'already consumed');

    expect($second->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(1);
});

test('F02 receipt remains disabled unless explicitly enabled', function () {
    $run = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $this->scheduled);
    config()->set('external-backups.receipt_acceptance_enabled', false);

    expect(fn () => $this->acceptance->accept(
        $run->id, $this->scheduled->addSeconds(14), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class, 'disabled');

    expect($run->fresh()->status)->toBe('pending');
    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 cannot accept a failed, early, unobserved or expired occurrence', function () {
    $pending = $this->claimer->claim($this->rule->id, $this->scheduled);
    expect(fn () => $this->acceptance->accept(
        $pending->id, $this->scheduled->addSecond(), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class);

    $this->states->recordPending($pending->id, $this->scheduled->addSeconds(10), 'unstable', 'backup_20261123.cfg');
    expect(fn () => $this->acceptance->accept(
        $pending->id, $this->scheduled->addSeconds(11), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class);

    $this->states->expire($pending->id, $this->scheduled->addSeconds(120), 'missing');
    expect(fn () => $this->acceptance->accept(
        $pending->id, $this->scheduled->addSeconds(121), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class);

    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 token is stable for one file but changes for device, name or actual arrival', function () {
    $factory = new ExternalBackupReceiptTokenFactory;
    $token = $factory->fromDescriptorEvidence($this->device->id, 'backup_20261123.cfg', $this->evidence);

    expect(strlen($token))->toBe(64);
    expect($factory->fromDescriptorEvidence($this->device->id, 'backup_20261123.cfg', $this->evidence))->toBe($token);
    expect($factory->fromDescriptorEvidence($this->device->id + 1, 'backup_20261123.cfg', $this->evidence))->not->toBe($token);
    expect($factory->fromDescriptorEvidence($this->device->id, 'backup_20261124.cfg', $this->evidence))->not->toBe($token);

    $modified = $this->evidence;
    $modified['ctime_ns'] += 1;
    expect($factory->fromDescriptorEvidence($this->device->id, 'backup_20261123.cfg', $modified))->not->toBe($token);

    $modified['ctime_ns'] -= 1;
    $modified['sha256'] = str_repeat('b', 64);
    expect($factory->fromDescriptorEvidence($this->device->id, 'backup_20261123.cfg', $modified))->not->toBe($token);
});

test('F02 receipt rejects missing key, malformed identity or traversal', function () {
    $run = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $this->scheduled);
    config()->set('external-backups.receipt_hmac_key', '');

    expect(fn () => $this->acceptance->accept(
        $run->id, $this->scheduled->addSeconds(14), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(RuntimeException::class, 'key');

    config()->set('external-backups.receipt_hmac_key', 'base64:' . base64_encode(str_repeat('T', 32)));
    expect(fn () => $this->acceptance->accept(
        $run->id, $this->scheduled->addSeconds(14), '../backup.cfg', $this->evidence
    ))->toThrow(InvalidArgumentException::class);

    $malformed = $this->evidence;
    $malformed['state'] = 'unverified';

    expect(fn () => $this->acceptance->accept(
        $run->id, $this->scheduled->addSeconds(14), 'backup_20261123.cfg', $malformed
    ))->toThrow(InvalidArgumentException::class);

    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 stale descriptor evidence cannot satisfy a new occurrence', function () {
    $scheduled = $this->scheduled->addHour();
    $run = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $scheduled);

    expect(fn () => $this->acceptance->accept(
        $run->id, $scheduled->addSeconds(14), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class, 'not fresh');

    expect(ExternalBackupFileReceipt::count())->toBe(0);
});

test('F02 pause prevents accepting an otherwise eligible candidate', function () {
    $run = f02SyntheticCandidate($this->claimer, $this->states, $this->rule->id, $this->scheduled);
    $this->task->update(['is_paused' => 1]);

    expect(fn () => $this->acceptance->accept(
        $run->id, $this->scheduled->addSeconds(14), 'backup_20261123.cfg', $this->evidence
    ))->toThrow(DomainException::class);

    expect(ExternalBackupFileReceipt::count())->toBe(0);
});
