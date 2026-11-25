<?php

use App\Services\ExternalBackups\LocalBackupPresencePolicy;
use App\Services\ExternalBackups\LocalBackupPresenceStabilityPolicy;

beforeEach(function () {
    $this->policy = new LocalBackupPresenceStabilityPolicy(new LocalBackupPresencePolicy);
    $this->now = 1800000000;
    $this->file = [
        'filename' => 'backup.cfg',
        'size' => 1024,
        'mtime' => $this->now - 120,
        'ctime' => $this->now - 120,
        'device' => 11,
        'inode' => 42,
    ];
    $this->scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$this->file]];
});

test('optional stability can be disabled explicitly', function () {
    expect($this->policy->evaluate($this->scan, $this->now, quietSeconds: null))->toBe([
        'status' => 'present', 'reason' => 'matching_file_observed', 'filename' => 'backup.cfg',
    ]);
});

test('first observation requires a second scan when quiet is enabled', function () {
    expect($this->policy->evaluate($this->scan, $this->now))->toBe([
        'status' => 'pending', 'reason' => 'stability_wait', 'filename' => 'backup.cfg',
    ]);
});

test('two identical observations satisfy the inclusive quiet boundary', function () {
    expect($this->policy->evaluate($this->scan, $this->now, earlierScan: $this->scan, earlierObservedAt: $this->now - 29)['status'])->toBe('pending');
    expect($this->policy->evaluate($this->scan, $this->now, earlierScan: $this->scan, earlierObservedAt: $this->now - 30))->toBe([
        'status' => 'present', 'reason' => 'stable_matching_file_observed', 'filename' => 'backup.cfg',
    ]);
});

test('a changed file identity or metadata requires another observation', function (string $field) {
    $earlier = $this->scan;
    $earlier['files'][0][$field]--;
    expect($this->policy->evaluate($this->scan, $this->now, earlierScan: $earlier, earlierObservedAt: $this->now - 60)['status'])->toBe('pending');
})->with(['device', 'inode', 'size', 'mtime', 'ctime']);

test('a new matching file does not mask an eligible stable backup', function () {
    $later = $this->scan;
    $later['files'][] = array_replace($this->file, ['filename' => 'new.cfg', 'mtime' => $this->now - 1, 'ctime' => $this->now - 1, 'inode' => 43]);
    $later['scanned_entries'] = 2;
    expect($this->policy->evaluate($later, $this->now, earlierScan: $this->scan, earlierObservedAt: $this->now - 60)['filename'])->toBe('backup.cfg');
});

test('stable but stale or undersized evidence is not eligible', function () {
    expect($this->policy->evaluate($this->scan, $this->now, maximumAgeSeconds: 119, earlierScan: $this->scan, earlierObservedAt: $this->now - 60)['status'])->toBe('absent');
    expect($this->policy->evaluate($this->scan, $this->now, minimumBytes: 1025, earlierScan: $this->scan, earlierObservedAt: $this->now - 60)['status'])->toBe('absent');
});

test('metadata newer than the earlier observation cannot establish stability', function () {
    $scan = $this->scan;
    $scan['files'][0]['ctime'] = $this->now - 10;
    expect($this->policy->evaluate($scan, $this->now, earlierScan: $scan, earlierObservedAt: $this->now - 60)['status'])->toBe('pending');
});

test('an absent or unmatched scan is not presence evidence', function () {
    $missing = ['directory_present' => false, 'scanned_entries' => 0, 'files' => []];
    expect($this->policy->evaluate($missing, $this->now)['reason'])->toBe('directory_missing');
    $empty = ['directory_present' => true, 'scanned_entries' => 2, 'files' => []];
    expect($this->policy->evaluate($empty, $this->now)['reason'])->toBe('no_matching_file');
});

test('new or renamed matching files require a new pair of observations', function () {
    $later = $this->scan;
    $later['files'][0]['filename'] = 'renamed.cfg';
    expect($this->policy->evaluate($later, $this->now, earlierScan: $this->scan, earlierObservedAt: $this->now - 60)['status'])->toBe('pending');
});

test('invalid time pairs or quiet limits are refused', function () {
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, quietSeconds: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, quietSeconds: 3601))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, earlierScan: $this->scan))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, earlierScan: $this->scan, earlierObservedAt: $this->now + 1))->toThrow(InvalidArgumentException::class);
});

test('malformed identity and duplicate earlier observations fail closed', function () {
    $bad = $this->scan;
    unset($bad['files'][0]['inode']);
    expect(fn () => $this->policy->evaluate($bad, $this->now))->toThrow(InvalidArgumentException::class);
    $duplicate = $this->scan;
    $duplicate['files'][] = $this->file;
    $duplicate['scanned_entries'] = 2;
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, earlierScan: $duplicate, earlierObservedAt: $this->now - 60))->toThrow(InvalidArgumentException::class);
});
