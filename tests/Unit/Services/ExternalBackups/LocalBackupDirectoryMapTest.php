<?php

use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupDirectoryMap;
use App\Services\ExternalBackups\LocalBackupPresencePolicy;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = realpath(sys_get_temp_dir()) . '/rconfig_localmap_' . bin2hex(random_bytes(8));
    $this->root = $this->sandbox . '/existing backup';
    $this->nested = $this->root . '/firewalls/site-a';
    File::ensureDirectoryExists($this->nested);
    $this->mapping = new LocalBackupDirectoryMap(['existing' => $this->root]);
    $this->scanner = new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

test('checks an existing named directory with no numeric device subdirectory', function () {
    File::put($this->root . '/fw.cfg', 'synthetic');
    File::put($this->root . '/switch.cfg', 'synthetic');
    File::put($this->nested . '/fw-other.cfg', 'synthetic');

    $fw = $this->scanner->scanMapped($this->mapping, 'existing', '', 'fw*.cfg', 'glob');
    $switch = $this->scanner->scanMapped($this->mapping, 'existing', '', 'switch\.cfg', 'regex');

    expect(array_column($fw['files'], 'filename'))->toBe(['fw.cfg']);
    expect(array_column($switch['files'], 'filename'))->toBe(['switch.cfg']);
    expect($fw['files'][0])->not->toHaveKey('path');
    expect((new LocalBackupPresencePolicy)->evaluate($fw, time())['status'])->toBe('present');
});

test('supports a nested authorized folder and ignores other directories', function () {
    File::put($this->root . '/backup.cfg', 'other');
    File::put($this->nested . '/backup.cfg', 'test');
    $result = $this->scanner->scanMapped($this->mapping, 'existing', 'firewalls/site-a', '*.cfg', 'glob');
    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0]['size'])->toBe(4);
});

test('unknown aliases and relative path escapes are rejected', function (string $relative) {
    expect(fn () => $this->mapping->snapshot('missing'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->mapping->snapshot('existing', $relative))->toThrow(InvalidArgumentException::class);
})->with(['../outside', '/etc', 'firewalls/../site-a', 'firewalls//site-a', './firewalls', 'firewalls/', 'a\\b']);

test('refuses a symlink at any path component including the approved root ancestor', function () {
    File::ensureDirectoryExists($this->sandbox . '/outside');
    symlink($this->sandbox . '/outside', $this->root . '/escape');
    symlink($this->root, $this->sandbox . '/linked');

    expect(fn () => $this->mapping->snapshot('existing', 'escape'))->toThrow(RuntimeException::class);
    $linked = new LocalBackupDirectoryMap(['linked' => $this->sandbox . '/linked/firewalls']);
    expect(fn () => $linked->snapshot('linked'))->toThrow(RuntimeException::class);
});

test('missing or unsafe mapping is an error rather than a missing backup', function () {
    $bad = new LocalBackupDirectoryMap(['bad' => '/']);
    expect(fn () => $bad->snapshot('bad'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->mapping->snapshot('existing', 'missing'))->toThrow(RuntimeException::class);
});

test('detects replacement of a directory after resolution', function () {
    $chain = $this->mapping->snapshot('existing', 'firewalls/site-a');
    rename($this->nested, $this->nested . '-old');
    File::ensureDirectoryExists($this->nested);
    expect(fn () => $this->mapping->assertUnchanged($chain))->toThrow(RuntimeException::class);
});

test('ignores symlink files and refuses incomplete bounded scans', function () {
    File::put($this->sandbox . '/outside.cfg', 'synthetic');
    symlink($this->sandbox . '/outside.cfg', $this->root . '/escape.cfg');
    File::put($this->root . '/a.cfg', 'a');
    File::put($this->root . '/b.cfg', 'b');

    $result = $this->scanner->scanMapped($this->mapping, 'existing', '', '*.cfg', 'glob');
    expect(array_column($result['files'], 'filename'))->toBe(['a.cfg', 'b.cfg']);
    expect(fn () => $this->scanner->scanMapped($this->mapping, 'existing', '', '*.cfg', 'glob', 1, 1))->toThrow(RuntimeException::class);
    expect(fn () => $this->scanner->scanMapped($this->mapping, 'existing', '', '*.cfg', 'glob', 100, 1))->toThrow(RuntimeException::class);
    expect(fn () => $this->scanner->scanMapped($this->mapping, 'existing', '', '(', 'regex'))->toThrow(InvalidArgumentException::class);
});
