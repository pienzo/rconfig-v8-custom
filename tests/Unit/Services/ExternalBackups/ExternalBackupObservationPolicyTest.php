<?php

use App\Services\ExternalBackups\ExternalBackupObservationPolicy;

beforeEach(function () {
    $this->policy = new ExternalBackupObservationPolicy;
    $this->scheduled = 1800000000;
    $this->deadline = $this->scheduled + 3600;
    $this->firstTime = $this->scheduled + 120;
    $this->secondTime = $this->scheduled + 170;

    $this->file = [
        'filename' => 'backup_20270115.cfg',
        'size' => 100,
        'mtime' => $this->scheduled + 60,
        'ctime' => $this->scheduled + 60,
        'device' => 19,
        'inode' => 811,
    ];
    $this->scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$this->file]];
});

test('unchanged recent metadata is only a candidate for secure opening, never success', function () {
    $result = $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime);

    expect($result)->toBe(['status' => 'candidate_requires_safe_open', 'reason' => 'requires_safe_open', 'filename' => 'backup_20270115.cfg']);
});

test('missing directory and missing files stay pending until deadline', function () {
    $missing = ['directory_present' => false, 'scanned_entries' => 0, 'files' => []];
    $empty = ['directory_present' => true, 'scanned_entries' => 0, 'files' => []];

    expect($this->policy->evaluate($this->scan, $missing, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('missing');
    expect($this->policy->evaluate($this->scan, $empty, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['status'])->toBe('pending');
    expect($this->policy->evaluate($missing, $missing, $this->scheduled, $this->deadline, $this->firstTime, $this->deadline))->toBe([
        'status' => 'failed', 'reason' => 'missing', 'filename' => null,
    ]);
});

test('stale backup does not satisfy a later scheduled occurrence', function () {
    $oldFile = $this->file;
    $oldFile['mtime'] = $this->scheduled - 1;
    $oldFile['ctime'] = $this->scheduled - 1;
    $scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$oldFile]];

    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('stale');
    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->deadline)['status'])->toBe('failed');
});

test('a permitted clock skew is bounded and must be explicit', function () {
    $old = $this->file;
    $old['mtime'] = $this->scheduled - 10;
    $old['ctime'] = $this->scheduled - 10;
    $scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$old]];

    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('stale');
    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 30, 1, 10)['status'])->toBe('candidate_requires_safe_open');
});

test('empty or undersized backups never become ready candidates', function () {
    $zero = $this->file;
    $zero['size'] = 0;
    $scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$zero]];

    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('undersized');
    expect($this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 30, 101)['status'])->toBe('pending');
});

test('new or replaced file observations are unstable', function () {
    $empty = ['directory_present' => true, 'scanned_entries' => 0, 'files' => []];
    $replaced = $this->scan;
    $replaced['files'][0]['inode']++;

    expect($this->policy->evaluate($empty, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('unstable');
    expect($this->policy->evaluate($this->scan, $replaced, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('unstable');
});

test('changes in size mtime and ctime invalidate the quiet period', function (string $field) {
    $changed = $this->scan;
    $changed['files'][0][$field]++;

    expect($this->policy->evaluate($this->scan, $changed, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('unstable');
})->with(['size', 'mtime', 'ctime']);

test('two metadata observations must be separated by quiet time', function () {
    expect($this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->firstTime + 5)['reason'])->toBe('unstable');
});

test('recent metadata changes require another quiet interval even when two snapshots agree', function () {
    $justUpdated = $this->file;
    $justUpdated['ctime'] = $this->secondTime - 5;
    $scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$justUpdated]];

    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('unstable');
});

test('file dated in future or beyond deadline is never considered fresh', function () {
    $future = $this->file;
    $future['mtime'] = $this->secondTime + 301;
    $future['ctime'] = $future['mtime'];
    $scan = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$future]];

    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 30, 1, 300)['reason'])->toBe('invalid_timestamp');
    $future['mtime'] = $this->deadline;
    $scan['files'] = [$future];
    expect($this->policy->evaluate($scan, $scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime)['reason'])->toBe('invalid_timestamp');
});

test('a candidate observed at or after deadline is rejected, even if previously stable', function () {
    $result = $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->deadline);

    expect($result)->toBe(['status' => 'failed', 'reason' => 'deadline_elapsed', 'filename' => 'backup_20270115.cfg']);
});

test('newest current candidate is selected deterministically and cannot be masked by older stable file', function () {
    $recent = $this->file;
    $recent['filename'] = 'backup_recent.cfg';
    $recent['mtime'] = $this->scheduled + 110;
    $recent['ctime'] = $this->scheduled + 110;
    $recent['inode']++;
    $first = ['directory_present' => true, 'scanned_entries' => 1, 'files' => [$this->file]];
    $second = ['directory_present' => true, 'scanned_entries' => 2, 'files' => [$this->file, $recent]];

    $result = $this->policy->evaluate($first, $second, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime);
    expect($result)->toBe(['status' => 'pending', 'reason' => 'unstable', 'filename' => 'backup_recent.cfg']);
});

test('malformed input and duplicate filenames fail closed', function () {
    $dup = $this->scan;
    $dup['files'][] = $this->file;
    $dup['scanned_entries'] = 2;
    $bad = $this->scan;
    $bad['files'][0]['filename'] = '../outside.cfg';
    $wrongType = $this->scan;
    $wrongType['files'][0]['mtime'] = 'not-an-epoch';

    expect(fn () => $this->policy->evaluate($dup, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($bad, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($wrongType, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime))->toThrow(InvalidArgumentException::class);
});

test('invalid windows and limits throw rather than silently accepting file', function () {
    expect(fn () => $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->scheduled, $this->firstTime, $this->secondTime))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 30, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->policy->evaluate($this->scan, $this->scan, $this->scheduled, $this->deadline, $this->firstTime, $this->secondTime, 30, 1, 301))->toThrow(InvalidArgumentException::class);
});
