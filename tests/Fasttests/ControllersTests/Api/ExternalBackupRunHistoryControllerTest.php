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
    $this->task = Task::factory()->create(['task_command' => 'rconfig:check-external-backup', 'is_paused' => 1]);
    $this->rule = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => 'synthetic_*.cfg',
        'enabled' => false,
    ]);
});

afterEach(function () {
    $this->rollBackTransaction();
});

function f02RunHistoryUrl(int $ruleId, string $query = ''): string
{
    return '/api/tasks/external-backup-rules/' . $ruleId . '/runs' . $query;
}

test('F02 run history is empty when no durable check has been recorded', function () {
    $this->getJson(f02RunHistoryUrl($this->rule->id))->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonCount(0, 'data');
});

test('F02 run history is scoped to the given rule and ordered newest-first', function () {
    $other = ExternalBackupRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.txt',
    ]);

    $old = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-20 02:00:00',
        'deadline_at_utc' => '2026-11-20 03:00:00',
        'status' => 'failed',
        'reason' => 'missing',
    ]);
    $new = ExternalBackupCheckRun::create([
        'rule_id' => $this->rule->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-21 02:00:00',
        'deadline_at_utc' => '2026-11-21 03:00:00',
        'status' => 'pending',
        'reason' => 'requires_safe_open',
        'matched_filename' => 'synthetic_20261121.cfg',
    ]);
    ExternalBackupCheckRun::create([
        'rule_id' => $other->id,
        'device_id' => $this->device->id,
        'scheduled_at_utc' => '2026-11-22 02:00:00',
        'deadline_at_utc' => '2026-11-22 03:00:00',
        'status' => 'failed',
    ]);

    $this->getJson(f02RunHistoryUrl($this->rule->id, '?per_page=1'))->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('last_page', 2)
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.receipt_exists', false)
        ->assertJsonMissing(['verified_file_token'])
        ->assertJsonMissing(['sha256']);

    $this->getJson(f02RunHistoryUrl($this->rule->id, '?per_page=1&page=2'))->assertOk()
        ->assertJsonPath('data.0.id', $old->id)
        ->assertJsonPath('data.0.status', 'failed');

    $this->getJson(f02RunHistoryUrl($other->id))->assertOk()
        ->assertJsonPath('total', 1);
});

test('F02 run history caps page size and rejects unknown rules', function () {
    $this->getJson(f02RunHistoryUrl($this->rule->id, '?per_page=10000'))->assertOk()
        ->assertJsonPath('per_page', 100);

    $this->getJson(f02RunHistoryUrl(99999999))->assertNotFound();
});
