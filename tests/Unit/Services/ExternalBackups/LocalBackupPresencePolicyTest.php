<?php

use App\Services\ExternalBackups\LocalBackupPresencePolicy;

beforeEach(function () {
    $this->policy = new LocalBackupPresencePolicy;
    $this->now = 1800000000;
    $this->file = ['filename' => 'backup.cfg', 'size' => 1024, 'mtime' => $this->now - 3600];
    $this->scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$this->file]];
});

test('an existing backup before the scheduled check is presence evidence only', function () {
    expect($this->policy->evaluate($this->scan, $this->now, 86400))->toBe([
        'status' => 'present', 'reason' => 'matching_file_observed', 'filename' => 'backup.cfg',
    ]);
});

test('age is optional and inclusive at the configured boundary', function () {
    expect($this->policy->evaluate($this->scan, $this->now)['status'])->toBe('present');
    expect($this->policy->evaluate($this->scan, $this->now, 3600)['status'])->toBe('present');
    expect($this->policy->evaluate($this->scan, $this->now, 3599)['status'])->toBe('absent');
});

test('future dates and undersized files are not eligible', function () {
    $future = $this->scan;
    $future['files'][0]['mtime'] = $this->now + 1;
    expect($this->policy->evaluate($future, $this->now)['status'])->toBe('absent');
    expect($this->policy->evaluate($this->scan, $this->now, minimumBytes: 1025)['status'])->toBe('absent');
});

test('any eligible matching file suffices and selection is deterministic', function () {
    $scan = $this->scan;
    $scan['files'][] = ['filename' => 'a.cfg', 'size' => 1024, 'mtime' => $this->file['mtime']];
    $scan['files'][] = ['filename' => 'new.cfg', 'size' => 0, 'mtime' => $this->now];
    $scan['scanned_entries'] = 3;
    expect($this->policy->evaluate($scan, $this->now)['filename'])->toBe('a.cfg');
});

test('missing directory and empty match set are distinct', function () {
    expect($this->policy->evaluate(['directory_present' => false, 'scanned_entries' => 0, 'files' => []], $this->now)['reason'])->toBe('directory_missing');
    expect($this->policy->evaluate(['directory_present' => true, 'scanned_entries' => 3, 'files' => []], $this->now)['reason'])->toBe('no_matching_file');
});

test('malformed evidence and invalid limits fail closed', function () {
    $bad = $this->scan;
    $bad['files'][0]['filename'] = '../backup.cfg';
    $duplicate = $this->scan;
    $duplicate['files'][] = $this->file;
    $duplicate['scanned_entries'] = 2;
    expect(fn () => $this->policy->evaluate($bad, $this->now))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($duplicate, $this->now))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->now, minimumBytes: 0))->toThrow(InvalidArgumentException::class);
});
