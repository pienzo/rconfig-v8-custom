<?php

use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/rconfig_extscan_' . getmypid() . '_' . bin2hex(random_bytes(5));
    $this->root = $this->sandbox . '/inbox';
    $this->deviceDir = $this->root . '/42';
    File::ensureDirectoryExists($this->deviceDir);
    $this->scanner = new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

test('observes only matched regular files in one device directory', function () {
    File::put($this->deviceDir . '/backup_b.cfg', 'B');
    File::put($this->deviceDir . '/backup_a.cfg', 'AAA');
    File::put($this->deviceDir . '/other.txt', 'not a backup');
    File::ensureDirectoryExists($this->deviceDir . '/nested');
    File::put($this->deviceDir . '/nested/backup_nested.cfg', 'nested');
    File::ensureDirectoryExists($this->root . '/43');
    File::put($this->root . '/43/backup_other.cfg', 'other device');

    $result = $this->scanner->scan($this->root, 42, 'backup_*.cfg', 'glob');

    expect($result['directory_present'])->toBeTrue();
    expect($result['scanned_entries'])->toBe(4);
    expect(array_column($result['files'], 'filename'))->toBe(['backup_a.cfg', 'backup_b.cfg']);
    expect($result['files'][0]['size'])->toBe(3);
    expect($result['files'][0])->toHaveKeys(['mtime', 'ctime', 'inode', 'device']);
    expect($result['files'][0])->not->toHaveKey('path');
});

test('missing device directory is reported without claiming backup success', function () {
    $result = $this->scanner->scan($this->root, 123, '*', 'glob');

    expect($result)->toBe(['directory_present' => false, 'scanned_entries' => 0, 'files' => []]);
});

test('rejects unsupported device ids and scan bounds', function () {
    expect(fn () => $this->scanner->scan($this->root, 0, '*', 'glob'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->scanner->scan($this->root, 42, '*', 'glob', 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->scanner->scan($this->root, 42, '*', 'glob', 1, 2))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->scanner->scan($this->root, 42, '*', 'glob', 1000, 100, 0))->toThrow(InvalidArgumentException::class);
});

test('invalid pattern is rejected even when directory does not exist', function () {
    expect(fn () => $this->scanner->scan($this->root, 123, '(', 'regex'))->toThrow(InvalidArgumentException::class);
});

test('rejects missing or unsafe intake root', function () {
    expect(fn () => $this->scanner->scan($this->sandbox . '/missing', 42, '*', 'glob'))->toThrow(RuntimeException::class);
    expect(fn () => $this->scanner->scan('/', 42, '*', 'glob'))->toThrow(RuntimeException::class);
});

test('does not follow an intake root symlink', function () {
    $linkedRoot = $this->sandbox . '/linked';
    @symlink($this->root, $linkedRoot);
    if (! is_link($linkedRoot)) {
        $this->markTestSkipped('Symlink creation not supported here.');
    }

    expect(fn () => $this->scanner->scan($linkedRoot, 42, '*', 'glob'))->toThrow(RuntimeException::class);
});

test('does not follow a device directory symlink', function () {
    @symlink($this->deviceDir, $this->root . '/99');
    if (! is_link($this->root . '/99')) {
        $this->markTestSkipped('Symlink creation not supported here.');
    }

    expect(fn () => $this->scanner->scan($this->root, 99, '*', 'glob'))->toThrow(RuntimeException::class);
});

test('skips a symlinked file, never exposing its contents', function () {
    File::put($this->sandbox . '/outside.cfg', 'synthetic-only');
    @symlink($this->sandbox . '/outside.cfg', $this->deviceDir . '/backup_escape.cfg');
    if (! is_link($this->deviceDir . '/backup_escape.cfg')) {
        $this->markTestSkipped('Symlink creation not supported here.');
    }

    $result = $this->scanner->scan($this->root, 42, 'backup_*.cfg', 'glob');
    expect($result['files'])->toBe([]);
});

test('refuses partial scans when entry limit is exceeded', function () {
    File::put($this->deviceDir . '/a.cfg', 'a');
    File::put($this->deviceDir . '/b.cfg', 'b');

    expect(fn () => $this->scanner->scan($this->root, 42, '*.cfg', 'glob', 1, 1))->toThrow(RuntimeException::class);
});

test('refuses partial scans when match limit is exceeded', function () {
    File::put($this->deviceDir . '/a.cfg', 'a');
    File::put($this->deviceDir . '/b.cfg', 'b');

    expect(fn () => $this->scanner->scan($this->root, 42, '*.cfg', 'glob', 2, 1))->toThrow(RuntimeException::class);
});

test('regex may select a timestamped file but does not validate freshness', function () {
    File::put($this->deviceDir . '/backup_20261122.cfg', 'fake');
    $result = $this->scanner->scan($this->root, 42, 'backup_\d{8}\.cfg', 'regex');

    expect(array_column($result['files'], 'filename'))->toBe(['backup_20261122.cfg']);
    expect($result['files'][0])->not->toHaveKey('status');
});
