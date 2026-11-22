<?php

use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;

beforeEach(function () {
    $this->matcher = new ExternalBackupFilenameMatcher;
});

test('glob wildcard matches a single backup filename', function () {
    expect($this->matcher->matches('backup_20261122.cfg', 'backup_*.cfg', 'glob'))->toBeTrue();
    expect($this->matcher->matches('backup_20261122.txt', 'backup_*.cfg', 'glob'))->toBeFalse();
});

test('glob question mark matches exactly one character', function () {
    expect($this->matcher->matches('FW-A.cfg', 'FW-?.cfg', 'glob'))->toBeTrue();
    expect($this->matcher->matches('FW-AB.cfg', 'FW-?.cfg', 'glob'))->toBeFalse();
});

test('glob square brackets have literal meaning, not an undocumented wildcard', function () {
    expect($this->matcher->matches('config[1].cfg', 'config[1].cfg', 'glob'))->toBeTrue();
    expect($this->matcher->matches('config1.cfg', 'config[1].cfg', 'glob'))->toBeFalse();
});

test('raw regex matches a complete timestamped filename', function () {
    $pattern = 'FW-BRANCH_\d{4}-\d{2}-\d{2}_\d{6}\.cfg';

    expect($this->matcher->matches('FW-BRANCH_2026-11-22_140000.cfg', $pattern, 'regex'))->toBeTrue();
    expect($this->matcher->matches('old_FW-BRANCH_2026-11-22_140000.cfg', $pattern, 'regex'))->toBeFalse();
    expect($this->matcher->matches('FW-BRANCH_2026-11-22_140000.cfg.tmp', $pattern, 'regex'))->toBeFalse();
});

test('regex meta characters and literal delimiters are safely handled', function () {
    expect($this->matcher->matches('backup~1.cfg', 'backup~\d\.cfg', 'regex'))->toBeTrue();
    expect($this->matcher->matches('anything', 'a(*ACCEPT)', 'regex'))->toBeFalse();
});

test('unicode filenames and patterns work', function () {
    expect($this->matcher->matches('sede-città_01.cfg', 'sede-*.cfg', 'glob'))->toBeTrue();
});

test('path-like names are rejected even if a wildcard could match them', function () {
    foreach (['../backup.cfg', 'nested/backup.cfg', 'nested\backup.cfg', '.', '..', ''] as $filename) {
        expect($this->matcher->matches($filename, '*', 'glob'))->toBeFalse();
    }
});

test('names with control characters or invalid utf8 are rejected', function () {
    expect($this->matcher->matches("backup\n.cfg", '*', 'glob'))->toBeFalse();
    expect($this->matcher->matches("backup\0.cfg", '*', 'glob'))->toBeFalse();
    expect($this->matcher->matches("invalid_\xFF.cfg", '*', 'glob'))->toBeFalse();
});

test('malformed regex and unknown mode are configuration errors', function () {
    expect(fn () => $this->matcher->validatePattern('(', 'regex'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->matcher->validatePattern('*.cfg', 'unknown'))->toThrow(InvalidArgumentException::class);
});

test('glob traversal and empty pattern are invalid', function () {
    expect(fn () => $this->matcher->validatePattern('../*.cfg', 'glob'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->matcher->validatePattern('nested\*.cfg', 'glob'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->matcher->validatePattern('', 'glob'))->toThrow(InvalidArgumentException::class);
});

test('patterns and filenames are bounded in bytes', function () {
    expect(fn () => $this->matcher->validatePattern(str_repeat('a', 256), 'glob'))->toThrow(InvalidArgumentException::class);
    expect($this->matcher->matches(str_repeat('a', 256), '*', 'glob'))->toBeFalse();
});

test('invalid utf8 in regex configuration is rejected', function () {
    expect(fn () => $this->matcher->validatePattern("a\xFF", 'regex'))->toThrow(InvalidArgumentException::class);
});
