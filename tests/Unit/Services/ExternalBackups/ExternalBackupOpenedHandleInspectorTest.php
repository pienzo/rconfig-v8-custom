<?php

use App\Services\ExternalBackups\ExternalBackupOpenedHandleInspector;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'rconfig_f02_handle_');
    file_put_contents($this->path, 'synthetic-backup-001');
    $this->handle = fopen($this->path, 'rb');
    $this->inspector = new ExternalBackupOpenedHandleInspector;
});

afterEach(function () {
    if (is_resource($this->handle)) {
        fclose($this->handle);
    }
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

function f02ExpectedOpenedFile(string $path): array
{
    clearstatcache(true, $path);
    $stat = stat($path);

    return [
        'filename' => basename($path),
        'size' => $stat['size'],
        'mtime' => $stat['mtime'],
        'ctime' => $stat['ctime'],
        'device' => $stat['dev'],
        'inode' => $stat['ino'],
    ];
}

test('reads a trusted regular-file handle and returns only limited descriptor evidence', function () {
    $expected = f02ExpectedOpenedFile($this->path);
    $result = $this->inspector->inspect($this->handle, $expected);

    expect($result['state'])->toBe('descriptor_observed_only');
    expect($result['size'])->toBe(strlen('synthetic-backup-001'));
    expect($result['sha256'])->toBe(hash('sha256', 'synthetic-backup-001'));
    expect($result['device'])->toBe($expected['device']);
    expect($result['inode'])->toBe($expected['inode']);
    expect($result)->not->toHaveKey('status');
    expect($result)->not->toHaveKey('filename');
    expect($result)->not->toHaveKey('verified_file_token');
});

test('seeks to the start of a trusted descriptor even when its cursor has moved', function () {
    fread($this->handle, 8);

    $result = $this->inspector->inspect($this->handle, f02ExpectedOpenedFile($this->path));

    expect($result['sha256'])->toBe(hash('sha256', 'synthetic-backup-001'));
});

test('rejects writable streams and temporary memory streams', function () {
    $expected = f02ExpectedOpenedFile($this->path);
    $writable = fopen($this->path, 'r+b');
    $temporary = fopen('php://temp', 'w+b');

    try {
        expect(fn () => $this->inspector->inspect($writable, $expected))->toThrow(RuntimeException::class);
        expect(fn () => $this->inspector->inspect($temporary, $expected))->toThrow(RuntimeException::class);
    } finally {
        fclose($writable);
        fclose($temporary);
    }
});

test('refuses invalid handles and missing scanner evidence', function () {
    $expected = f02ExpectedOpenedFile($this->path);
    expect(fn () => $this->inspector->inspect(null, $expected))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->inspector->inspect('unsafe/path', $expected))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->inspector->inspect($this->handle, []))->toThrow(InvalidArgumentException::class);

    $expected['filename'] = '../synthetic.cfg';
    expect(fn () => $this->inspector->inspect($this->handle, $expected))->toThrow(InvalidArgumentException::class);
});

test('rejects invalid UTF-8 and embedded path separators in evidence', function () {
    foreach (["evil\xFF.cfg", 'dir/file.cfg', 'dir\file.cfg', '.'] as $filename) {
        $expected = f02ExpectedOpenedFile($this->path);
        $expected['filename'] = $filename;
        expect(fn () => $this->inspector->inspect($this->handle, $expected))->toThrow(InvalidArgumentException::class);
    }
});

test('enforces minimum and maximum byte limits before reading', function () {
    $expected = f02ExpectedOpenedFile($this->path);

    expect(fn () => $this->inspector->inspect($this->handle, $expected, $expected['size'] + 1))->toThrow(RuntimeException::class);
    expect(fn () => $this->inspector->inspect($this->handle, $expected, 1, $expected['size'] - 1))->toThrow(RuntimeException::class);
    expect(fn () => $this->inspector->inspect($this->handle, $expected, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->inspector->inspect($this->handle, $expected, 1, 1073741825))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->inspector->inspect($this->handle, $expected, 1, 104857600, 0))->toThrow(InvalidArgumentException::class);
});

test('rejects a file that differs from the earlier scanner observation', function () {
    foreach (['size', 'mtime', 'ctime', 'inode', 'device'] as $changed) {
        $expected = f02ExpectedOpenedFile($this->path);
        $expected[$changed]++;

        expect(fn () => $this->inspector->inspect($this->handle, $expected))->toThrow(RuntimeException::class);
    }
});

test('detects replacement of a pathname when the opener supplies the replacement descriptor', function () {
    $expected = f02ExpectedOpenedFile($this->path);
    $oldPath = $this->path . '.previous';
    rename($this->path, $oldPath);
    file_put_contents($this->path, 'synthetic-backup-002');
    $replacement = fopen($this->path, 'rb');

    try {
        expect(fn () => $this->inspector->inspect($replacement, $expected))->toThrow(RuntimeException::class);
    } finally {
        fclose($replacement);
        unlink($oldPath);
    }
});

test('rejects hardlinked regular files rather than inferring an independent upload', function () {
    $hardlink = $this->path . '.hardlink';
    if (! @link($this->path, $hardlink)) {
        $this->markTestSkipped('Hardlink creation unsupported on test filesystem.');
    }

    try {
        expect(fn () => $this->inspector->inspect($this->handle, f02ExpectedOpenedFile($this->path)))->toThrow(RuntimeException::class);
    } finally {
        unlink($hardlink);
    }
});

test('rejects a file modified after the earlier observation', function () {
    $expected = f02ExpectedOpenedFile($this->path);
    file_put_contents($this->path, 'a much longer synthetic backup to mimic another upload chunk');

    expect(fn () => $this->inspector->inspect($this->handle, $expected))->toThrow(RuntimeException::class);
});

test('rejects an empty regular file even if its identity matches', function () {
    fclose($this->handle);
    file_put_contents($this->path, '');
    $this->handle = fopen($this->path, 'rb');

    expect(fn () => $this->inspector->inspect($this->handle, f02ExpectedOpenedFile($this->path)))->toThrow(RuntimeException::class);
});
