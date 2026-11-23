<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupRule;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * A process-isolated Linux openat2 + SHA-256 inspection of a scanned basename.
 *
 * The native program cannot create receipts or backup SUCCESS. Its output is
 * private evidence only, to be consumed by a future audited receipt claimant.
 * Neither paths nor the executable can come from any HTTP request.
 */
final class ExternalBackupSafeDigestAdapter
{
    /**
     * @param  array{filename: string, device: int, inode: int, size: int, mtime: int, ctime: int}  $expected
     * @return array{state: 'descriptor_checked_only', sha256: string, size: int, device: int, inode: int, mtime: int, ctime: int, mtime_ns: int, ctime_ns: int}
     */
    public function inspect(ExternalBackupRule $rule, array $expected): array
    {
        $binary = config('external-backups.safe_digest_binary', '');
        $root = config('external-backups.intake_root', '');
        $limit = (int) config('external-backups.max_digest_bytes', 104857600);
        $minimum = (int) $rule->minimum_bytes;

        if (! is_string($binary) || $binary === '' || is_link($binary)
            || ! is_file($binary) || ! is_executable($binary)
            || ! is_string($root) || $root === '' || $root[0] !== '/'
            || ! is_dir($root) || is_link($root)
            || $minimum < 1 || $minimum > $limit
            || $limit < 1 || $limit > 1073741824
            || (int) $rule->device_id < 1) {
            throw new RuntimeException('F02 native verification is not configured or not executable.');
        }

        $filename = $expected['filename'] ?? null;
        if (! is_string($filename) || $filename === '' || $filename === '.' || $filename === '..'
            || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) !== 0
            || preg_match('//u', $filename) !== 1) {
            throw new InvalidArgumentException('Invalid scanned backup basename.');
        }

        foreach (['device', 'inode', 'size', 'mtime', 'ctime'] as $field) {
            if (! isset($expected[$field]) || ! is_int($expected[$field]) || $expected[$field] < 0) {
                throw new InvalidArgumentException('Invalid scanned backup metadata.');
            }
        }

        $process = new Process([
            $binary,
            $root,
            (string) $rule->device_id,
            $filename,
            (string) $expected['device'],
            (string) $expected['inode'],
            (string) $expected['size'],
            (string) $expected['mtime'],
            (string) $expected['ctime'],
            (string) $minimum,
            (string) $limit,
        ]);
        $process->setTimeout(12);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('F02 native verification exceeded its time budget.');
        }

        $stdout = $process->getOutput();
        if (! $process->isSuccessful() || strlen($stdout) > 1024) {
            throw new RuntimeException('F02 native verification refused this file.');
        }

        try {
            $evidence = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('F02 native verifier emitted invalid evidence.');
        }

        if (! is_array($evidence)
            || ($evidence['version'] ?? null) !== 1
            || ($evidence['state'] ?? null) !== 'descriptor_checked_only'
            || ! is_string($evidence['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $evidence['sha256']) !== 1) {
            throw new RuntimeException('F02 native verifier emitted invalid evidence.');
        }

        foreach (['size', 'device', 'inode', 'mtime', 'ctime', 'mtime_ns', 'ctime_ns'] as $field) {
            if (! is_int($evidence[$field] ?? null) || $evidence[$field] < 0) {
                throw new RuntimeException('F02 native verifier emitted invalid numeric evidence.');
            }
        }

        if ($evidence['mtime_ns'] >= 1000000000 || $evidence['ctime_ns'] >= 1000000000) {
            throw new RuntimeException('F02 native verifier emitted invalid time precision.');
        }

        foreach (['size', 'device', 'inode', 'mtime', 'ctime'] as $field) {
            if ($evidence[$field] !== $expected[$field]) {
                throw new RuntimeException('F02 native evidence does not match the scanner observation.');
            }
        }

        return [
            'state' => 'descriptor_checked_only',
            'sha256' => $evidence['sha256'],
            'size' => $evidence['size'],
            'device' => $evidence['device'],
            'inode' => $evidence['inode'],
            'mtime' => $evidence['mtime'],
            'ctime' => $evidence['ctime'],
            'mtime_ns' => $evidence['mtime_ns'],
            'ctime_ns' => $evidence['ctime_ns'],
        ];
    }
}
