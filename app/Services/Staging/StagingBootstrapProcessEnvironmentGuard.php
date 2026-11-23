<?php

namespace App\Services\Staging;

/**
 * Examine the ORIGINAL execve environment, before Laravel Dotenv calls putenv().
 *
 * getenv() after Console Kernel bootstrap cannot identify injected process
 * overrides: Illuminate\Support\Env's default PutenvAdapter exposes the
 * protected .env values there as well. On the synthetic Linux container,
 * /proc/self/environ is the initial execve snapshot, not Dotenv's runtime
 * process environment. If procfs is unavailable, refuse the bootstrap.
 *
 * Never log these raw bytes: they may contain external credentials.
 */
final class StagingBootstrapProcessEnvironmentGuard
{
    private const MAX_BYTES = 65536;

    private const BLOCKED = [
        'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST',
        'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB',
        'REDIS_CACHE_DB', 'QUEUE_CONNECTION', 'CACHE_DRIVER',
        'SESSION_DRIVER', 'RCONFIG_F02_QUEUE_ENABLED', 'MAIL_MAILER',
    ];

    public function safe(): bool
    {
        $snapshot = @file_get_contents('/proc/self/environ');

        return is_string($snapshot)
            && strlen($snapshot) <= self::MAX_BYTES
            && self::safeSnapshot($snapshot);
    }

    /**
     * @internal Public solely for deterministic synthetic unit tests.
     */
    public static function safeSnapshot(string $snapshot): bool
    {
        if ($snapshot === '' || ! str_ends_with($snapshot, "\0")) {
            return false;
        }

        foreach (explode("\0", $snapshot) as $entry) {
            if ($entry === '') {
                continue;
            }

            $separator = strpos($entry, '=');

            if ($separator === false || $separator === 0) {
                return false;
            }

            $key = substr($entry, 0, $separator);

            if (in_array($key, self::BLOCKED, true)) {
                return false;
            }
        }

        return true;
    }
}
