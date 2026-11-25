<?php

namespace App\Services\ExternalBackups;

use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only metadata observations of direct children of a device inbox.
 *
 * This deliberately does not read file contents or declare backup SUCCESS.
 * Files need a later, handle-based safety and stability check before acceptance.
 */
final class ExternalBackupDirectoryScanner
{
    public function __construct(private readonly ExternalBackupFilenameMatcher $matcher) {}

    /**
     * @return array{directory_present: bool, scanned_entries: int, files: list<array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>}
     */
    public function scan(
        string $intakeRoot,
        int $deviceId,
        string $pattern,
        string $mode,
        int $maxEntries = 1000,
        int $maxMatches = 100,
        int $maxScanMillis = 2000
    ): array {
        if ($deviceId < 1 || $maxEntries < 1 || $maxEntries > 10000 || $maxMatches < 1 || $maxMatches > 1000 || $maxMatches > $maxEntries || $maxScanMillis < 1 || $maxScanMillis > 10000) {
            throw new InvalidArgumentException('Invalid external backup scanner bounds or device ID.');
        }

        // Report invalid rules even if the device directory is still missing.
        $this->matcher->validatePattern($pattern, $mode);

        if ($intakeRoot === '' || is_link($intakeRoot) || ! is_dir($intakeRoot)) {
            throw new RuntimeException('External backup intake root is not a trusted directory.');
        }

        $root = realpath($intakeRoot);
        if ($root === false || $root === DIRECTORY_SEPARATOR || ! is_readable($root) || ! is_executable($root)) {
            throw new RuntimeException('External backup intake root is inaccessible or unsafe.');
        }

        $rootBefore = $this->snapshot($root);
        if ($rootBefore === null || ! $this->isDirectory($rootBefore)) {
            throw new RuntimeException('External backup intake root changed during validation.');
        }

        // Only the numeric DB ID contributes a path component; never device names or patterns.
        $directory = $root . DIRECTORY_SEPARATOR . $deviceId;
        $directoryBefore = $this->snapshot($directory);
        if ($directoryBefore === null) {
            if (! ExternalBackupDirectoryIdentity::same($rootBefore, $this->snapshot($root) ?? [])) {
                throw new RuntimeException('External backup intake root changed during validation.');
            }

            return ['directory_present' => false, 'scanned_entries' => 0, 'files' => []];
        }

        if (! $this->isDirectory($directoryBefore) || is_link($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('External backup device directory is not a real direct child.');
        }

        $result = $this->enumerate($directory, $pattern, $mode, $maxEntries, $maxMatches, $maxScanMillis);

        // Detect in-scan replacement of either directory. Not an atomic openat()
        // guarantee: a later safe-open adapter must still validate file handles.
        if (! ExternalBackupDirectoryIdentity::same($rootBefore, $this->snapshot($root) ?? []) || ! ExternalBackupDirectoryIdentity::same($directoryBefore, $this->snapshot($directory) ?? [])) {
            throw new RuntimeException('External backup directory changed during enumeration.');
        }

        return $result;
    }

    /**
     * Scan an operator-authorized directory mapping, not an arbitrary API path.
     *
     * @return array{directory_present: bool, scanned_entries: int, files: list<array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>}
     */
    public function scanMapped(
        LocalBackupDirectoryMap $mapping,
        string $alias,
        string $relativeDirectory,
        string $pattern,
        string $mode,
        int $maxEntries = 1000,
        int $maxMatches = 100,
        int $maxScanMillis = 2000
    ): array {
        return $this->scanMappedObservation($mapping, $alias, $relativeDirectory, $pattern, $mode, $maxEntries, $maxMatches, $maxScanMillis)['scan'];
    }

    /**
     * Bind metadata to the same checked directory chain across observations.
     * The expected fingerprint is internal state, never a user authorization token.
     *
     * @return array{directory_identity: string, scan: array{directory_present: bool, scanned_entries: int, files: list<array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>}}
     */
    public function scanMappedObservation(
        LocalBackupDirectoryMap $mapping,
        string $alias,
        string $relativeDirectory,
        string $pattern,
        string $mode,
        int $maxEntries = 1000,
        int $maxMatches = 100,
        int $maxScanMillis = 2000,
        ?string $expectedIdentity = null
    ): array {
        if ($expectedIdentity !== null && preg_match('/\\A[0-9a-f]{64}\\z/D', $expectedIdentity) !== 1) {
            throw new InvalidArgumentException('Invalid expected backup directory identity.');
        }

        if ($maxEntries < 1 || $maxEntries > 10000 || $maxMatches < 1 || $maxMatches > 1000 || $maxMatches > $maxEntries || $maxScanMillis < 1 || $maxScanMillis > 10000) {
            throw new InvalidArgumentException('Invalid external backup scanner bounds.');
        }

        $this->matcher->validatePattern($pattern, $mode);
        $chain = $mapping->snapshot($alias, $relativeDirectory);
        $directory = array_key_last($chain);
        if ($directory === null) {
            throw new RuntimeException('Approved backup directory is unavailable.');
        }

        $identity = $this->mappedDirectoryFingerprint($chain);
        if ($expectedIdentity !== null && hash_equals($expectedIdentity, $identity) === false) {
            throw new RuntimeException('Approved backup directory identity changed between observations.');
        }

        $result = $this->enumerate($directory, $pattern, $mode, $maxEntries, $maxMatches, $maxScanMillis);

        $mapping->assertUnchanged($chain);

        return ['directory_identity' => $identity, 'scan' => $result];
    }

    /**
     * Ignore changing directory entry timestamps/size between scans: arrivals
     * are normal. The existing in-scan check still detects concurrent changes.
     *
     * @param  array<string, array<string|int, int>>  $chain
     */
    private function mappedDirectoryFingerprint(array $chain): string
    {
        $components = [];
        foreach ($chain as $path => $stat) {
            $components[] = [$path, $stat['dev'], $stat['ino'], $stat['mode'], $stat['uid'], $stat['gid']];
        }

        return hash('sha256', json_encode(['version' => 1, 'components' => $components], JSON_THROW_ON_ERROR));
    }

    /** @return array{directory_present: bool, scanned_entries: int, files: list<array{filename: string, size: int, mtime: int, ctime: int, device: int, inode: int}>} */
    private function enumerate(string $directory, string $pattern, string $mode, int $maxEntries, int $maxMatches, int $maxScanMillis): array
    {
        $started = hrtime(true);
        $entries = 0;
        $matches = [];

        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException $e) {
            throw new RuntimeException('Cannot enumerate external backup device directory.', 0, $e);
        }

        foreach ($iterator as $entry) {
            $entries++;
            if ($entries > $maxEntries || (hrtime(true) - $started) / 1000000 > $maxScanMillis) {
                throw new RuntimeException('External backup directory scan exceeded configured limits.');
            }

            $filename = $entry->getFilename();
            if (! $this->matcher->matches($filename, $pattern, $mode)) {
                continue;
            }

            $filePath = $directory . DIRECTORY_SEPARATOR . $filename;
            $before = $this->snapshot($filePath);
            if ($before === null) {
                throw new RuntimeException('External backup candidate changed during enumeration.');
            }

            // lstat does not follow symlinks; exclude all special files and hardlinks.
            if (! $this->isRegularFile($before) || $before['nlink'] !== 1) {
                continue;
            }

            $after = $this->snapshot($filePath);
            if ($after === null || $before !== $after) {
                throw new RuntimeException('External backup candidate changed during metadata observation.');
            }

            $matches[] = [
                'filename' => $filename,
                'size' => $before['size'],
                'mtime' => $before['mtime'],
                'ctime' => $before['ctime'],
                'device' => $before['dev'],
                'inode' => $before['ino'],
            ];

            if (count($matches) > $maxMatches) {
                throw new RuntimeException('Too many matching external backup files.');
            }
        }

        usort($matches, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));

        return ['directory_present' => true, 'scanned_entries' => $entries, 'files' => $matches];
    }

    /** @return array<string|int, int>|null */
    private function snapshot(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat === false ? null : $stat;
    }

    /** @param array<string|int, int> $stat */
    private function isDirectory(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0040000;
    }

    /** @param array<string|int, int> $stat */
    private function isRegularFile(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0100000;
    }
}
