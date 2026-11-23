<?php

namespace App\Services\Staging;

/**
 * Fail-closed gate for the exact, separate, unprivileged synthetic container.
 *
 * This only verifies local PHP/configuration/filesystem invariants. A future
 * one-shot operator entrypoint MUST separately prove image identity, isolated
 * network egress, exclusive host roles and consistent tested recovery state.
 */
final class StagingBootstrapContainerGate implements StagingBootstrapRuntimeGate
{
    private const ADMIN_FILE = '/run/secrets/staging_admin_password';

    private readonly string|false $initialEnvFingerprint;
    private readonly string $initialKeyFingerprint;

    public function __construct()
    {
        $env = base_path('.env');
        $key = config('app.key');

        // In-memory digests only; never include the APP_KEY or digest in logs.
        $this->initialEnvFingerprint = $this->protectedFile($env) ? @hash_file('sha256', $env) : false;
        $this->initialKeyFingerprint = is_string($key) ? hash('sha256', $key) : '';
    }

    public function redisIndicesValid(): bool
    {
        return config('database.redis.client') === 'predis'
            && config('database.redis.default.host') === 'redis'
            && config('database.redis.cache.host') === 'redis'
            && (string) config('database.redis.default.port') === '6379'
            && (string) config('database.redis.cache.port') === '6379'
            && (string) config('database.redis.default.database') === '0'
            && (string) config('database.redis.cache.database') === '1'
            && config('queue.connections.redis.connection') === 'default'
            && config('cache.stores.redis.connection') === 'cache';
    }

    public function permitted(): bool
    {
        if (PHP_SAPI !== 'cli'
            || ! function_exists('posix_geteuid')
            || posix_geteuid() !== 1000
            || posix_getegid() !== 33
            || getenv('IS_DOCKER') !== 'true'
            || getenv('RCONFIG_STAGING_BOOTSTRAP') !== 'core-synthetic-v1'
            || config('app.env') !== 'production'
            || config('app.debug') !== false
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.driver') !== 'mysql'
            || config('database.connections.mysql.host') !== 'db'
            || (string) config('database.connections.mysql.port') !== '3306'
            || config('database.connections.mysql.database') !== 'rconfig_staging'
            || config('database.connections.mysql.username') !== 'rconfig_staging'
            || (string) config('database.connections.mysql.unix_socket') !== ''
            || ! $this->redisIndicesValid()
            || config('queue.default') !== 'redis'
            || config('cache.default') !== 'redis'
            || config('session.driver') !== 'redis'
            || config('rConfig.f02_queue_enabled', true) !== false
            // Current provision-env.sh omits MAIL_MAILER=log. The existing VM
            // will therefore REFUSE until that separate config gate is reviewed.
            || config('mail.default') !== 'log') {
            return false;
        }

        // Process-level overrides can supersede the protected .env file.
        foreach ([
            'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST',
            'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET',
            'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB',
            'REDIS_CACHE_DB', 'QUEUE_CONNECTION', 'CACHE_DRIVER',
            'SESSION_DRIVER', 'RCONFIG_F02_QUEUE_ENABLED', 'MAIL_MAILER',
        ] as $key) {
            if (getenv($key) !== false) {
                return false;
            }
        }

        $dbPassword = config('database.connections.mysql.password');
        $redisPassword = config('database.redis.default.password');
        $key = config('app.key');

        if (! is_string($dbPassword)
            || preg_match('/\A[0-9a-fA-F]{64}\z/D', $dbPassword) !== 1
            || ! is_string($redisPassword)
            || preg_match('/\A[0-9a-fA-F]{64}\z/D', $redisPassword) !== 1
            || config('database.redis.cache.password') !== $redisPassword
            || ! is_string($key)
            || preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/D', $key) !== 1
            || $this->initialEnvFingerprint === false
            || ! $this->protectedFile(base_path('.env'))
            || ! $this->protectedFile(self::ADMIN_FILE)) {
            return false;
        }

        $currentEnvFingerprint = @hash_file('sha256', base_path('.env'));

        return is_string($currentEnvFingerprint)
            && hash_equals($this->initialEnvFingerprint, $currentEnvFingerprint)
            && hash_equals($this->initialKeyFingerprint, hash('sha256', $key));
    }

    public function replacementPassword(): ?string
    {
        if (! $this->permitted()) {
            return null;
        }

        $value = @file_get_contents(self::ADMIN_FILE);

        if (! is_string($value)
            || preg_match('/\A[0-9a-fA-F]{64}\n\z/D', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 64);
    }

    public function postflightReady(): bool
    {
        if (! $this->permitted()) {
            return false;
        }

        $storage = storage_path();
        $data = $storage . '/app/rconfig/data';
        $cache = base_path('bootstrap/cache');
        $marker = $storage . '/.staging-bootstrap-complete';
        clearstatcache(true, $data);

        if ($storage !== base_path('storage')
            || ! is_dir($storage)
            || is_link($storage)
            || realpath($storage) !== $storage
            || ! is_writable($storage)
            || ! is_dir($data)
            || is_link($data)
            || (fileperms($data) & 0777) !== 0750
            || ! is_dir($cache)
            || is_link($cache)
            || ! is_writable($cache)
            || file_exists($marker)
            || is_link($marker)) {
            return false;
        }

        foreach ([
            'default.yml', 'ios-ssh-enable.yml', 'ios-ssh-noenable.yml',
            'ios-telnet-enable.yml', 'ios-telnet-noenable.yml',
        ] as $template) {
            $file = $storage . '/app/rconfig/templates/' . $template;

            if (! is_file($file) || is_link($file) || filesize($file) === 0) {
                return false;
            }
        }

        return $this->permitted();
    }

    private function protectedFile(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path)
            && ! is_link($path)
            && is_readable($path)
            && fileowner($path) === 0
            && filegroup($path) === 33
            && (fileperms($path) & 0777) === 0640;
    }
}
