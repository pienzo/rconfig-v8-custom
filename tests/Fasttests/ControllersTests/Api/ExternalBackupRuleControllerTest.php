<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->beginTransaction();
    $this->actingAs(User::factory()->create());
    $this->device = Device::factory()->create();
});

afterEach(function () {
    $this->rollBackTransaction();
});

function f02RulePayload(int $deviceId, array $changes = []): array
{
    return array_merge([
        'device_id' => $deviceId,
        'task_name' => 'External backup sample ' . rand(100000, 999999),
        'task_cron' => ['0', '2', '*', '*', '*'],
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'quiet_seconds' => 30,
        'deadline_seconds' => 3600,
    ], $changes);
}

test('F02 create atomically stores a paused Core task and a disabled rule', function () {
    $response = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))
        ->assertCreated()
        ->assertJsonPath('data.enabled', false);

    $rule = ExternalBackupRule::findOrFail($response->json('data.id'));
    $task = Task::findOrFail($rule->task_id);

    expect($rule->device_id)->toBe($this->device->id);
    expect($task->task_command)->toBe('rconfig:check-external-backup');
    expect((bool) $task->is_paused)->toBeTrue();
    expect($task->device->pluck('id')->all())->toBe([$this->device->id]);
    expect(ExternalBackupCheckRun::count())->toBe(0);

    $this->getJson('/api/tasks/external-backup-rules')
        ->assertOk()
        ->assertJsonFragment(['filename_pattern' => 'backup_*.cfg']);
});

test('F02 API rejects unsafe patterns invalid devices and enabled flag without storing tasks', function () {
    $before = Task::count();

    $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id, [
        'pattern_mode' => 'regex', 'filename_pattern' => '(',
    ]))->assertUnprocessable()->assertJsonValidationErrors('filename_pattern');

    $this->postJson('/api/tasks/external-backup-rules', f02RulePayload(99999999))
        ->assertUnprocessable()->assertJsonValidationErrors('device_id');

    $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id, ['enabled' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('enabled');

    $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id, [
        'quiet_seconds' => 3600, 'deadline_seconds' => 3600,
    ]))->assertUnprocessable()->assertJsonValidationErrors('quiet_seconds');

    expect(Task::count())->toBe($before);
});

test('F02 updates a dormant rule but never changes its device or enables execution', function () {
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))
        ->assertCreated();
    $id = $created->json('data.id');
    $rule = ExternalBackupRule::findOrFail($id);

    $payload = f02RulePayload($this->device->id, [
        'task_name' => 'Updated external backup rule',
        'pattern_mode' => 'regex',
        'filename_pattern' => 'backup_\\d{8}\\.cfg',
    ]);
    unset($payload['device_id']);

    $this->patchJson('/api/tasks/external-backup-rules/' . $id, $payload)
        ->assertOk()
        ->assertJsonPath('data.filename_pattern', 'backup_\\d{8}\\.cfg');

    expect($rule->fresh()->enabled)->toBeFalse();
    expect($rule->task->fresh()->task_name)->toBe('Updated external backup rule');
    expect((bool) $rule->task->fresh()->is_paused)->toBeTrue();
    expect($rule->fresh()->device_id)->toBe($this->device->id);
});

test('F02 native endpoints cannot edit delete unpause or dispatch a dormant external check', function () {
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))->assertCreated();
    $rule = ExternalBackupRule::findOrFail($created->json('data.id'));
    $taskId = $rule->task_id;

    $this->postJson('/api/tasks/run-manual-task', ['id' => $taskId])->assertStatus(409);
    $this->getJson('/api/tasks/toggle-pause-task/' . $taskId)->assertStatus(409);
    $this->patchJson('/api/tasks/' . $taskId, ['task_name' => 'native-edit'])->assertUnprocessable();
    $this->deleteJson('/api/tasks/' . $taskId)->assertStatus(409);

    expect(Task::findOrFail($taskId)->is_paused)->toBe(1);
    expect(ExternalBackupRule::findOrFail($rule->id)->enabled)->toBeFalse();

    $this->deleteJson('/api/tasks/external-backup-rules/' . $rule->id)->assertOk();
    $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    $this->assertDatabaseMissing('external_backup_rules', ['id' => $rule->id]);
});

test('an F02 rule with run history cannot be updated or deleted', function () {
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))->assertCreated();
    $rule = ExternalBackupRule::findOrFail($created->json('data.id'));
    ExternalBackupCheckRun::create([
        'rule_id' => $rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-23 08:00:00',
        'deadline_at_utc' => '2026-11-23 09:00:00',
    ]);

    $patch = f02RulePayload($this->device->id);
    unset($patch['device_id']);
    $this->patchJson('/api/tasks/external-backup-rules/' . $rule->id, $patch)->assertStatus(409);
    $this->deleteJson('/api/tasks/external-backup-rules/' . $rule->id)->assertStatus(409);
});
