<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;

/**
 * Assess two scanner metadata observations without opening any backup file.
 *
 * CANDIDATE is never SUCCESS: final acceptance still requires a safe file-handle
 * check and a persistent run/file-identity ledger preventing reused backups.
 */
final class ExternalBackupObservationPolicy
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANDIDATE = 'candidate_requires_safe_open';

    /**
     * @param  array<string, mixed>  $earlierScan  Shape returned by ExternalBackupDirectoryScanner::scan()
     * @param  array<string, mixed>  $laterScan
     * @return array{status: string, reason: string, filename: string|null}
     */
    public function evaluate(
        array $earlierScan,
        array $laterScan,
        int $scheduledAtUtc,
        int $deadlineUtc,
        int $earlierObservedAtUtc,
        int $laterObservedAtUtc,
        int $quietSeconds = 30,
        int $minimumBytes = 1,
        int $clockSkewSeconds = 0
    ): array {
        if (
            $scheduledAtUtc < 0 || $deadlineUtc <= $scheduledAtUtc
            || $deadlineUtc - $scheduledAtUtc > 31 * 86400
            || $earlierObservedAtUtc < $scheduledAtUtc
            || $laterObservedAtUtc < $earlierObservedAtUtc
            || $quietSeconds < 1 || $quietSeconds > 3600
            || $minimumBytes < 1 || $minimumBytes > 1000000000000
            || $clockSkewSeconds < 0 || $clockSkewSeconds > 300
        ) {
            throw new InvalidArgumentException('Invalid external backup observation window or validation limits.');
        }

        // Validate both inputs, including duplicates, before making any decision.
        $earlier = $this->filesByName($earlierScan);
        $later = $this->filesByName($laterScan);

        if (! $laterScan['directory_present'] || $later === []) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'missing', null);
        }

        // Only the newest current candidate is evaluated; a newer in-progress
        // backup cannot be masked by an older, already-stable file.
        $candidates = array_values($later);
        usort($candidates, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['filename'], $b['filename']));
        $file = $candidates[0];
        $filename = $file['filename'];

        if ($file['size'] < $minimumBytes) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'undersized', $filename);
        }

        if ($file['mtime'] < $scheduledAtUtc - $clockSkewSeconds) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'stale', $filename);
        }

        // A file's mtime after the run deadline or after the observed instant
        // (beyond the explicit skew allowance) is not trusted as fresh.
        if ($file['mtime'] >= $deadlineUtc || $file['mtime'] > $laterObservedAtUtc + $clockSkewSeconds) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'invalid_timestamp', $filename);
        }

        $prior = $earlier[$filename] ?? null;
        if (! $earlierScan['directory_present'] || $prior === null) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'unstable', $filename);
        }

        foreach (['device', 'inode', 'size', 'mtime', 'ctime'] as $key) {
            if ($prior[$key] !== $file[$key]) {
                return $this->result($laterObservedAtUtc, $deadlineUtc, 'unstable', $filename);
            }
        }

        if (
            $laterObservedAtUtc - $earlierObservedAtUtc < $quietSeconds
            || $laterObservedAtUtc - max($file['mtime'], $file['ctime']) < $quietSeconds
        ) {
            return $this->result($laterObservedAtUtc, $deadlineUtc, 'unstable', $filename);
        }

        if ($laterObservedAtUtc >= $deadlineUtc) {
            return ['status' => self::STATUS_FAILED, 'reason' => 'deadline_elapsed', 'filename' => $filename];
        }

        return ['status' => self::STATUS_CANDIDATE, 'reason' => 'requires_safe_open', 'filename' => $filename];
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>
     */
    private function filesByName(array $scan): array
    {
        if (
            ! isset($scan['directory_present'], $scan['scanned_entries'], $scan['files'])
            || ! is_bool($scan['directory_present'])
            || ! is_int($scan['scanned_entries']) || $scan['scanned_entries'] < 0
            || ! is_array($scan['files']) || ! array_is_list($scan['files'])
            || count($scan['files']) > $scan['scanned_entries']
        ) {
            throw new InvalidArgumentException('Malformed external backup directory observation.');
        }

        if (! $scan['directory_present'] && ($scan['files'] !== [] || $scan['scanned_entries'] !== 0)) {
            throw new InvalidArgumentException('A missing directory cannot contain backup observations.');
        }

        $result = [];
        foreach ($scan['files'] as $file) {
            if (! is_array($file) || ! isset($file['filename'], $file['size'], $file['mtime'], $file['ctime'], $file['device'], $file['inode'])) {
                throw new InvalidArgumentException('Malformed external backup file observation.');
            }

            $filename = $file['filename'];
            if (
                ! is_string($filename) || $filename === '' || $filename === '.' || $filename === '..'
                || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $filename) !== 0 || preg_match('//u', $filename) !== 1
            ) {
                throw new InvalidArgumentException('An external backup filename is not a valid basename.');
            }

            foreach (['size', 'mtime', 'ctime', 'device', 'inode'] as $key) {
                if (! is_int($file[$key]) || $file[$key] < 0) {
                    throw new InvalidArgumentException('Invalid external backup file metadata.');
                }
            }

            if (isset($result[$filename])) {
                throw new InvalidArgumentException('Duplicate external backup filename observation.');
            }

            $result[$filename] = [
                'filename' => $filename,
                'size' => $file['size'],
                'mtime' => $file['mtime'],
                'ctime' => $file['ctime'],
                'device' => $file['device'],
                'inode' => $file['inode'],
            ];
        }

        return $result;
    }

    /** @return array{status: string, reason: string, filename: string|null} */
    private function result(int $observedAt, int $deadline, string $reason, ?string $filename): array
    {
        return [
            'status' => $observedAt >= $deadline ? self::STATUS_FAILED : self::STATUS_PENDING,
            'reason' => $reason,
            'filename' => $filename,
        ];
    }
}
