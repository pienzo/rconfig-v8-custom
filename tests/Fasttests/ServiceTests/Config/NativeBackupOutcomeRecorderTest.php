<?php

use App\CustomClasses\SaveConfigsToDiskAndDb;
use App\Models\Category;
use App\Models\Config;
use App\Models\Device;
use App\Services\Notifications\NativeBackupOutcomeRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->beginTransaction();
    config()->set('rConfig.is_demo', false);
    config()->set('custom-backup-notifications.enabled', true);
    config()->set('rconfig-native-backup-notifications.enabled', true);
    $this->category = Category::factory()->create();
    $this->device = Device::factory()->create(['device_category_id' => $this->category->id]);
    $this->recorder = app(NativeBackupOutcomeRecorder::class);
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('native connection failure captures the persisted result at the Core save boundary', function () {
    $start = Carbon::parse('2026-11-24 01:00:00');
    $record = [
        'id' => $this->device->id,
        'device_name' => $this->device->device_name,
        'device_category_id' => $this->category->id,
        'start_time' => $start,
        'end_time' => $start->copy()->addSeconds(2),
    ];
    $save = new SaveConfigsToDiskAndDb('device_download', 'Failed config download', 0, $record);
    expect($save->saveConfigs()['success'])->toBeFalse();

    $row = DB::table('custom_native_backup_outcomes')->where('source_config_id', $save->model->id)->first();
    expect($row->event_type)->toBe('backup.failed');
    expect($row->filename)->toBeNull();
    expect((int) $row->duration_seconds)->toBe(2);
});

test('positive native result captures only approved metadata and remains unchanged on updates', function () {
    $config = new Config([
        'device_id' => $this->device->id,
        'device_name' => 'DEMO',
        'type' => 'device_download',
        'download_status' => 1,
        'config_location' => '/synthetic/private/demo.cfg',
        'config_filename' => 'demo.cfg',
        'config_filesize' => 10,
    ]);
    expect($this->recorder->save($config))->toBeTrue();
    $row = DB::table('custom_native_backup_outcomes')->where('source_config_id', $config->id)->first();
    expect($row->event_type)->toBe('backup.success');
    expect($row->filename)->toBe('demo.cfg');
    expect((array) $row)->not->toHaveKey('config_location');

    $config->download_status = 0;
    $this->recorder->save($config);
    expect(DB::table('custom_native_backup_outcomes')->where('source_config_id', $config->id)->value('event_type'))->toBe('backup.success');
});

test('gate off and inconsistent successful metadata do not create native events', function () {
    config()->set('rconfig-native-backup-notifications.enabled', false);
    $config = new Config(['device_id' => $this->device->id, 'download_status' => 0]);
    $this->recorder->save($config);
    expect(DB::table('custom_native_backup_outcomes')->where('source_config_id', $config->id)->exists())->toBeFalse();

    config()->set('rconfig-native-backup-notifications.enabled', true);
    $invalid = new Config([
        'device_id' => $this->device->id,
        'download_status' => 1,
        'config_filename' => '../invalid.cfg',
        'config_filesize' => 10,
    ]);
    $this->recorder->save($invalid);
    expect(DB::table('custom_native_backup_outcomes')->where('source_config_id', $invalid->id)->exists())->toBeFalse();
});
