<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresenceRunState;
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
    $this->state = new LocalBackupPresenceRunState(new ExternalBackupFilenameMatcher);
    $this->run = $this->service->claim($this->rule->id, $this->instant);
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('absence and stability waiting remain pending until terminal evidence', function () {
    $missing = ['status' => 'absent', 'reason' => 'no_matching_file', 'filename' => null];
    $pending = $this->state->record($this->rule->id, $this->run->id, $this->instant, $missing);
    expect($pending->status)->toBe('pending');
    expect($pending->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-25 06:00:00');

    $waiting = ['status' => 'pending', 'reason' => 'stability_wait', 'filename' => 'backup_a.cfg'];
    $next = $this->state->record($this->rule->id, $this->run->id, $this->instant->addSeconds(30), $waiting);
    expect($next->status)->toBe('pending');
    expect($next->reason)->toBe('stability_wait');

    $result = ['status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup_a.cfg'];
    $final = $this->state->record($this->rule->id, $this->run->id, $this->instant->addMinute(), $result);
    expect($final->status)->toBe('present');
    expect($final->matched_filename)->toBe('backup_a.cfg');
    expect($final->getRawOriginal('first_observed_at_utc'))->toBe('2026-11-25 06:00:00');
    expect($final->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-25 06:01:00');
});

test('terminal presence survives a later retry and expiry unchanged', function () {
    $result = ['status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup_a.cfg'];
    $first = $this->state->record($this->rule->id, $this->run->id, $this->instant->addMinute(), $result);
    $replay = $this->state->expire($this->rule->id, $this->run->id, $this->instant->addHour());
    expect($replay->getAttributes())->toEqual($first->getAttributes());
});

test('missing file at deadline becomes absent without inventing a new scan', function () {
    $result = ['status' => 'absent', 'reason' => 'no_eligible_file', 'filename' => null];
    $this->state->record($this->rule->id, $this->run->id, $this->instant, $result);
    expect(fn () => $this->state->expire($this->rule->id, $this->run->id, $this->instant->addSeconds(3599)))->toThrow(DomainException::class);
    $final = $this->state->expire($this->rule->id, $this->run->id, $this->instant->addHour());
    expect($final->status)->toBe('absent');
    expect($final->reason)->toBe('no_eligible_file');
    expect($final->getRawOriginal('last_observed_at_utc'))->toBe('2026-11-25 06:00:00');
});

test('no observation by deadline is monitoring error not backup absence', function () {
    $final = $this->state->expire($this->rule->id, $this->run->id, $this->instant->addHour());
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('no_observation_before_deadline');
    expect($final->last_observed_at_utc)->toBeNull();
});

test('stability timeout has a distinct reason', function () {
    $this->state->record($this->rule->id, $this->run->id, $this->instant, [
        'status' => 'pending', 'reason' => 'stability_wait', 'filename' => 'backup_a.cfg',
    ]);
    $final = $this->state->expire($this->rule->id, $this->run->id, $this->instant->addHour());
    expect($final->status)->toBe('absent');
    expect($final->reason)->toBe('stability_deadline_elapsed');
});

test('sanitized scan error is terminal and distinct from missing file', function () {
    $final = $this->state->record($this->rule->id, $this->run->id, $this->instant, [
        'status' => 'error', 'reason' => 'scan_failed', 'filename' => null,
    ]);
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('scan_failed');
});

test('late evidence cannot become success at or after deadline', function () {
    $result = ['status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup_a.cfg'];
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant->addHour(), $result))->toThrow(DomainException::class);
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant->addHours(2), $result))->toThrow(DomainException::class);
    expect($this->run->fresh()->status)->toBe('pending');
});

test('same-instant pending replay is idempotent and conflicting or older evidence fails', function () {
    $result = ['status' => 'absent', 'reason' => 'no_matching_file', 'filename' => null];
    $at = $this->instant->addMinute();
    $first = $this->state->record($this->rule->id, $this->run->id, $at, $result);
    $second = $this->state->record($this->rule->id, $this->run->id, $at, $result);
    expect($second->getAttributes())->toEqual($first->getAttributes());
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $at, array_replace($result, ['reason' => 'no_eligible_file'])))->toThrow(DomainException::class);
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant, $result))->toThrow(DomainException::class);
});

test('invalid status reason path or pattern mismatch is rejected', function (array $result) {
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant, $result))->toThrow(InvalidArgumentException::class);
    expect($this->run->fresh()->status)->toBe('pending');
})->with([
    [['status' => 'success', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup_a.cfg']],
    [['status' => 'present', 'reason' => 'matching_file_observed', 'filename' => 'backup_a.cfg']],
    [['status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => '../backup_a.cfg']],
    [['status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'other.cfg']],
    [['status' => 'error', 'reason' => '/private/path', 'filename' => null]],
]);

test('disabled runtime and changed activation reject new observations', function () {
    $result = ['status' => 'absent', 'reason' => 'no_matching_file', 'filename' => null];
    config(['local-backup-presence.runtime_enabled' => false]);
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant, $result))->toThrow(DomainException::class);
    config(['local-backup-presence.runtime_enabled' => true]);
    $this->rule->activated_at_utc = '2026-11-25 05:30:00';
    $this->rule->save();
    expect(fn () => $this->state->record($this->rule->id, $this->run->id, $this->instant, $result))->toThrow(DomainException::class);
});

test('presence transition creates no strict receipt or run', function () {
    $before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];
    $this->state->record($this->rule->id, $this->run->id, $this->instant, [
        'status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup_a.cfg',
    ]);
    expect([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
});
