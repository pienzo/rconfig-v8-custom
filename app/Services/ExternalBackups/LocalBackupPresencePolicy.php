<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;

/**
 * Metadata-only existence evidence. This is NOT a verified upload receipt,
 * content validation, proof of transfer completion or proof of restorability.
 * The caller must obtain observations from a trusted bounded local scanner.
 */
final class LocalBackupPresencePolicy
{
    /**
     * @param  array<string, mixed>  $scan  Trusted scanner observation
     * @return array{status: string, reason: string, filename: string|null}
     */
    public function evaluate(array $scan, int $observedAtUtc, ?int $maximumAgeSeconds = null, int $minimumBytes = 1): array
    {
        if ($observedAtUtc < 0
            || ($maximumAgeSeconds !== null && ($maximumAgeSeconds < 1 || $maximumAgeSeconds > 31536000))
            || $minimumBytes < 1 || $minimumBytes > 1000000000000) {
            throw new InvalidArgumentException('Invalid local backup presence limits.');
        }

        if (! isset($scan['directory_present'], $scan['scanned_entries'], $scan['files'])
            || ! is_bool($scan['directory_present'])
            || ! is_int($scan['scanned_entries']) || $scan['scanned_entries'] < 0
            || ! is_array($scan['files']) || ! array_is_list($scan['files'])
            || count($scan['files']) > $scan['scanned_entries']
            || (! $scan['directory_present'] && ($scan['scanned_entries'] !== 0 || $scan['files'] !== []))) {
            throw new InvalidArgumentException('Invalid local backup observation.');
        }

        $seen = [];
        $candidates = [];
        $matcher = new ExternalBackupFilenameMatcher;
        foreach ($scan['files'] as $file) {
            if (! is_array($file) || ! isset($file['filename'], $file['size'], $file['mtime'])
                || ! is_string($file['filename']) || ! $matcher->matches($file['filename'], '*', 'glob')
                || ! is_int($file['size']) || $file['size'] < 0
                || ! is_int($file['mtime']) || $file['mtime'] < 0
                || isset($seen[$file['filename']])) {
                throw new InvalidArgumentException('Invalid or duplicate local backup metadata.');
            }

            $seen[$file['filename']] = true;
            if ($file['mtime'] <= $observedAtUtc
                && ($maximumAgeSeconds === null || $observedAtUtc - $file['mtime'] <= $maximumAgeSeconds)
                && $file['size'] >= $minimumBytes) {
                $candidates[] = $file;
            }
        }

        if ($candidates !== []) {
            usort($candidates, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['filename'], $b['filename']));

            return ['status' => 'present', 'reason' => 'matching_file_observed', 'filename' => $candidates[0]['filename']];
        }

        $reason = ! $scan['directory_present'] ? 'directory_missing' : ($scan['files'] === [] ? 'no_matching_file' : 'no_eligible_file');

        return ['status' => 'absent', 'reason' => $reason, 'filename' => null];
    }
}
