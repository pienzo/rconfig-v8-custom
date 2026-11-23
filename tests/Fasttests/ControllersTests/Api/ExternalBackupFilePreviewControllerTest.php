<?php

use App\Models\Device;
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
        'filename_pattern' => 'backup_*.cfg',
        'enabled' => false,
    ]);

    // An ephemeral synthetic test root, never a host backup directory.
    $this->intake = sys_get_temp_dir() . '/rconfig-f02-preview-' . bin2hex(random_bytes(10));
    mkdir($this->intake, 0700);
});

afterEach(function () {
    $folder = $this->intake . '/' . $this->device->id;
    foreach (['backup_20261123.cfg', 'backup_skipped.txt', 'backup_link.cfg'] as $basename) {
        $path = $folder . '/' . $basename;
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    if (is_dir($folder)) {
        rmdir($folder);
    }
    if (is_dir($this->intake)) {
        rmdir($this->intake);
    }
    config()->set('external-backups.intake_root', '');
    $this->rollBackTransaction();
});

test('F02 file preview refuses an unconfigured root', function () {
    config()->set('external-backups.intake_root', '');

    $this->getJson('/api/tasks/external-backup-rules/' . $this->rule->id . '/files-preview')
        ->assertStatus(503);

    expect($this->rule->fresh()->enabled)->toBeFalse();
});

test('F02 file preview lists synthetic matching files without a success claim', function () {
    $folder = $this->intake . '/' . $this->device->id;
    mkdir($folder, 0700);
    file_put_contents($folder . '/backup_20261123.cfg', 'SYNTHETIC-FIXTURE');
    file_put_contents($folder . '/backup_skipped.txt', 'SYNTHETIC-FIXTURE');

    config()->set('external-backups.intake_root', $this->intake);

    $this->getJson('/api/tasks/external-backup-rules/' . $this->rule->id . '/files-preview')
        ->assertOk()
        ->assertJsonPath('status', 'metadata_only')
        ->assertJsonPath('directory_present', true)
        ->assertJsonPath('scanned_entries', 2)
        ->assertJsonCount(1, 'files')
        ->assertJsonPath('files.0.filename', 'backup_20261123.cfg');

    $this->assertDatabaseCount('external_backup_check_runs', 0);
    $this->assertDatabaseCount('external_backup_file_receipts', 0);
});

test('F02 file preview reports a missing device inbox', function () {
    config()->set('external-backups.intake_root', $this->intake);

    $this->getJson('/api/tasks/external-backup-rules/' . $this->rule->id . '/files-preview')
        ->assertOk()
        ->assertJsonPath('status', 'metadata_only')
        ->assertJsonPath('directory_present', false)
        ->assertJsonCount(0, 'files');
});

test('F02 file preview skips symlink candidates without accessing their targets', function () {
    $folder = $this->intake . '/' . $this->device->id;
    mkdir($folder, 0700);
    file_put_contents($folder . '/backup_skipped.txt', 'SYNTHETIC-FIXTURE');
    symlink($folder . '/backup_skipped.txt', $folder . '/backup_link.cfg');

    config()->set('external-backups.intake_root', $this->intake);

    $this->getJson('/api/tasks/external-backup-rules/' . $this->rule->id . '/files-preview')
        ->assertOk()
        ->assertJsonCount(0, 'files');

    $this->assertDatabaseCount('external_backup_check_runs', 0);
});
