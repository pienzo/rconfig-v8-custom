<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;
use RuntimeException;

/**
 * Inspect a read-only, already-opened, trusted regular-file stream.
 *
 * This class does NOT open a pathname or establish a secure Linux dirfd.
 * The caller must supply a descriptor opened by a separately audited no-follow
 * boundary (ADR-0002). Never pass an untrusted fopen() pathname as that boundary.
 * Evidence returned here is NOT a verified receipt or backup SUCCESS.
 */
final class ExternalBackupOpenedHandleInspector
{
    /**
     * @param  resource  $handle  Trusted read-only descriptor, positioned anywhere.
     * @param  array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}  $expected
     * @return array{state: 'descriptor_observed_only', size: int, sha256: string, device: int, inode: int, mtime: int, ctime: int}
     */
    public function inspect(
        mixed $handle,
        array $expected,
        int $minimumBytes = 1,
        int $maximumBytes = 104857600,
        int $maximumReadMillis = 5000
    ): array {
        if ($minimumBytes < 1 || $maximumBytes < $minimumBytes || $maximumBytes > 1073741824
            || $maximumReadMillis < 1 || $maximumReadMillis > 120000) {
            throw new InvalidArgumentException('Invalid opened-file inspection bounds.');
        }

        $this->validateExpectedMetadata($expected);

        if (! is_resource($handle) || get_resource_type($handle) !== 'stream') {
            throw new InvalidArgumentException('Expected an already-opened file stream.');
        }

        $stream = stream_get_meta_data($handle);
        if (! in_array($stream['mode'] ?? null, ['r', 'rb'], true)
            || ($stream['seekable'] ?? false) !== true
            || ($stream['stream_type'] ?? null) !== 'STDIO') {
            throw new RuntimeException('A seekable, read-only, regular-file descriptor is required.');
        }

        $started = hrtime(true);
        $before = $this->statAndCheck($handle, $expected, $minimumBytes, $maximumBytes);
        if (@rewind($handle) !== true) {
            throw new RuntimeException('Cannot rewind the opened backup descriptor.');
        }

        $context = hash_init('sha256');
        $bytes = 0;
        while ($bytes < $before['size']) {
            $this->enforceElapsed($started, $maximumReadMillis);
            $chunk = @fread($handle, min(65536, $before['size'] - $bytes));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Opened backup changed or could not be read completely.');
            }

            hash_update($context, $chunk);
            $bytes += strlen($chunk);
        }

        $this->enforceElapsed($started, $maximumReadMillis);
        // Catch growth after the expected length; a regular local file will EOF.
        if (@fread($handle, 1) !== '') {
            throw new RuntimeException('Opened backup length changed during inspection.');
        }

        $after = $this->statAndCheck($handle, $expected, $minimumBytes, $maximumBytes);
        if ($this->identity($before) !== $this->identity($after)) {
            throw new RuntimeException('Opened backup metadata changed during inspection.');
        }
        $this->enforceElapsed($started, $maximumReadMillis);

        return [
            'state' => 'descriptor_observed_only',
            'size' => $bytes,
            'sha256' => hash_final($context),
            'device' => $after['dev'],
            'inode' => $after['ino'],
            'mtime' => $after['mtime'],
            'ctime' => $after['ctime'],
        ];
    }

    /** @param array<string, mixed> $expected */
    private function validateExpectedMetadata(array $expected): void
    {
        $filename = $expected['filename'] ?? null;
        if (! is_string($filename) || $filename === '' || $filename === '.' || $filename === '..'
            || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) !== 0 || preg_match('//u', $filename) !== 1) {
            throw new InvalidArgumentException('Invalid expected backup basename.');
        }

        foreach (['size', 'mtime', 'ctime', 'device', 'inode'] as $field) {
            if (! isset($expected[$field]) || ! is_int($expected[$field]) || $expected[$field] < 0) {
                throw new InvalidArgumentException('Invalid expected backup metadata.');
            }
        }
    }

    /**
     * @param  resource  $handle
     * @param  array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}  $expected
     * @return array<string|int, int>
     */
    private function statAndCheck(mixed $handle, array $expected, int $minimumBytes, int $maximumBytes): array
    {
        $stat = @fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new RuntimeException('Opened backup is not a single-linked regular file.');
        }

        if ($stat['size'] < $minimumBytes || $stat['size'] > $maximumBytes) {
            throw new RuntimeException('Opened backup size violates inspection limits.');
        }

        foreach (['size' => 'size', 'mtime' => 'mtime', 'ctime' => 'ctime', 'dev' => 'device', 'ino' => 'inode'] as $actual => $expectedField) {
            if ($stat[$actual] !== $expected[$expectedField]) {
                throw new RuntimeException('Opened backup does not match the earlier metadata observation.');
            }
        }

        return $stat;
    }

    /**
     * @param  array<string|int, int>  $stat
     * @return array<string, int>
     */
    private function identity(array $stat): array
    {
        return [
            'mode' => $stat['mode'],
            'nlink' => $stat['nlink'],
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'ctime' => $stat['ctime'],
        ];
    }

    private function enforceElapsed(int $started, int $limitMillis): void
    {
        if ((hrtime(true) - $started) / 1000000 > $limitMillis) {
            throw new RuntimeException('Opened backup inspection exceeded its time budget.');
        }
    }
}
