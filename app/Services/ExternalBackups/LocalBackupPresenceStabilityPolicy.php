<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;

/**
 * Optional metadata stability evidence; never proof of a completed transfer.
 * Only trusted bounded scanner observations may be supplied by the caller.
 * Scheduling, deadlines, persistence and retries belong to the lifecycle layer.
 */
final class LocalBackupPresenceStabilityPolicy
{
    public function __construct(private readonly LocalBackupPresencePolicy $presence) {}

    /**
     * @param  array<string, mixed>  $laterScan
     * @param  array<string, mixed>|null  $earlierScan
     * @return array{status: string, reason: string, filename: string|null}
     */
    public function evaluate(
        array $laterScan,
        int $laterObservedAt,
        ?int $maximumAgeSeconds = null,
        int $minimumBytes = 1,
        ?int $quietSeconds = 30,
        ?array $earlierScan = null,
        ?int $earlierObservedAt = null,
    ): array {
        if ($quietSeconds !== null && ($quietSeconds < 1 || $quietSeconds > 3600)) {
            throw new InvalidArgumentException('Invalid local backup quiet period.');
        }

        $presence = $this->presence->evaluate($laterScan, $laterObservedAt, $maximumAgeSeconds, $minimumBytes);

        if ($quietSeconds === null) {
            return $presence;
        }

        if (($earlierScan === null) !== ($earlierObservedAt === null)
            || ($earlierObservedAt !== null && ($earlierObservedAt < 0 || $earlierObservedAt > $laterObservedAt))) {
            throw new InvalidArgumentException('Invalid local backup stability observation window.');
        }

        $later = $this->identityByName($laterScan);
        $earlier = [];
        if ($earlierScan !== null && $earlierObservedAt !== null) {
            // Validate the complete earlier scanner shape before indexing it.
            $this->presence->evaluate($earlierScan, $earlierObservedAt);
            $earlier = $this->identityByName($earlierScan);
        }

        if ($presence['status'] !== 'present') {
            return $presence;
        }

        $pending = [
            'status' => 'pending',
            'reason' => 'stability_wait',
            'filename' => $presence['filename'],
        ];

        if ($earlierObservedAt === null || $laterObservedAt - $earlierObservedAt < $quietSeconds) {
            return $pending;
        }

        $stable = [];
        foreach ($later as $filename => $file) {
            if (! isset($earlier[$filename]) || $earlier[$filename] !== $file
                || max($file['mtime'], $file['ctime']) > $earlierObservedAt
                || $laterObservedAt - max($file['mtime'], $file['ctime']) < $quietSeconds) {
                continue;
            }

            $stable[] = $file;
        }

        // Reapply freshness and minimum size to the stable subset. Any
        // eligible stable match suffices; newer unstable files do not mask it.
        $stableScan = $laterScan;
        $stableScan['files'] = $stable;
        $result = $this->presence->evaluate($stableScan, $laterObservedAt, $maximumAgeSeconds, $minimumBytes);

        if ($result['status'] !== 'present') {
            return $pending;
        }

        $result['reason'] = 'stable_matching_file_observed';

        return $result;
    }

    /**
     * The base presence policy validates basenames, size, mtime and duplicates.
     * Stability additionally requires the scanner's complete file identity.
     *
     * @param  array<string, mixed>  $scan
     * @return array<string|int, array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>
     */
    private function identityByName(array $scan): array
    {
        $indexed = [];
        foreach ($scan['files'] as $file) {
            foreach (['ctime', 'device', 'inode'] as $field) {
                if (! isset($file[$field]) || ! is_int($file[$field]) || $file[$field] < 0) {
                    throw new InvalidArgumentException('Incomplete local backup stability metadata.');
                }
            }

            if ($file['inode'] === 0) {
                throw new InvalidArgumentException('Invalid local backup inode.');
            }

            $indexed[$file['filename']] = [
                'filename' => $file['filename'],
                'size' => $file['size'],
                'mtime' => $file['mtime'],
                'ctime' => $file['ctime'],
                'device' => $file['device'],
                'inode' => $file['inode'],
            ];
        }

        return $indexed;
    }
}
