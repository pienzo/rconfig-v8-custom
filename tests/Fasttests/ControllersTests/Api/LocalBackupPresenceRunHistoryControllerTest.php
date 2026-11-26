<?php

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

function presenceHistoryRule(Device $device): LocalBackupPresenceRule
{
    return LocalBackupPresenceRule::create([
        'task_id' => Task::factory()->create(['is_paused' => 1])->id,
        'device_id' => $device->id,
        'directory_alias' => 'synthetic',
        'relative_directory' => 'private-directory',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
    ]);
}

function presenceHistoryRun(LocalBackupPresenceRule $rule, string $minute, string $status, ?string $reason = null, ?string $filename = null): LocalBackupPresenceRun
{
    $run = LocalBackupPresenceRun::create([
        'rule_id' => $rule->id,
        'device_id' => $rule->device_id,
        'scheduled_at_utc' => '2026-11-25 06:' . $minute . ':00',
        'deadline_at_utc' => '2026-11-25 07:' . $minute . ':00',
    ]);
    $run->status = $status;
    $run->reason = $reason;
    $run->matched_filename = $filename;
    $run->criteria_snapshot = ['internal' => '/synthetic/private-root'];
    $run->directory_identity = str_repeat('a', 64);
    $run->stability_baseline = ['internal' => 'private-fixture'];
    $run->dispatch_not_before_utc = '2026-11-25 08:00:00';
    $run->save();

    return $run->fresh();
}

