<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;
use RuntimeException;

/**
 * Match a single upload filename, never a filesystem path.
 *
 * Regex expressions are supplied WITHOUT delimiters and are always matched
 * against the entire basename. Glob mode supports only * and ? wildcards.
 */
final class ExternalBackupFilenameMatcher
{
    public const MODE_GLOB = 'glob';

    public const MODE_REGEX = 'regex';

    private const MAX_PATTERN_BYTES = 255;

    private const MAX_FILENAME_BYTES = 255;

    /**
     * Validate a pattern before persisting configuration.
     *
     * @throws InvalidArgumentException
     */
    public function validatePattern(string $pattern, string $mode): void
    {
        $this->compile($pattern, $mode);
    }

    /**
     * Return whether a single basename matches a validated glob or regex.
     * A malformed pattern is a configuration error, not a negative file check.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function matches(string $filename, string $pattern, string $mode): bool
    {
        $expression = $this->compile($pattern, $mode);

        if (! $this->isSafeBasename($filename)) {
            return false;
        }

        $matches = [];
        $result = @preg_match($expression, $filename, $matches, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw new RuntimeException('External backup pattern matching failed: ' . preg_last_error_msg());
        }

        // Re-check the complete matched text: PCRE control verbs such as
        // (*ACCEPT) must not turn a partial regex match into a success.
        return $result === 1
            && ($matches[0][1] ?? -1) === 0
            && ($matches[0][0] ?? null) === $filename;
    }

    private function compile(string $pattern, string $mode): string
    {
        if (! in_array($mode, [self::MODE_GLOB, self::MODE_REGEX], true)) {
            throw new InvalidArgumentException('Unsupported external backup pattern mode.');
        }

        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_BYTES || preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
            throw new InvalidArgumentException('External backup pattern is empty, too long or contains control characters.');
        }

        if ($mode === self::MODE_GLOB) {
            if (str_contains($pattern, '/') || str_contains($pattern, '\\')) {
                throw new InvalidArgumentException('Glob patterns must match basenames, not paths.');
            }

            $escaped = preg_quote($pattern, '~');
            $source = str_replace(['\*', '\?'], ['.*', '.'], $escaped);
        } else {
            // Regex input contains no delimiters. Escape our own delimiter.
            $source = str_replace('~', '\~', $pattern);
        }

        // Fail closed under bounded PCRE work, and anchor every expression.
        // Limits are deliberately lower than the process-wide PHP defaults.
        $expression = '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)\A(?:' . $source . ')\z~u';

        if (@preg_match($expression, '') === false) {
            throw new InvalidArgumentException('Invalid external backup pattern syntax.');
        }

        return $expression;
    }

    private function isSafeBasename(string $filename): bool
    {
        if ($filename === '' || $filename === '.' || $filename === '..' || strlen($filename) > self::MAX_FILENAME_BYTES) {
            return false;
        }

        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $filename) === 0
            && preg_match('//u', $filename) === 1;
    }
}
