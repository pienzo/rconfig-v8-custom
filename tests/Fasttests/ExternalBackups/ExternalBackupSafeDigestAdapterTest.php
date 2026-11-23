<?php

use App\Models\ExternalBackupRule;
use App\Services\ExternalBackups\ExternalBackupSafeDigestAdapter;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/rconfig-f02-native-php-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
    mkdir($this->root . '/123', 0700);
    $this->file = $this->root . '/123/backup.cfg';
    file_put_contents($this->file, 'SYNTHETIC-F02-BACKUP');
    $stat = lstat($this->file);

    $this->expected = [
        'filename' => 'backup.cfg',
        'device' => $stat['dev'],
        'inode' => $stat['ino'],
        'size' => $stat['size'],
        'mtime' => $stat['mtime'],
        'ctime' => $stat['ctime'],
    ];
    $this->rule = new ExternalBackupRule([
        'device_id' => 123,
        'minimum_bytes' => 1,
    ]);
    config()->set('external-backups.intake_root', $this->root);
    config()->set('external-backups.max_digest_bytes', 104857600);
    config()->set('external-backups.safe_digest_binary', '/nonexistent/rconfig-f02-native');
    $this->adapter = new ExternalBackupSafeDigestAdapter;
});

afterEach(function () {
    if (isset($this->helper) && is_file($this->helper)) {
        unlink($this->helper);
    }
    unlink($this->file);
    rmdir($this->root . '/123');
    rmdir($this->root);
    config()->set('external-backups.intake_root', '');
    config()->set('external-backups.safe_digest_binary', '');
});

function f02FakeNativeProofScript(array $evidence): string
{
    $path = tempnam(sys_get_temp_dir(), 'f02-php-native-');
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg(json_encode($evidence, JSON_THROW_ON_ERROR)) . "\n");
    chmod($path, 0700);

    return $path;
}

test('F02 PHP native adapter fails closed when the compiled helper is absent', function () {
    expect(fn () => $this->adapter->inspect($this->rule, $this->expected))->toThrow(RuntimeException::class);
});

test('F02 PHP native adapter accepts typed descriptor evidence but does not return a backup result', function () {
    $evidence = [
        'version' => 1,
        'state' => 'descriptor_checked_only',
        'sha256' => str_repeat('a', 64),
        'size' => $this->expected['size'],
        'device' => $this->expected['device'],
        'inode' => $this->expected['inode'],
        'mtime' => $this->expected['mtime'],
        'ctime' => $this->expected['ctime'],
        'mtime_ns' => 0,
        'ctime_ns' => 0,
    ];
    $this->helper = f02FakeNativeProofScript($evidence);
    config()->set('external-backups.safe_digest_binary', $this->helper);

    $observed = $this->adapter->inspect($this->rule, $this->expected);
    expect($observed['state'])->toBe('descriptor_checked_only');
    expect($observed['sha256'])->toBe(str_repeat('a', 64));
    expect($observed)->not->toHaveKey('status');
    expect($observed)->not->toHaveKey('verified_file_token');
});

test('F02 PHP native adapter rejects malformed identity even from an executable helper', function () {
    $evidence = [
        'version' => 1,
        'state' => 'descriptor_checked_only',
        'sha256' => str_repeat('b', 64),
        'size' => $this->expected['size'],
        'device' => $this->expected['device'],
        'inode' => $this->expected['inode'] + 1,
        'mtime' => $this->expected['mtime'],
        'ctime' => $this->expected['ctime'],
        'mtime_ns' => 0,
        'ctime_ns' => 0,
    ];
    $this->helper = f02FakeNativeProofScript($evidence);
    config()->set('external-backups.safe_digest_binary', $this->helper);

    expect(fn () => $this->adapter->inspect($this->rule, $this->expected))->toThrow(RuntimeException::class);
});

test('F02 PHP native adapter refuses path traversal and invalid metadata before launching the helper', function () {
    $this->expected['filename'] = '../another.cfg';

    expect(fn () => $this->adapter->inspect($this->rule, $this->expected))->toThrow(RuntimeException::class);

    $this->helper = f02FakeNativeProofScript([]);
    config()->set('external-backups.safe_digest_binary', $this->helper);

    expect(fn () => $this->adapter->inspect($this->rule, $this->expected))->toThrow(InvalidArgumentException::class);

    $this->expected['filename'] = 'backup.cfg';
    $this->expected['inode'] = -1;

    expect(fn () => $this->adapter->inspect($this->rule, $this->expected))->toThrow(InvalidArgumentException::class);
});
