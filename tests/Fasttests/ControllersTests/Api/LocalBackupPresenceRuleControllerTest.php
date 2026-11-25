<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->beginTransaction();
    config(['rConfig.is_demo' => false, 'local-backup-presence.runtime_enabled' => false,
        'local-backup-presence.roots' => ['synthetic' => '/synthetic/private-root']]);
    $this->device = Device::factory()->create();
    $this->admin = User::factory()->create(['role' => 'Admin']);
    $this->payload = [
        'device_id' => $this->device->id,
        'task_name' => 'Synthetic API draft',
        'task_cron' => ['0', '6', '*', '*', '*'],
        'directory_alias' => 'synthetic',
        'relative_directory' => '',
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
    $this->url = '/api/tasks/local-backup-presence-rules';
    Bus::fake();
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('local presence API requires authentication', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->postJson($this->url, $this->payload)->assertUnauthorized();
    expect(LocalBackupPresenceRule::where('device_id', $this->device->id)->exists())->toBeFalse();
});

test('non administrators cannot read or mutate local presence rules', function () {
    $this->actingAs($this->admin);
    $id = $this->postJson($this->url, $this->payload)->assertCreated()->json('data.id');
    $rule = LocalBackupPresenceRule::findOrFail($id);
    $before = $rule->getRawOriginal();
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->getJson($this->url)->assertForbidden();
    $this->getJson($this->url . '/' . $id)->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertForbidden();
    $this->patchJson($this->url . '/' . $id, [])->assertForbidden();
    $this->deleteJson($this->url . '/' . $id)->assertForbidden();
    expect($rule->fresh()->getRawOriginal())->toBe($before);
    Bus::assertNothingDispatched();
});

test('administrator can manage a dormant draft without leaking paths or credentials', function () {
    $this->actingAs($this->admin);
    $response = $this->postJson($this->url, $this->payload)->assertCreated()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.activated_at_utc', null)
        ->assertJsonPath('data.relative_directory', '')
        ->assertJsonPath('data.maximum_age_seconds', null)
        ->assertJsonPath('data.quiet_seconds', null);
    $id = $response->json('data.id');
    $taskId = $response->json('data.task_id');
    $rule = LocalBackupPresenceRule::findOrFail($id);
    expect((bool) $rule->task->is_paused)->toBeTrue();
    expect(array_keys($response->json('data.device')))->toBe(['id', 'device_name']);
    expect(array_keys($response->json('data.task')))->toBe(['id', 'task_name', 'task_cron', 'is_paused']);
    expect($response->json('data'))->not->toHaveKeys(['scheduler_revision', 'scheduler_checked_minute_utc', 'roots']);
    expect($response->getContent())->not->toContain('/synthetic/private-root');

    $this->getJson($this->url . '?per_page=1000')->assertOk()->assertJsonPath('per_page', 100);
    $this->getJson($this->url . '/' . $id)->assertOk()->assertJsonPath('data.id', $id);
    $edit = $this->payload;
    unset($edit['device_id']);
    $edit['filename_pattern'] = 'edited_*.cfg';
    $this->patchJson($this->url . '/' . $id, $edit)->assertOk()->assertJsonPath('data.filename_pattern', 'edited_*.cfg');
    expect($rule->fresh()->enabled)->toBeFalse();
    $this->deleteJson($this->url . '/' . $id)->assertOk();
    $this->assertDatabaseMissing('local_backup_presence_rules', ['id' => $id]);
    $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    $this->assertDatabaseHas('devices', ['id' => $this->device->id]);
    $this->getJson($this->url . '/' . $id)->assertNotFound();
    Bus::assertNothingDispatched();
});

test('invalid local presence payload is refused before persistence', function (array $changes, string $field) {
    $this->actingAs($this->admin);
    $before = [Task::count(), LocalBackupPresenceRule::count()];
    $this->postJson($this->url, array_replace($this->payload, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect([Task::count(), LocalBackupPresenceRule::count()])->toBe($before);
    Bus::assertNothingDispatched();
})->with([
    'unknown nullable field' => [['enabled' => null], 'payload'],
    'raw path injection' => [['root' => '/synthetic/unauthorized'], 'payload'],
    'checkpoint injection' => [['scheduler_revision' => 'injected'], 'payload'],
    'numeric string' => [['minimum_bytes' => '1'], 'minimum_bytes'],
    'nonexistent device' => [['device_id' => 99999999], 'device_id'],
    'path traversal' => [['relative_directory' => '../escape'], 'criteria'],
    'invalid regex' => [['pattern_mode' => 'regex', 'filename_pattern' => '('], 'criteria'],
    'quiet deadline conflict' => [['quiet_seconds' => 180], 'criteria'],
    'match budget conflict' => [['max_entries' => 1], 'criteria'],
    'invalid cron' => [['task_cron' => ['99', '6', '*', '*', '*']], 'task_cron'],
    'unordered cron' => [['task_cron' => ['minute' => '0', 'hour' => '6', 'day' => '*', 'month' => '*', 'weekday' => '*']], 'task_cron'],
]);

test('device reassignment history and demo writes are refused', function () {
    $this->actingAs($this->admin);
    $id = $this->postJson($this->url, $this->payload)->assertCreated()->json('data.id');
    $rule = LocalBackupPresenceRule::findOrFail($id);
    $this->patchJson($this->url . '/' . $id, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('payload');
    $edit = $this->payload;
    unset($edit['device_id']);
    LocalBackupPresenceRun::create([
        'rule_id' => $id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-25 06:00:00',
        'deadline_at_utc' => '2026-11-25 06:03:00',
    ]);
    $before = $rule->fresh()->getRawOriginal();
    $this->patchJson($this->url . '/' . $id, $edit)->assertStatus(409);
    $this->deleteJson($this->url . '/' . $id)->assertStatus(409);
    expect($rule->fresh()->getRawOriginal())->toBe($before);
    expect($rule->runs()->count())->toBe(1);

    config(['rConfig.is_demo' => true]);
    $draft = $this->payload;
    $draft['task_name'] = 'Synthetic demo refusal';
    $this->postJson($this->url, $draft)->assertStatus(409);
    Bus::assertNothingDispatched();
});
