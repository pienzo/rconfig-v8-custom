<?php

namespace App\Console\Commands;

use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only staging gate; no --apply option or implicit migration is provided.
 */
final class StagingBootstrapStateCommand extends Command
{
    protected $signature = 'staging:bootstrap-state
                            {--phase= : Exactly one of fresh, migrated, sealed}';
    protected $description = 'Read-only synthetic staging schema, queue and admin-state gate';

    public function handle(StagingBootstrapStateService $state): int
    {
        $phase = $this->option('phase');

        if (! is_string($phase) || ! in_array($phase, ['fresh', 'migrated', 'sealed'], true)) {
            return $this->refuse('specify exactly one supported --phase');
        }

        // This command must not probe a real company DB, even read-only.
        if (PHP_SAPI !== 'cli'
            || getenv('IS_DOCKER') !== 'true'
            || getenv('RCONFIG_STAGING_BOOTSTRAP') !== 'core-synthetic-v1'
            || config('app.env') !== 'production'
            || config('app.debug') !== false
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== 'db'
            || config('database.connections.mysql.database') !== 'rconfig_staging'
            || config('database.connections.mysql.username') !== 'rconfig_staging'
            || config('database.redis.default.host') !== 'redis'
            || config('database.redis.cache.host') !== 'redis'
            || config('queue.default') !== 'redis'
            || config('rConfig.f02_queue_enabled', true) !== false) {
            return $this->refuse('synthetic bootstrap-only environment gate failed');
        }

        // Do not permit environment variables to override the protected .env.
        foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD'] as $key) {
            if (getenv($key) !== false) {
                return $this->refuse('secret environment override forbidden');
            }
        }

        try {
            if ($phase === 'fresh') {
                $valid = $state->isFresh();
            } elseif ($phase === 'migrated') {
                $valid = $state->isMigrated();
            } else {
                $file = '/run/secrets/staging_admin_password';
                clearstatcache(true, $file);

                if (! is_file($file) || is_link($file)
                    || fileowner($file) !== 0 || filegroup($file) !== 33
                    || (fileperms($file) & 0777) !== 0640) {
                    return $this->refuse('protected admin credential unavailable');
                }

                $raw = file_get_contents($file);

                if (! is_string($raw)
                    || preg_match('/\A[0-9a-fA-F]{64}\n\z/D', $raw) !== 1) {
                    return $this->refuse('protected admin credential invalid');
                }

                $valid = $state->isSealed(substr($raw, 0, 64));
                unset($raw);
            }
        } catch (Throwable) {
            // Never log SQL exceptions or connection credentials into CI/UI.
            return $this->refuse('state inspection failed');
        }

        if (! $valid) {
            return $this->refuse('state does not match required phase');
        }

        $this->info('staging bootstrap state passed: ' . $phase . ' (no changes)');

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->error('staging bootstrap state refused: ' . $reason);

        return self::FAILURE;
    }
}
