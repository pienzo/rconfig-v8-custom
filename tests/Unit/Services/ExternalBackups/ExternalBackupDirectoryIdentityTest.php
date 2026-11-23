<?php

use App\Services\ExternalBackups\ExternalBackupDirectoryIdentity;

test('reading a directory can update atime without changing its identity', function () {
    $before = [
        'dev' => 8, 'ino' => 42, 'mode' => 16832, 'nlink' => 2,
        'uid' => 1000, 'gid' => 33, 'size' => 4096, 'mtime' => 1700000000,
        'ctime' => 1700000000, 'atime' => 1699999999, 8 => 1699999999,
    ];
    $after = $before;
    $after['atime'] = 1700000100;
    $after[8] = 1700000100;

    expect(ExternalBackupDirectoryIdentity::same($before, $after))->toBeTrue();
});

test('directory replacement or attribute changes still invalidate the observation', function () {
    $original = [
        'dev' => 8, 'ino' => 42, 'mode' => 16832, 'nlink' => 2,
        'uid' => 1000, 'gid' => 33, 'size' => 4096, 'mtime' => 1700000000,
        'ctime' => 1700000000,
    ];
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
        $modified = $original;
        $modified[$field]++;
        expect(ExternalBackupDirectoryIdentity::same($original, $modified))->toBeFalse();
    }

    $missing = $original;
    unset($missing['ino']);
    expect(ExternalBackupDirectoryIdentity::same($original, $missing))->toBeFalse();
});
