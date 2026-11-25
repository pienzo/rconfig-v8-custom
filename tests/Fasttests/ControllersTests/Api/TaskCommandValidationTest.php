<?php

use App\Models\Category;
use App\Models\Device;
use App\Models\ExternalBackupRule;
use App\Models\LocalBackupPresenceRule;
use App\Models\RestApiToken;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->beginTransaction();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    $this->rollBackTransaction();
});

dataset('unsupported task commands', [
    'command with appended shell text' => ['rconfig:download-device;id'],
    'command with spaced suffix' => ['rconfig:download-device ;id'],
    'command with newline suffix' => ["rconfig:download-device\nid"],
    'command with extra argument' => ['rconfig:download-device 5'],
    'other artisan command' => ['tinker'],
    'unknown command' => ['rconfig:purge-configs'],
]);

/**
 * @return array<string, mixed>
 */
function taskCommandValidationPayload(string $command, array $overrides = []): array
{
    return array_merge([
        'task_name' => 'task-command-' . rand(100000, 999999),
        'task_desc' => 'task command validation',
        'task_command' => $command,
        'task_cron' => ['0', '0', '1', '1', '*'],
        'device' => Device::factory(1)->create()->map(fn ($device) => ['id' => $device->id])->all(),
        'category' => Category::factory(1)->create()->map(fn ($category) => ['id' => $category->id])->all(),
        'tag' => Tag::factory(1)->create()->map(fn ($tag) => ['id' => $tag->id])->all(),
        'task_email_notify' => 0,
        'download_report_notify' => 0,
        'verbose_download_report_notify' => 0,
    ], $overrides);
}

test('rejects an unsupported task command on create', function (string $command) {
    $this->json('post', '/api/tasks', taskCommandValidationPayload($command))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_command']);

    expect(Task::where('task_desc', 'task command validation')->count())->toBe(0);
})->with('unsupported task commands');

test('rejects an unsupported task command on validate task', function (string $command) {
    $this->json('post', '/api/tasks/validate-task', taskCommandValidationPayload($command))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_command']);
})->with('unsupported task commands');

test('rejects an unsupported task command on update', function (string $command) {
    $task = Task::factory()->create(['task_command' => 'rconfig:download-device']);

    $this->json('patch', '/api/tasks/' . $task->id, taskCommandValidationPayload($command, ['id' => $task->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_command']);

    expect($task->fresh()->task_command)->toBe('rconfig:download-device');
})->with('unsupported task commands');

test('rejects an unsupported task command on the token api create route', function () {
    User::factory()->create();
    $token = RestApiToken::factory()->create();

    $this->withHeaders(['apitoken' => $token->api_token])
        ->postJson('/api/v1/tasks', taskCommandValidationPayload('rconfig:download-device;id'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_command']);
});

test('accepts every supported task command on create and update', function (string $command) {
    $created = $this->json('post', '/api/tasks', taskCommandValidationPayload($command))
        ->assertStatus(200);

    $taskId = $created->json('data.id');
    expect(Task::find($taskId)->task_command)->toBe($command);

    $this->json('patch', '/api/tasks/' . $taskId, taskCommandValidationPayload($command, ['id' => $taskId]))
        ->assertStatus(200);
})->with([
    'rconfig:download-device',
    'rconfig:download-category',
    'rconfig:download-tag',
    'rconfig:download-task',
]);

dataset('managed file check commands', [
    'strict receipt' => ['rconfig:check-external-backup'],
    'local presence' => ['rconfig:check-local-backup-presence'],
]);

test('managed F02 commands cannot be created or assigned through Core task routes', function (string $command) {
    $task = Task::factory()->create(['task_command' => 'rconfig:download-device']);
    $payload = taskCommandValidationPayload($command);
    $this->postJson('/api/tasks', $payload)->assertUnprocessable()->assertJsonValidationErrors(['task_command']);
    $this->postJson('/api/tasks/validate-task', $payload)->assertUnprocessable()->assertJsonValidationErrors(['task_command']);
    $this->patchJson('/api/tasks/' . $task->id, $payload + ['id' => $task->id])->assertUnprocessable()->assertJsonValidationErrors(['task_command']);
    expect($task->fresh()->task_command)->toBe('rconfig:download-device');
})->with('managed file check commands');

test('managed F02 tasks refuse valid native edits pause delete manual dispatch and mixed bulk deletion', function (string $command) {
    Bus::fake();
    $device = Device::factory()->create();
    $task = Task::factory()->create([
        'task_command' => $command,
        'is_paused' => 1,
        'is_system' => 0,
        'task_devices' => 1,
        'task_categories' => 0,
        'task_tags' => 0,
    ]);
    $task->device()->attach($device->id);
    $attributes = [
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
    ];
    $rule = $command === 'rconfig:check-local-backup-presence'
        ? LocalBackupPresenceRule::create($attributes + ['directory_alias' => 'synthetic', 'relative_directory' => 'device'])
        : ExternalBackupRule::create($attributes + ['quiet_seconds' => 30, 'deadline_seconds' => 180, 'enabled' => false]);
    $native = Task::factory()->create(['task_command' => 'rconfig:download-device']);
    $before = $task->fresh()->getRawOriginal();
    $ruleBefore = $rule->fresh()->getRawOriginal();

    $this->patchJson('/api/tasks/' . $task->id, taskCommandValidationPayload('rconfig:download-device', ['id' => $task->id]))->assertStatus(409);
    $this->getJson('/api/tasks/toggle-pause-task/' . $task->id)->assertStatus(409);
    $this->deleteJson('/api/tasks/' . $task->id)->assertStatus(409);
    $this->postJson('/api/tasks/run-manual-task', ['id' => $task->id])->assertStatus(409);
    $this->postJson('/api/tasks/delete-many', ['ids' => [$native->id, $task->id]])->assertStatus(409);
    $this->postJson('/api/tasks/delete-many', ['ids' => [$task->id]])->assertStatus(409);

    expect($task->fresh()->getRawOriginal())->toBe($before);
    expect($rule->fresh()->getRawOriginal())->toBe($ruleBefore);
    expect($task->device()->pluck('devices.id')->all())->toBe([$device->id]);
    $this->assertDatabaseHas('tasks', ['id' => $native->id]);
    Bus::assertNothingDispatched();
})->with('managed file check commands');

test('native tasks can still be deleted in bulk', function () {
    $tasks = Task::factory(2)->create(['task_command' => 'rconfig:download-device']);
    $this->postJson('/api/tasks/delete-many', ['ids' => $tasks->pluck('id')->all()])->assertOk();
    foreach ($tasks as $task) {
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
});
