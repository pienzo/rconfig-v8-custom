<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\ExternalBackupRule;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->beginTransaction();
    $this->task = Task::factory()->create(['is_paused' => 1]);
    $this->device = Device::factory()->create();
    $this->attributes = [
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'directory_alias' => 'synthetic',
        'relative_directory' => 'firewalls/site-a',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
    ];
    $this->rule = LocalBackupPresenceRule::create($this->attributes);
    $this->occurrence = [
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-25 06:00:00',
        'deadline_at_utc' => '2026-11-25 07:00:00',
    ];
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('local presence schema is additive and starts disabled with sensible defaults', function () {
    expect(Schema::hasTable('local_backup_presence_rules'))->toBeTrue();
    expect(Schema::hasTable('local_backup_presence_runs'))->toBeTrue();
    $rule = $this->rule->fresh();
    expect($rule->enabled)->toBeFalse();
    expect($rule->activated_at_utc)->toBeNull();
    expect($rule->maximum_age_seconds)->toBe(86400);
    expect($rule->minimum_bytes)->toBe(1);
    expect($rule->quiet_seconds)->toBe(30);
    expect($rule->task->id)->toBe($this->task->id);
    expect($rule->device->id)->toBe($this->device->id);
});

test('presence rules may explicitly opt out of age or quiet criteria', function () {
    $this->rule->update(['maximum_age_seconds' => null, 'quiet_seconds' => null]);
    expect($this->rule->fresh()->maximum_age_seconds)->toBeNull();
    expect($this->rule->fresh()->quiet_seconds)->toBeNull();
});

test('rule creation cannot mass assign activation', function () {
    $rule = new LocalBackupPresenceRule;
    expect($rule->isFillable('enabled'))->toBeFalse();
    expect($rule->isFillable('activated_at_utc'))->toBeFalse();
});

test('one local presence rule per native task is enforced', function () {
    expect(fn () => LocalBackupPresenceRule::create($this->attributes))->toThrow(QueryException::class);
});

test('multiple tasks can share an authorized directory', function () {
    $secondTask = Task::factory()->create(['is_paused' => 1]);
    $second = LocalBackupPresenceRule::create(array_replace($this->attributes, [
        'task_id' => $secondTask->id,
        'filename_pattern' => 'switch_*.cfg',
    ]));
    expect($second->directory_alias)->toBe($this->rule->directory_alias);
    expect($second->relative_directory)->toBe($this->rule->relative_directory);
    expect($second->id)->not->toBe($this->rule->id);
});

test('one pending occurrence per rule and UTC schedule is enforced', function () {
    $run = LocalBackupPresenceRun::create($this->occurrence)->fresh();
    expect($run->status)->toBe(LocalBackupPresenceRun::PENDING);
    expect($run->rule->id)->toBe($this->rule->id);
    expect($run->device->id)->toBe($this->device->id);
    expect($run->matched_filename)->toBeNull();
    expect($run->last_observed_at_utc)->toBeNull();
    expect(fn () => LocalBackupPresenceRun::create($this->occurrence))->toThrow(QueryException::class);
});

test('different scheduled occurrences of the same rule can coexist', function () {
    $first = LocalBackupPresenceRun::create($this->occurrence);
    $second = LocalBackupPresenceRun::create(array_replace($this->occurrence, [
        'scheduled_at_utc' => '2026-11-26 06:00:00',
        'deadline_at_utc' => '2026-11-26 07:00:00',
    ]));
    expect($first->id)->not->toBe($second->id);
    expect($this->rule->runs()->count())->toBe(2);
});

test('a run cannot claim another device and history prevents deletion of its rule', function () {
    $other = Device::factory()->create();
    expect(fn () => LocalBackupPresenceRun::create(array_replace($this->occurrence, [
        'device_id' => $other->id,
    ])))->toThrow(QueryException::class);
    LocalBackupPresenceRun::create($this->occurrence);
    expect(fn () => $this->rule->delete())->toThrow(QueryException::class);
});

test('presence outcome cannot be mass assigned and verified success is not a presence status', function () {
    $run = LocalBackupPresenceRun::create($this->occurrence);
    foreach (['status', 'reason', 'matched_filename', 'first_observed_at_utc', 'last_observed_at_utc'] as $field) {
        expect($run->isFillable($field))->toBeFalse();
    }
    expect(fn () => DB::table('local_backup_presence_runs')->where('id', $run->id)->update(['status' => 'success']))->toThrow(QueryException::class);
    expect($run->fresh()->status)->toBe(LocalBackupPresenceRun::PENDING);
});

test('database rejects the empty enum sentinel and invalid insert without changing core SQL mode', function () {
    $run = LocalBackupPresenceRun::create($this->occurrence);
    expect(fn () => DB::table('local_backup_presence_runs')->where('id', $run->id)->update(['status' => '']))->toThrow(QueryException::class);
    expect($run->fresh()->status)->toBe(LocalBackupPresenceRun::PENDING);

    expect(fn () => DB::table('local_backup_presence_runs')->insert(array_replace($this->occurrence, [
        'scheduled_at_utc' => '2026-11-26 06:00:00',
        'deadline_at_utc' => '2026-11-26 07:00:00',
        'status' => 'success',
    ])))->toThrow(QueryException::class);

    expect($this->rule->runs()->count())->toBe(1);
});

test('database accepts all four presence status values', function (string $status) {
    $run = LocalBackupPresenceRun::create($this->occurrence);
    DB::table('local_backup_presence_runs')->where('id', $run->id)->update(['status' => $status]);
    expect($run->fresh()->status)->toBe($status);
})->with(['pending', 'present', 'absent', 'error']);

test('presence ledger has no side effects on strict rule run and receipt ledgers', function () {
    $before = [ExternalBackupRule::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];
    LocalBackupPresenceRun::create($this->occurrence);
    expect([ExternalBackupRule::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
});
