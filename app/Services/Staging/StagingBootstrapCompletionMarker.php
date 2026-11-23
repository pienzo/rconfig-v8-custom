<?php

namespace App\Services\Staging;

/**
 * Inert until called by a future reviewed bootstrap coordinator/adapter.
 *
 * Publishes the exact installation marker using an exclusive hard link from
 * a private attempt directory on the SAME filesystem. A failed publication
 * leaves the attempt and any prepared file in place for manual inspection.
 * This does not provide crash-consistent SQL/Redis recovery or guarantee
 * directory-entry durability after sudden power loss.
 */
final class StagingBootstrapCompletionMarker
{
    private const MARKER = '.staging-bootstrap-complete';

    private const ATTEMPT = '.staging-bootstrap-attempt';

    private const PREPARED = 'completion-marker.ready';

    private const CONTENT = 'staging-bootstrap-v1';

    public function publish(string $storage): bool
    {
        if (! str_starts_with($storage, '/')
            || $storage === '/'
            || ! is_dir($storage)
            || is_link($storage)
            || realpath($storage) !== $storage
            || ! is_writable($storage)) {
            return false;
        }

        $attempt = $storage . '/' . self::ATTEMPT;
        $marker = $storage . '/' . self::MARKER;
        $prepared = $attempt . '/' . self::PREPARED;

        clearstatcache(true, $attempt);
        $mode = @fileperms($attempt);

        if (! is_dir($attempt)
            || is_link($attempt)
            || realpath($attempt) !== $attempt
            || $mode === false
            || ($mode & 0777) !== 0700
            || ! is_writable($attempt)
            || file_exists($marker)
            || is_link($marker)
            || file_exists($prepared)
            || is_link($prepared)) {
            return false;
        }

        // Exclusive creation refuses a preexisting file or dangling symlink.
        // The directory is only writable by the one-shot bootstrap identity.
        $previousUmask = umask(0077);

        try {
            $handle = @fopen($prepared, 'x');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            return false;
        }

        try {
            // The marker is nonsecret. Role preflight reads this exact value.
            if (@fwrite($handle, self::CONTENT) !== strlen(self::CONTENT)
                || ! @chmod($prepared, 0640)
                || ! @fflush($handle)
                || ! @fsync($handle)) {
                return false;
            }
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $prepared);

        if (! is_file($prepared)
            || is_link($prepared)
            || (fileperms($prepared) & 0777) !== 0640) {
            return false;
        }

        // link(), unlike rename(), refuses any existing destination and
        // publishes atomically without replacing a previously written marker.
        // Keep the prepared file inside the attempt directory as evidence.
        return @link($prepared, $marker);
    }
}
