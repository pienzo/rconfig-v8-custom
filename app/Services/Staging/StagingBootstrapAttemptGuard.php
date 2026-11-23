<?php

namespace App\Services\Staging;

/**
 * Dormant filesystem primitive for a FUTURE explicit, synthetic-only bootstrap.
 *
 * This does not migrate, seal an admin, or write the successful-install marker.
 * A future driver must first verify container identity, the persistent Docker
 * volume, a recovery checkpoint, MariaDB/Redis freshness, and network isolation.
 */
final class StagingBootstrapAttemptGuard
{
    private const ATTEMPT_DIRECTORY = '.staging-bootstrap-attempt';

    private const COMPLETION_MARKER = '.staging-bootstrap-complete';

    /**
     * Claim a never-reused attempt slot. A crash leaves the directory in place.
     *
     * Atomic mkdir prevents two cooperating drivers from claiming the same
     * filesystem directory at once. This does NOT imply power-loss durability,
     * container identity validation or a distributed SQL/Redis lock.
     */
    public function claim(string $storage): bool
    {
        // The eventual caller must supply the fixed, persistent Laravel storage
        // mount, not an arbitrary relative path or a symlinked directory.
        if (! str_starts_with($storage, '/')
            || $storage === '/'
            || ! is_dir($storage)
            || is_link($storage)
            || realpath($storage) !== $storage
            || ! is_writable($storage)) {
            return false;
        }

        $attempt = $storage . '/' . self::ATTEMPT_DIRECTORY;
        $completed = $storage . '/' . self::COMPLETION_MARKER;

        // file_exists() alone misses dangling symlinks. Never unlink, repair,
        // overwrite or recycle either record, including after a failed run.
        if (file_exists($attempt) || is_link($attempt)
            || file_exists($completed) || is_link($completed)) {
            return false;
        }

        $previousUmask = umask(0077);

        try {
            $created = @mkdir($attempt, 0700);
        } finally {
            umask($previousUmask);
        }

        if (! $created) {
            return false;
        }

        // If a filesystem cannot honor 0700, refuse while preserving the
        // claimed directory as evidence; do not turn this into a retry.
        clearstatcache(true, $attempt);
        $mode = @fileperms($attempt);

        return is_dir($attempt)
            && ! is_link($attempt)
            && $mode !== false
            && ($mode & 0777) === 0700;
    }
}
