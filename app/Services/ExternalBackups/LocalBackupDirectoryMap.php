<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;
use RuntimeException;

/**
 * Operator-owned allowlist of mounted local directories, never request data.
 * An alias and optional relative subdirectory identify a metadata scan target.
 *
 * This is a bounded path/identity check, not an atomic descriptor-based
 * traversal. The operator must protect ancestors and mount topology.
 */
final class LocalBackupDirectoryMap
{
    /** @param array<string, string> $roots */
    public function __construct(private readonly array $roots) {}

    /**
     * @return array<string, array<string|int, int>>
     */
    public function snapshot(string $alias, string $relativeDirectory = ''): array
    {
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $alias) !== 1
            || ! isset($this->roots[$alias])) {
            throw new InvalidArgumentException('Unknown approved backup directory mapping.');
        }

        $root = $this->roots[$alias];
        if (! str_starts_with($root, '/') || $root === '/') {
            throw new InvalidArgumentException('Invalid approved backup root.');
        }

        $parts = $this->segments(substr($root, 1));
        if ($relativeDirectory !== '') {
            $parts = [...$parts, ...$this->segments($relativeDirectory)];
        }

        if (count($parts) > 32 || strlen(implode('/', $parts)) > 4095) {
            throw new InvalidArgumentException('Approved backup directory exceeds path bounds.');
        }

        $path = '';
        $chain = [];
        foreach ($parts as $part) {
            $path .= '/' . $part;
            $chain[$path] = $this->directoryStat($path);
        }

        if (! is_readable($path)) {
            throw new RuntimeException('Approved backup directory is inaccessible.');
        }

        return $chain;
    }

    /** @param array<string, array<string|int, int>> $chain */
    public function assertUnchanged(array $chain): void
    {
        $last = array_key_last($chain);
        if ($last === null) {
            throw new InvalidArgumentException('Missing approved directory identity.');
        }

        foreach ($chain as $path => $before) {
            $after = $this->directoryStat($path);

            if ($path === $last) {
                if (! ExternalBackupDirectoryIdentity::same($before, $after)) {
                    throw new RuntimeException('Approved backup directory changed during scan.');
                }
            } else {
                // Unrelated directory entries may change in a trusted ancestor.
                foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $field) {
                    if ($before[$field] !== $after[$field]) {
                        throw new RuntimeException('Approved backup directory ancestor changed during scan.');
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        if (strlen($path) > 4095 || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) !== 0
            || preg_match('//u', $path) !== 1) {
            throw new InvalidArgumentException('Invalid approved backup directory path.');
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
                throw new InvalidArgumentException('Invalid approved backup directory path component.');
            }
        }

        return $parts;
    }

    /** @return array<string|int, int> */
    private function directoryStat(string $path): array
    {
        clearstatcache(true);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
            || realpath($path) !== $path || ! is_executable($path)) {
            throw new RuntimeException('Approved backup directory is missing, inaccessible or unsafe.');
        }

        return $stat;
    }
}
