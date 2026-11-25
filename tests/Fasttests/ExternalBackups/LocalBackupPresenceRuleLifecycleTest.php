<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->beginTransaction();
    config(['rConfig.is_demo' => false, 'local-backup-presence.runtime_enabled' => false, 'local-backup-presence.roots' => []]);
    $this->device = Device::factory()->create();
    $this->service = app(LocalBackupPresenceRuleLifecycle::class);
    $this->criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'site/device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => null,
        'deadline_seconds' => 180,
        'max_entries' => 1000,
        'max_matches' => 100,
        'max_scan_millis' => 2000,
    ];
    Bus::fake();
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('creates updates and deletes a dormant presence rule without IO or dispatch', function () {
    $rule = $this->service->create($this->device->id, 'Synthetic draft', '0 6 * * *', $this->criteria);
    $taskId = $rule->task_id;
    expect($rule->enabled)->toBeFalse()
        ->and($rule->activated_at_utc)->toBeNull()
        ->and($rule->maximum_age_seconds)->toBeNull()
        ->and($rule->quiet_seconds)->toBeNull()
        ->and($rule->task->task_command)->toBe(LocalBackupPresenceOccurrenceClaim::TASK_COMMAND)
        ->and((bool) $rule->task->is_paused)->toBeTrue()
        ->and($rule->task->device()->pluck('devices.id')->map(fn ($id): int => (int) $id)->all())->toBe([$this->device->id]);
    $rule->scheduler_checked_minute_utc = '2026-11-25 06:00:00';
    $rule->scheduler_revision = str_repeat('a', 64);
    $rule->save();

    $updated = $this->service->update($rule->id, 'Synthetic edited', '5 6 * * *', array_replace($this->criteria, ['filename_pattern' => 'other_*.cfg']));
    expect($updated->filename_pattern)->toBe('other_*.cfg')
        ->and($updated->task->task_name)->toBe('Synthetic edited')
        ->and($updated->task->task_cron)->toBe(['5', '6', '*', '*', '*'])
        ->and($updated->scheduler_checked_minute_utc)->toBeNull()
        ->and($updated->scheduler_revision)->toBeNull()
        ->and($updated->enabled)->toBeFalse()
        ->and($updated->runs()->count())->toBe(0);

    $this->service->delete($updated->id);
    $this->assertDatabaseMissing('local_backup_presence_rules', ['id' => $rule->id]);
    $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    $this->assertDatabaseMissing('device_task', ['task_id' => $taskId]);
    $this->assertDatabaseHas('devices', ['id' => $this->device->id]);
    Bus::assertNothingDispatched();
});

test('invalid criteria or extra fields are rejected before task creation', function (array $override, string $exception) {
    $before = [Task::count(), LocalBackupPresenceRule::count()];
    expect(fn () => $this->service->create($this->device->id, 'Synthetic invalid', '0 6 * * *', array_replace($this->criteria, $override)))->toThrow($exception);
    expect([Task::count(), LocalBackupPresenceRule::count()])->toBe($before);
    Bus::assertNothingDispatched();
})->with([
    'traversal' => [['relative_directory' => '../escape'], InvalidArgumentException::class],
    'regex syntax' => [['pattern_mode' => 'regex', 'filename_pattern' => '('], InvalidArgumentException::class],
    'injected enable' => [['enabled' => true], InvalidArgumentException::class],
    'numeric string' => [['minimum_bytes' => '1'], InvalidArgumentException::class],
    'quiet exceeds deadline' => [['quiet_seconds' => 180], DomainException::class],
    'match limit exceeds entries' => [['max_entries' => 1], DomainException::class],
]);

test('invalid schedules and demo mode cannot persist drafts', function () {
    $before = [Task::count(), LocalBackupPresenceRule::count()];
    expect(fn () => $this->service->create($this->device->id, 'Synthetic invalid', '0 6 * * * extra', $this->criteria))->toThrow(InvalidArgumentException::class);
    config(['rConfig.is_demo' => true]);
    expect(fn () => $this->service->create($this->device->id, 'Synthetic demo', '0 6 * * *', $this->criteria))->toThrow(DomainException::class);
    expect([Task::count(), LocalBackupPresenceRule::count()])->toBe($before);
});

test('active previously activated history-bearing or inconsistently linked rules cannot be edited or deleted', function (string $state) {
    $rule = $this->service->create($this->device->id, 'Synthetic protected', '0 6 * * *', $this->criteria);
    if ($state === 'active') {
        $rule->enabled = true;
        $rule->save();
    } elseif ($state === 'activated') {
        $rule->activated_at_utc = '2026-11-25 05:00:00';
        $rule->save();
    } elseif ($state === 'history') {
        LocalBackupPresenceRun::create([
            'rule_id' => $rule->id,
            'device_id' => $rule->device_id,
            'scheduled_at_utc' => '2026-11-25 06:00:00',
            'deadline_at_utc' => '2026-11-25 06:03:00',
        ]);
    } elseif ($state === 'linkage') {
        $rule->task->device()->attach(Device::factory()->create()->id);
    } else {
        config(['rConfig.is_demo' => true]);
    }
    $before = $rule->fresh()->getRawOriginal();
    $taskBefore = $rule->task()->firstOrFail()->getRawOriginal();
    expect(fn () => $this->service->update($rule->id, 'Synthetic refused', '5 6 * * *', $this->criteria))->toThrow(DomainException::class);
    expect(fn () => $this->service->delete($rule->id))->toThrow(DomainException::class);
    expect($rule->fresh()->getRawOriginal())->toBe($before)
        ->and($rule->task()->firstOrFail()->getRawOriginal())->toBe($taskBefore);
    Bus::assertNothingDispatched();
})->with(['active', 'activated', 'history', 'linkage', 'demo']);
