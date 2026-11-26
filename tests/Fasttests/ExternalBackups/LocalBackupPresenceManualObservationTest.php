<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupPresenceManualObservation;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->beginTransaction();
    $this->sandbox = realpath(sys_get_temp_dir()) . '/rconfig_presence_manual_' . bin2hex(random_bytes(8));
    $this->folder = $this->sandbox . '/device';
    File::ensureDirectoryExists($this->folder);
    config([
        'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.manual_probe_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => $this->sandbox],
    ]);
    $this->instant = CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(10);
    CarbonImmutable::setTestNow($this->instant);
    $this->device = Device::factory()->create();
    $this->criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 4,
        'maximum_age_seconds' => 60,
        'quiet_seconds' => null,
        'deadline_seconds' => 180,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ];
    $this->rule = app(LocalBackupPresenceRuleLifecycle::class)->create($this->device->id, 'Synthetic manual observation', '* * * * *', $this->criteria);
    $this->service = app(LocalBackupPresenceManualObservation::class);
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->sandbox);
    $this->rollBackTransaction();
});

test('manual observation of an eligible existing file is diagnostic only and preserves every ledger', function () {
    $path = $this->folder . '/backup_a.cfg';
    File::put($path, 'synthetic');
    $before = [$this->rule->fresh()->getRawOriginal(), $this->rule->task()->firstOrFail()->getRawOriginal(),
        LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count()];
    $result = $this->service->observe($this->rule->id);
    expect($result)->toBe([
        'status' => 'present',
        'reason' => 'matching_file_observed',
        'filename' => 'backup_a.cfg',
        'observed_at_utc' => $this->instant->format('Y-m-d\TH:i:s\Z'),
        'evidence_type' => 'metadata_presence',
        'persistent_check_run' => false,
        'verified_success' => false,
    ]);
    expect([$this->rule->fresh()->getRawOriginal(), $this->rule->task()->firstOrFail()->getRawOriginal(),
        LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count()])->toBe($before)
        ->and(File::get($path))->toBe('synthetic');
    Bus::assertNothingDispatched();
});

test('a single observation never asserts stability even for an old eligible file', function () {
    $this->rule->update(['quiet_seconds' => 30, 'maximum_age_seconds' => null]);
    $path = $this->folder . '/backup_a.cfg';
    File::put($path, 'synthetic');
    touch($path, $this->instant->subMinutes(5)->getTimestamp());
    $first = $this->service->observe($this->rule->id);
    CarbonImmutable::setTestNow($this->instant->addSeconds(60));
    $second = $this->service->observe($this->rule->id);
    expect($first['status'])->toBe('pending')
        ->and($first['reason'])->toBe('stability_wait')
        ->and($second['status'])->toBe('pending')
        ->and($second['verified_success'])->toBeFalse();
    expect($this->rule->runs()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('an empty directory or only ineligible files are absent now, not a scheduled failure', function (string $kind, string $reason) {
    if ($kind !== 'empty') {
        $path = $this->folder . '/backup_a.cfg';
        File::put($path, $kind === 'small' ? 'x' : 'synthetic');
        if ($kind === 'stale') {
            touch($path, $this->instant->subSeconds(61)->getTimestamp());
        } elseif ($kind === 'future') {
            touch($path, $this->instant->addSeconds(10)->getTimestamp());
        }
    }
    $result = $this->service->observe($this->rule->id);
    expect($result['status'])->toBe('absent')
        ->and($result['reason'])->toBe($reason)
        ->and($result['filename'])->toBeNull()
        ->and($result['persistent_check_run'])->toBeFalse();
    expect($this->rule->runs()->count())->toBe(0);
})->with([
    ['empty', 'no_matching_file'],
    ['small', 'no_eligible_file'],
    ['stale', 'no_eligible_file'],
    ['future', 'no_eligible_file'],
]);

test('missing unsafe or over-budget directories fail as sanitized technical errors', function (string $kind) {
    if ($kind === 'missing') {
        File::deleteDirectory($this->folder);
    } elseif ($kind === 'symlink') {
        File::deleteDirectory($this->folder);
        File::ensureDirectoryExists($this->sandbox . '/other');
        symlink($this->sandbox . '/other', $this->folder);
    } else {
        $this->rule->update(['max_entries' => 1, 'max_matches' => 1]);
        File::put($this->folder . '/backup_a.cfg', 'synthetic');
        File::put($this->folder . '/backup_b.cfg', 'synthetic');
    }
    $result = $this->service->observe($this->rule->id);
    expect($result['status'])->toBe('error')
        ->and($result['reason'])->toBe('scan_failed')
        ->and($result['observed_at_utc'])->toBeNull()
        ->and($result['filename'])->toBeNull();
    expect($this->rule->runs()->count())->toBe(0);
})->with(['missing', 'symlink', 'budget']);

test('unknown mappings and invalid criteria are sanitized configuration errors', function () {
    config(['local-backup-presence.roots' => []]);
    expect($this->service->observe($this->rule->id)['reason'])->toBe('invalid_configuration');
    config(['local-backup-presence.roots' => ['synthetic' => $this->sandbox]]);
    $this->rule->update(['relative_directory' => '../escape']);
    expect($this->service->observe($this->rule->id)['reason'])->toBe('invalid_configuration');
    Bus::assertNothingDispatched();
});

test('manual observation gates fail closed even with a valid fixture', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(fn () => $this->service->observe($this->rule->id))->toThrow(DomainException::class);
    expect($this->rule->runs()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.manual_probe_enabled', false],
    ['local-backup-presence.manual_probe_enabled', 'true'],
    ['rConfig.is_demo', true],
]);

test('inconsistent task linkage refuses manual observation', function () {
    $this->rule->task->device()->attach(Device::factory()->create()->id);
    expect(fn () => $this->service->observe($this->rule->id))->toThrow(DomainException::class);
    expect($this->rule->runs()->count())->toBe(0);
    Bus::assertNothingDispatched();
});