beforeEach(function () {
    $this->beginTransaction();
    config(['app.timezone' => 'Europe/Rome', 'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => false]);
    Bus::fake();
    $this->admin = User::factory()->create(['role' => 'Admin']);
    $this->device = Device::factory()->create();
    $this->rule = presenceHistoryRule($this->device);
    $this->otherRule = presenceHistoryRule($this->device);
    $this->otherDevice = Device::factory()->create();
    $this->foreignRule = presenceHistoryRule($this->otherDevice);
    $this->ruleUrl = '/api/tasks/local-backup-presence-rules/' . $this->rule->id . '/runs';
    $this->deviceUrl = '/api/tasks/local-backup-presence-runs?device_id=' . $this->device->id;
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('presence history requires authentication and administrator authorization', function () {
    $this->getJson($this->ruleUrl)->assertUnauthorized();
    $this->getJson($this->deviceUrl)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->getJson($this->ruleUrl)->assertForbidden();
    $this->getJson($this->deviceUrl)->assertForbidden();
    Bus::assertNothingDispatched();
});

test('history isolates rules and devices and preserves all four ledger statuses', function () {
    $this->actingAs($this->admin);
    $pending = presenceHistoryRun($this->rule, '00', 'pending');
    $present = presenceHistoryRun($this->rule, '01', 'present', 'matching_file_observed', 'backup_ok.cfg');
    $absent = presenceHistoryRun($this->rule, '02', 'absent', 'no_matching_file');
    $error = presenceHistoryRun($this->rule, '03', 'error', 'scan_failed');
    $sameDevice = presenceHistoryRun($this->otherRule, '03', 'present', 'matching_file_observed', 'backup_other.cfg');
    presenceHistoryRun($this->foreignRule, '04', 'present', 'matching_file_observed', 'backup_foreign.cfg');

    $response = $this->getJson($this->ruleUrl)->assertOk()
        ->assertJsonPath('evidence_type', 'metadata_presence')
        ->assertJsonPath('total', 4);
    expect(array_column($response->json('data'), 'id'))->toBe([$error->id, $absent->id, $present->id, $pending->id])
        ->and(array_column($response->json('data'), 'status'))->toBe(['error', 'absent', 'present', 'pending']);
    $response = $this->getJson($this->deviceUrl)->assertOk()->assertJsonPath('total', 5);
    expect(array_column($response->json('data'), 'id'))->toBe([$sameDevice->id, $error->id, $absent->id, $present->id, $pending->id]);

    $this->getJson($this->ruleUrl . '?status=present')->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $present->id);
    $this->getJson($this->deviceUrl . '&status=present')->assertOk()->assertJsonPath('total', 2);
    Bus::assertNothingDispatched();
});

test('history filters scheduled UTC with inclusive start and exclusive end and stable pagination', function () {
    $this->actingAs($this->admin);
    presenceHistoryRun($this->rule, '00', 'pending');
    $middle = presenceHistoryRun($this->rule, '01', 'pending');
    $last = presenceHistoryRun($this->rule, '02', 'pending');
    presenceHistoryRun($this->rule, '03', 'pending');
    $query = '?from=2026-11-25T06:01:00Z&to=2026-11-25T06:03:00Z&per_page=1';
    $response = $this->getJson($this->ruleUrl . $query)->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('data.0.id', $last->id)
        ->assertJsonPath('data.0.scheduled_at_utc', '2026-11-25T06:02:00Z');
    expect($response->json('next_page_url'))->toContain('per_page=1');
    $this->getJson($this->ruleUrl . $query . '&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $middle->id);
    $this->getJson($this->ruleUrl . $query . '&page=3')->assertOk()->assertJsonCount(0, 'data');
});

test('history exposes only safe metadata and does not modify stored evidence', function () {
    $this->actingAs($this->admin);
    $valid = presenceHistoryRun($this->rule, '00', 'present', 'matching_file_observed', 'backup_ok.cfg');
    $corrupt = presenceHistoryRun($this->rule, '01', 'error', '/synthetic/private-root', '/synthetic/private-root/backup.cfg');
    $before = [$valid->getRawOriginal(), $corrupt->getRawOriginal()];
    $response = $this->getJson($this->ruleUrl)->assertOk()
        ->assertJsonPath('data.0.matched_filename', null)
        ->assertJsonPath('data.0.reason', null)
        ->assertJsonPath('data.1.matched_filename', 'backup_ok.cfg')
        ->assertJsonPath('data.1.reason', 'matching_file_observed')
        ->assertJsonPath('data.1.first_observed_at_utc', null)
        ->assertJsonPath('data.1.deadline_at_utc', '2026-11-25T07:00:00Z');
    expect(array_keys($response->json('data.1')))->toBe([
        'id', 'rule_id', 'device_id', 'scheduled_at_utc', 'deadline_at_utc', 'status', 'reason',
        'first_observed_at_utc', 'last_observed_at_utc', 'matched_filename',
    ]);
    expect($response->getContent())->not->toContain('/synthetic/private-root', 'private-fixture', 'criteria_snapshot', 'directory_identity', 'stability_baseline', 'dispatch_not_before_utc');
    expect([$valid->fresh()->getRawOriginal(), $corrupt->fresh()->getRawOriginal()])->toBe($before);
    Bus::assertNothingDispatched();
});

test('invalid presence history filters are rejected', function (string $query, string $field) {
    $this->actingAs($this->admin);
    $this->getJson($this->ruleUrl . '?' . $query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['status=success', 'status'],
    ['status[]=present', 'status'],
    ['from=2026-11-25', 'from'],
    ['to=2026-02-30T06:00:00Z', 'to'],
    ['from=2026-11-25T06:00:00%2B01:00', 'from'],
    ['from=2026-11-25T06:03:00Z&to=2026-11-25T06:03:00Z', 'to'],
    ['from=2026-11-25T06:04:00Z&to=2026-11-25T06:03:00Z', 'to'],
    ['per_page=101', 'per_page'],
    ['per_page=0', 'per_page'],
    ['page=0', 'page'],
    ['device_id=1', 'filters'],
    ['include=criteria_snapshot', 'filters'],
]);

test('device history requires an existing device and missing rules are not found', function () {
    $this->actingAs($this->admin);
    $this->getJson('/api/tasks/local-backup-presence-runs')->assertUnprocessable()->assertJsonValidationErrors('device_id');
    $this->getJson('/api/tasks/local-backup-presence-runs?device_id=99999999')->assertUnprocessable()->assertJsonValidationErrors('device_id');
    $this->getJson('/api/tasks/local-backup-presence-rules/99999999/runs')->assertNotFound();
    $this->getJson($this->deviceUrl)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
});
