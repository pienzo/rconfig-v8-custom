<?php

/**
 * GitHub-hosted, disposable ONLY: MariaDB 10.11 real Core migration SIGKILL.
 *
 * Four scoped phases: run (killed externally after one migration), inspect,
 * reprovision (recreate ONLY the named CI database), verify (read only).
 * This is NOT a snapshot restore or an operator bootstrap command.
 */
use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

function ensureCrashTest(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException('Disposable staging crash test refused: ' . $reason);
    }
}

$phase = $argv[1] ?? '';
ensureCrashTest(count($argv) === 2
    && in_array($phase, ['run', 'inspect', 'reprovision', 'verify'], true), 'phase');
ensureCrashTest(getenv('GITHUB_ACTIONS') === 'true'
    && getenv('STAGING_CRASH_CI') === 'disposable-mariadb-v1'
    && getenv('RUNNER_OS') === 'Linux'
    && getenv('APP_ENV') === 'testing'
    && getenv('APP_DEBUG') === 'false'
    && getenv('DB_CONNECTION') === 'mysql'
    && getenv('DB_HOST') === '127.0.0.1'
    && getenv('DB_PORT') === '3306'
    && getenv('DB_USERNAME') === 'root'
    && getenv('DB_PASSWORD') === ''
    && getenv('DB_DATABASE') === 'rconfig_ci_bootstrap_crash'
    && getenv('REDIS_HOST') === '127.0.0.1'
    && getenv('REDIS_PORT') === '6379'
    && getenv('REDIS_DB') === '0'
    && getenv('REDIS_CACHE_DB') === '1'
    && getenv('MAIL_MAILER') === 'log'
    && getenv('RCONFIG_F02_QUEUE_ENABLED') === 'false', 'CI-only environment');

$root = getenv('STAGING_CRASH_ROOT');
ensureCrashTest(is_string($root)
    && preg_match('#\A/tmp/rconfig-staging-crash-[A-Za-z0-9]{10}\z#D', $root) === 1
    && is_dir($root)
    && ! is_link($root)
    && realpath($root) === $root
    && (fileperms($root) & 0777) === 0700, 'private disposable test root');

$storage = $root . '/failed-storage';
$recoveredStorage = $root . '/reprovisioned-storage';
$signal = $root . '/migration-ended.ready';

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

ensureCrashTest(config('database.default') === 'mysql'
    && config('database.connections.mysql.database') === 'rconfig_ci_bootstrap_crash'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && config('mail.default') === 'log'
    && (string) config('database.redis.default.database') === '0'
    && (string) config('database.redis.cache.database') === '1', 'Laravel runtime configuration');

$keyBefore = config('app.key');
ensureCrashTest(is_string($keyBefore) && str_starts_with($keyBefore, 'base64:'), 'private test APP_KEY');

$tables = static function (): int {
    $row = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()');

    return $row === null ? -1 : (int) $row->n;
};

$attempt = $storage . '/.staging-bootstrap-attempt';
$marker = $storage . '/.staging-bootstrap-complete';
$service = new StagingBootstrapStateService;

