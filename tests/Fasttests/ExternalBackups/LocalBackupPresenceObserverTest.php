<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupDirectoryMap;
use App\Services\ExternalBackups\LocalBackupPresenceObserver;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use App\Services\ExternalBackups\LocalBackupPresencePolicy;
use App\Services\ExternalBackups\LocalBackupPresenceRunState;
use App\Services\ExternalBackups\LocalBackupPresenceStabilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->beginTransaction();
    config([
        'local-backup-presence.runtime_enabled' => true,
        'rConfig.is_demo' => false,
        'app.timezone' => 'UTC',
    ]);
    $this->device = Device::factory()->create();
    $this->task = Task::factory()->create([
        'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
        'task_devices' => 1,
        'task_categories' => 0,
        'task_tags' => 0,
        'is_system' => 0,
        'is_paused' => 0,
        'task_cron' => '* * * * *',
    ]);
    $this->task->device()->attach($this->device->id);
    $this->rule = LocalBackupPresenceRule::create([
        'task_id' => $this->task->id,
        'device_id' => $this->device->id,
        'directory_alias' => 'synthetic',
        'relative_directory' => 'firewalls/site-a',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
    ]);
    $this->rule->enabled = true;
    $this->instant = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(2);
    $this->rule->activated_at_utc = $this->instant->subMinute()->format('Y-m-d H:i:s');
    $this->rule->save();
    $this->service = new LocalBackupPresenceOccurrenceClaim(new ExternalBackupFilenameMatcher);
    $this->state = new LocalBackupPresenceRunState(new ExternalBackupFilenameMatcher);
    $this->sandbox = realpath(sys_get_temp_dir()) . '/rconfig_presence_' . bin2hex(random_bytes(8));
    $this->folder = $this->sandbox . '/firewalls/site-a';
    File::ensureDirectoryExists($this->folder);
    $this->mapping = new LocalBackupDirectoryMap(['synthetic' => $this->sandbox]);
    $this->observer = new LocalBackupPresenceObserver(new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher), new LocalBackupPresenceStabilityPolicy(new LocalBackupPresencePolicy), $this->state);
    $this->run = $this->service->claim($this->rule->id, $this->instant);
    CarbonImmutable::setTestNow($this->instant);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->sandbox);
    $this->rollBackTransaction();
});

test('actual preexisting file is present after quiet period and early retries preserve baseline', function () {
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->status)->toBe('pending');
    expect($first->reason)->toBe('stability_wait');
    $baseline = $first->stability_baseline;
    expect($first->toArray())->not->toHaveKey('directory_identity');
    expect($first->toArray())->not->toHaveKey('stability_baseline');

    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    $early = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($early->stability_baseline)->toEqual($baseline);

    // New service instance simulates losing process-local state.
    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    $observer = new LocalBackupPresenceObserver(new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher), new LocalBackupPresenceStabilityPolicy(new LocalBackupPresencePolicy), $this->state);
    $final = $observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('present');
    expect($final->matched_filename)->toBe('backup_a.cfg');
    expect($final->stability_baseline)->toBeNull();
    expect($final->directory_identity)->toBeNull();
    expect(File::get($this->folder . '/backup_a.cfg'))->toBe('synthetic');
});

test('file arrival after an empty scan and a growing file need a new stable observation', function () {
    $empty = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($empty->reason)->toBe('no_matching_file');
    File::put($this->folder . '/backup_a.cfg', 'partial');

    CarbonImmutable::setTestNow($this->instant->addMinute());
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('stability_wait');
    File::append($this->folder . '/backup_a.cfg', ' more');

    CarbonImmutable::setTestNow($this->instant->addSeconds(90));
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->status)->toBe('pending');
    expect($second->stability_baseline['observed_at'])->toBe($this->instant->addSeconds(90)->getTimestamp());

    CarbonImmutable::setTestNow($this->instant->addMinutes(2));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('present');
});

