<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Models\User;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

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

function f02ApproveSyntheticActivation(): void
{
    config()->set('rConfig.is_demo', false);
    config()->set('external-backups.finalization_approved', true);
    config()->set('external-backups.runtime_enabled', true);
    config()->set('external-backups.queue_enabled', true);
    config()->set('external-backups.receipt_acceptance_enabled', true);
    config()->set('external-backups.intake_root', '/synthetic/inbox');
    config()->set('external-backups.safe_digest_binary', '/synthetic/native-helper');
    config()->set('external-backups.receipt_hmac_key', 'base64:' . base64_encode(str_repeat('x', 32)));
    config()->set('external-backups.max_digest_bytes', 104857600);
}

test('activation is admin only and requires explicit server approval', function () {
    Queue::fake();
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))->assertCreated();
    $rule = ExternalBackupRule::findOrFail($created->json('data.id'));
    $url = '/api/tasks/external-backup-rules/' . $rule->id . '/activation';

    f02ApproveSyntheticActivation();
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->patchJson($url, ['enabled' => true])->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    config()->set('external-backups.finalization_approved', false);
    $this->patchJson($url, ['enabled' => true])->assertStatus(409);
    config()->set('external-backups.finalization_approved', true);
    config()->set('external-backups.receipt_hmac_key', '');
    $this->patchJson($url, ['enabled' => true])->assertStatus(409);

    expect($rule->fresh()->enabled)->toBeFalse();
    expect((bool) $rule->task->fresh()->is_paused)->toBeTrue();
    Queue::assertNothingPushed();
});

test('administrator activates and pauses atomically without dispatching checks', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))->assertCreated();
    $rule = ExternalBackupRule::findOrFail($created->json('data.id'));
    $url = '/api/tasks/external-backup-rules/' . $rule->id . '/activation';
    f02ApproveSyntheticActivation();

    $this->patchJson($url, ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    $rule->refresh();
    expect((bool) $rule->task->fresh()->is_paused)->toBeFalse();
    $activation = CarbonImmutable::parse($rule->getRawOriginal('activated_at_utc'), 'UTC');
    expect(fn () => app(ExternalBackupOccurrenceClaimService::class)->claim($rule->id, $activation->subMinute()))
        ->toThrow(DomainException::class);
    expect($rule->runs()->count())->toBe(0);

    $this->patchJson($url, ['enabled' => true])->assertOk();
    expect($rule->fresh()->getRawOriginal('activated_at_utc'))->toBe($activation->format('Y-m-d H:i:s'));

    config()->set('external-backups.finalization_approved', false);
    $this->patchJson($url, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    expect((bool) $rule->task->fresh()->is_paused)->toBeTrue();
    Queue::assertNothingPushed();
});

test('pause refuses to abandon pending occurrences and preserves both states', function () {
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    $created = $this->postJson('/api/tasks/external-backup-rules', f02RulePayload($this->device->id))->assertCreated();
    $rule = ExternalBackupRule::findOrFail($created->json('data.id'));
    $url = '/api/tasks/external-backup-rules/' . $rule->id . '/activation';
    f02ApproveSyntheticActivation();
    $this->patchJson($url, ['enabled' => true])->assertOk();
    $instant = CarbonImmutable::parse($rule->fresh()->getRawOriginal('activated_at_utc'), 'UTC');
    app(ExternalBackupOccurrenceClaimService::class)->claim($rule->id, $instant);
    $this->patchJson($url, ['enabled' => false])->assertStatus(409);
    expect($rule->fresh()->enabled)->toBeTrue();
    expect((bool) $rule->task->fresh()->is_paused)->toBeFalse();
});