if ($phase === 'run') {
    ensureCrashTest(is_dir($storage)
        && ! is_link($storage)
        && realpath($storage) === $storage
        && (fileperms($storage) & 0777) === 0700
        && ! file_exists($attempt)
        && ! file_exists($marker)
        && ! is_link($attempt)
        && ! is_link($marker)
        && ! file_exists($signal)
        && $tables() === 0, 'pristine persistent fixture before migration');
    ensureCrashTest((int) Redis::connection('default')->dbsize() === 0
        && (int) Redis::connection('cache')->dbsize() === 0, 'fresh Redis DB0/DB1');

    // Laravel MigrationEnded follows a real migration up(). Pause only
    // after MariaDB has visibly committed at least one table.
    Event::listen(MigrationEnded::class, static function () use ($tables, $signal): void {
        ensureCrashTest($tables() > 0, 'first Laravel DDL not committed');

        $fd = fopen($signal, 'x');
        ensureCrashTest($fd !== false, 'cannot publish test-only pause signal');

        try {
            ensureCrashTest(fwrite($fd, "ddl-complete\n") === strlen("ddl-complete\n")
                && fflush($fd)
                && fsync($fd), 'cannot sync crash signal');
        } finally {
            fclose($fd);
        }

        // CI supervisor MUST SIGKILL this process. Never proceed to seal.
        sleep(120);
        throw new RuntimeException('Synthetic SIGKILL was never delivered');
    });

    $gate = new class($storage, $keyBefore) implements StagingBootstrapRuntimeGate
    {
        public function __construct(
            private readonly string $storage,
            private readonly string $key,
        ) {}

        public function permitted(): bool
        {
            return getenv('STAGING_CRASH_CI') === 'disposable-mariadb-v1'
                && config('app.key') === $this->key
                && config('mail.default') === 'log'
                && config('database.connections.mysql.database') === 'rconfig_ci_bootstrap_crash';
        }

        public function attemptClaimed(): bool
        {
            $dir = $this->storage . '/.staging-bootstrap-attempt';
            clearstatcache(true, $dir);

            return is_dir($dir)
                && ! is_link($dir)
                && realpath($dir) === $dir
                && (fileperms($dir) & 0777) === 0700;
        }

        public function replacementPassword(): ?string
        {
            return $this->permitted() && $this->attemptClaimed() ? str_repeat('e', 64) : null;
        }

        public function postflightReady(): bool
        {
            return $this->permitted() && $this->attemptClaimed();
        }
    };

    $adapter = new LaravelStagingBootstrapStages($service, new SealDefaultAdminService, $gate);
    (new StagingBootstrapCoordinator)->run($storage, $adapter);

    throw new RuntimeException('Migration resumed without the required external SIGKILL');
}

ensureCrashTest(is_dir($storage)
    && ! is_link($storage)
    && is_dir($attempt)
    && ! is_link($attempt)
    && realpath($attempt) === $attempt
    && (fileperms($attempt) & 0777) === 0700
    && ! file_exists($marker)
    && ! is_link($marker)
    && is_file($signal)
    && file_get_contents($signal) === "ddl-complete\n"
    && $tables() > 0, 'abrupt death must retain partial SQL and attempt without completion');
ensureCrashTest(! $service->hasCompleteMigrationLedger()
    && ! $service->isFresh(), 'incomplete migration must not be accepted as fresh/complete');

if ($phase === 'inspect') {
    echo "synthetic MariaDB CI: SIGKILL left partial real Core DDL, persistent attempt and no marker\n";
    exit(0);
}

if ($phase === 'reprovision') {
    // This is intentionally fresh disposable re-provisioning, NOT a database
    // dump restore. It cannot demonstrate recovery of existing user data.
    ensureCrashTest(! file_exists($recoveredStorage)
        && ! is_link($recoveredStorage)
        && (int) Redis::connection('default')->dbsize() === 0
        && (int) Redis::connection('cache')->dbsize() === 0, 'no Redis mutations and new recovery volume');

    DB::disconnect('mysql');

    // Hard-coded CI database; NEVER interpolate an external identifier.
    $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $server->exec('DROP DATABASE rconfig_ci_bootstrap_crash');
    $server->exec('CREATE DATABASE rconfig_ci_bootstrap_crash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server = null;

    ensureCrashTest(mkdir($recoveredStorage, 0700)
        && realpath($recoveredStorage) === $recoveredStorage
        && (fileperms($recoveredStorage) & 0777) === 0700, 'new recovery storage fixture');
    DB::purge('mysql');
    ensureCrashTest($service->isFresh() && $tables() === 0, 'reprovisioned schema and Redis must be fresh');
    ensureCrashTest(is_dir($attempt) && ! file_exists($marker), 'failed attempt evidence preserved');

    echo "synthetic MariaDB CI: isolated fresh baseline reprovisioned; failed attempt retained\n";
    exit(0);
}

ensureCrashTest($phase === 'verify'
    && is_dir($recoveredStorage)
    && ! is_link($recoveredStorage)
    && (fileperms($recoveredStorage) & 0777) === 0700, 'reprovisioned storage directory');
ensureCrashTest($service->isFresh() && $tables() === 0, 'disposable fresh baseline');
ensureCrashTest(is_dir($attempt) && ! file_exists($marker), 'previous failed attempt still preserved');
ensureCrashTest(config('app.key') === $keyBefore, 'synthetic APP_KEY retained');

echo "synthetic MariaDB CI: fresh reprovision independently checked; no failed-attempt replay\n";