test('same size replacement changes the inode and restarts the quiet baseline', function () {
    $file = $this->folder . '/backup_a.cfg';
    File::put($file, 'original');
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('stability_wait');
    $original = $first->stability_baseline['scan']['files'][0];

    // Keep the old inode allocated under a nonmatching name.
    rename($file, $this->folder . '/previous.cfg');
    File::put($file, 'replaced');
    touch($file, $original['mtime']);

    CarbonImmutable::setTestNow($this->instant->addSeconds(10));
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->status)->toBe('pending');
    expect($second->stability_baseline['observed_at'])->toBe($this->instant->addSeconds(10)->getTimestamp());
    $replacement = $second->stability_baseline['scan']['files'][0];
    expect($replacement['size'])->toBe($original['size']);
    expect($replacement['mtime'])->toBe($original['mtime']);
    expect($replacement['inode'])->not->toBe($original['inode']);

    CarbonImmutable::setTestNow($this->instant->addSeconds(30));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('pending');
    CarbonImmutable::setTestNow($this->instant->addSeconds(40));
    expect($this->observer->observe($this->rule->id, $this->run->id, $this->mapping)->status)->toBe('present');
});

test('snapshot disables quiet checking without requiring a second observation', function () {
    $this->rule->update(['quiet_seconds' => null]);
    $instant = $this->instant->addMinute();
    $run = $this->service->claim($this->rule->id, $instant);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    CarbonImmutable::setTestNow($instant);

    $before = [ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()];
    $final = $this->observer->observe($this->rule->id, $run->id, $this->mapping);
    expect($final->status)->toBe('present');
    expect($final->reason)->toBe('matching_file_observed');
    expect([ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count()])->toBe($before);
});

test('old file does not satisfy freshness and expiry needs final-minute evidence', function () {
    File::put($this->folder . '/backup_a.cfg', 'old');
    touch($this->folder . '/backup_a.cfg', $this->instant->subDays(2)->getTimestamp());
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->reason)->toBe('no_eligible_file');

    CarbonImmutable::setTestNow($this->instant->addHour());
    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('observation_gap_before_deadline');
    expect($final->directory_identity)->toBeNull();
});

test('recent absence at expiry is distinct from unobserved monitoring error', function () {
    CarbonImmutable::setTestNow($this->instant->addMinutes(59));
    $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    CarbonImmutable::setTestNow($this->instant->addHour());

    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('absent');
    expect($final->reason)->toBe('no_matching_file');
});

test('replaced directory is not accepted as the same backup source', function () {
    $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    rename($this->folder, $this->folder . '-old');
    File::ensureDirectoryExists($this->folder);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    CarbonImmutable::setTestNow($this->instant->addMinute());

    $final = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($final->status)->toBe('error');
    expect($final->reason)->toBe('directory_identity_changed');
    expect($final->matched_filename)->toBeNull();
});

test('missing directory is a sanitized scan error and terminal replay does not scan', function () {
    File::deleteDirectory($this->folder);
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($first->status)->toBe('error');
    expect($first->reason)->toBe('scan_failed');
    CarbonImmutable::setTestNow($this->instant->addMinute());
    $second = $this->observer->observe($this->rule->id, $this->run->id, new LocalBackupDirectoryMap([]));
    expect($second->getAttributes())->toEqual($first->getAttributes());
});

test('same second replay does not rescan and paused task or disabled gate refuses new work', function () {
    $first = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    File::put($this->folder . '/backup_a.cfg', 'synthetic');
    $second = $this->observer->observe($this->rule->id, $this->run->id, $this->mapping);
    expect($second->getAttributes())->toEqual($first->getAttributes());

    CarbonImmutable::setTestNow($this->instant->addMinute());
    $this->task->update(['is_paused' => 1]);
    expect(fn () => $this->observer->observe($this->rule->id, $this->run->id, $this->mapping))->toThrow(DomainException::class);
    config(['local-backup-presence.runtime_enabled' => false]);
    expect(fn () => $this->observer->observe($this->rule->id, $this->run->id, $this->mapping))->toThrow(DomainException::class);
});
