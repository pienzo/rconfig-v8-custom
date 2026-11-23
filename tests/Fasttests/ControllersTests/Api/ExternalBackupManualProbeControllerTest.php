<?php

use App\Models\Device;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->beginTransaction();
    $this->actingAs(User::factory()->create());
    $device = Device::factory()->create();
    $task = Task::factory()->create(['task_command' => 'rconfig:check-external-backup', 'is_paused' => 1]);
    $this->rule = ExternalBackupRule::create([
        'task_id' => $task->id,
        'device_id' => $device->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
        'enabled' => false,
    ]);
    $this->root = sys_get_temp_dir() . '/rconfig-f02-manual-' . bin2hex(random_bytes(8));
    $this->inbox = $this->root . '/' . $device->id;
    mkdir($this->root, 0700);
    mkdir($this->inbox, 0700);
    config()->set('external-backups.intake_root', $this->root);
    $this->instant = CarbonImmutable::now('UTC')->startOfSecond();
    CarbonImmutable::setTestNow($this->instant);
});

afterEach(function () {
    foreach (glob($this->inbox . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->inbox);
    rmdir($this->root);
    CarbonImmutable::setTestNow();
    config()->set('external-backups.intake_root', '');
    $this->rollBackTransaction();
});

function f02ManualUrl(int $id): string
{
    return '/api/tasks/external-backup-rules/' . $id . '/verify-now';
}

test('two stable observations produce a candidate, never a successful backup receipt', function () {
    $file = $this->inbox . '/backup_20261123.cfg';
    file_put_contents($file, 'SYNTHETIC-FILE-FOR-TEST');
    touch($file, $this->instant->getTimestamp() - 5);

    $first = $this->postJson(f02ManualUrl($this->rule->id))
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'first_observation')
        ->assertJsonPath('verified_success', false);

    $token = $first->json('checkpoint');
    expect($token)->toBeString();

    CarbonImmutable::setTestNow($this->instant->addSeconds(3));
    $this->postJson(f02ManualUrl($this->rule->id), ['checkpoint' => $token])
        ->assertOk()
        ->assertJsonPath('status', 'candidate_requires_safe_open')
        ->assertJsonPath('reason', 'requires_safe_open')
        ->assertJsonPath('filename', 'backup_20261123.cfg')
        ->assertJsonPath('checkpoint', null)
        ->assertJsonPath('verified_success', false);

    $this->assertDatabaseCount('external_backup_check_runs', 0);
    $this->assertDatabaseCount('external_backup_file_receipts', 0);
});

test('early retry preserves quiet interval checkpoint without rescanning', function () {
    $first = $this->postJson(f02ManualUrl($this->rule->id))->assertOk();
    $token = $first->json('checkpoint');

    CarbonImmutable::setTestNow($this->instant->addSecond());
    $this->postJson(f02ManualUrl($this->rule->id), ['checkpoint' => $token])
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'quiet_interval')
        ->assertJsonPath('retry_after_seconds', 1)
        ->assertJsonPath('checkpoint', $token);
});

test('missing files become failed only after the manual deadline', function () {
    $first = $this->postJson(f02ManualUrl($this->rule->id))->assertOk();
    CarbonImmutable::setTestNow($this->instant->addSeconds(3));
    $second = $this->postJson(f02ManualUrl($this->rule->id), [
        'checkpoint' => $first->json('checkpoint'),
    ])->assertOk()->assertJsonPath('reason', 'missing')->assertJsonPath('status', 'pending');

    CarbonImmutable::setTestNow($this->instant->addSeconds(70));
    $this->postJson(f02ManualUrl($this->rule->id), [
        'checkpoint' => $second->json('checkpoint'),
    ])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'missing')
        ->assertJsonPath('verified_success', false);
});

test('a growing file remains unstable even after quiet interval', function () {
    $file = $this->inbox . '/backup.cfg';
    file_put_contents($file, 'SYNTHETIC-FIXTURE');
    touch($file, $this->instant->getTimestamp() - 5);
    $first = $this->postJson(f02ManualUrl($this->rule->id))->assertOk();
    file_put_contents($file, 'SYNTHETIC-FIXTURE-WAS-CHANGED');

    CarbonImmutable::setTestNow($this->instant->addSeconds(3));
    $this->postJson(f02ManualUrl($this->rule->id), [
        'checkpoint' => $first->json('checkpoint'),
    ])->assertOk()->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'unstable');
});

test('tampered tokens and changed rules cannot reuse prior observations', function () {
    $first = $this->postJson(f02ManualUrl($this->rule->id))->assertOk();
    $token = $first->json('checkpoint');

    $this->postJson(f02ManualUrl($this->rule->id), ['checkpoint' => $token . '.altered'])
        ->assertUnprocessable()->assertJsonValidationErrors('checkpoint');

    $this->rule->update(['filename_pattern' => 'other_*.cfg']);
    $this->postJson(f02ManualUrl($this->rule->id), ['checkpoint' => $token])
        ->assertUnprocessable()->assertJsonValidationErrors('checkpoint');
});

test('a second device cannot reuse an encrypted checkpoint', function () {
    $other = Device::factory()->create();
    $rule2 = ExternalBackupRule::create([
        'task_id' => $this->rule->task_id,
        'device_id' => $other->id,
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'quiet_seconds' => 2,
        'deadline_seconds' => 120,
    ]);
    $first = $this->postJson(f02ManualUrl($this->rule->id))->assertOk();

    $this->postJson(f02ManualUrl($rule2->id), [
        'checkpoint' => $first->json('checkpoint'),
    ])->assertUnprocessable()->assertJsonValidationErrors('checkpoint');
});
