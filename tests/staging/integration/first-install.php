<?php

/**
 * Isolated GitHub-hosted first-install probe for Core's REAL Laravel migrations.
 *
 * This is deliberately a test driver, NOT the production bootstrap gate.
 * It uses ephemeral MySQL 8 / Redis 7 CI services, a synthetic administrator
 * password, and a private temporary directory. Never invoke on vm-gpt.
 */
use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStages;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

function verify(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException('Synthetic first-install CI refused: ' . $message);
    }
}

$scenario = $argv[1] ?? '';
verify(count($argv) === 2 && in_array($scenario, ['interrupted', 'success'], true), 'scenario');
verify(getenv('GITHUB_ACTIONS') === 'true'
    && getenv('STAGING_FIRST_INSTALL_CI') === 'disposable-v1'
    && getenv('APP_ENV') === 'testing'
    && getenv('DB_HOST') === '127.0.0.1'
    && getenv('DB_USERNAME') === 'root'
    && getenv('DB_PASSWORD') === ''
    && getenv('REDIS_HOST') === '127.0.0.1'
    && getenv('MAIL_MAILER') === 'log', 'hosted synthetic-only identity');

$database = getenv('DB_DATABASE');
verify($database === 'rconfig_ci_bootstrap_' . $scenario, 'dedicated database');

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

verify(config('database.default') === 'mysql'
    && config('database.connections.mysql.database') === $database
    && config('mail.default') === 'log'
    && (string) config('database.redis.default.database') === '0'
    && (string) config('database.redis.cache.database') === '1', 'Laravel configuration');

$originalKey = config('app.key');
verify(is_string($originalKey) && str_starts_with($originalKey, 'base64:'), 'stable synthetic APP_KEY');

$tables = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()');
verify($tables !== null && (int) $tables->c === 0, 'MySQL must start with zero tables');
verify((int) Redis::connection('default')->dbsize() === 0
    && (int) Redis::connection('cache')->dbsize() === 0, 'both Redis databases must start empty');

$storage = realpath(sys_get_temp_dir()) . '/rconfig-staging-first-install-' . bin2hex(random_bytes(12));
verify(mkdir($storage, 0700) && realpath($storage) === $storage, 'private fixture storage');

// This password is a synthetic constant, never a real secret.
$password = str_repeat('c', 64);
$gate = new class($storage, $password, $originalKey) implements StagingBootstrapRuntimeGate
{
    public function __construct(
        private readonly string $storage,
        private readonly string $password,
        private readonly string $appKey,
    ) {}

    public function permitted(): bool
    {
        return getenv('STAGING_FIRST_INSTALL_CI') === 'disposable-v1'
            && config('app.key') === $this->appKey
            && config('database.connections.mysql.database') === getenv('DB_DATABASE')
            && config('mail.default') === 'log';
    }

    public function attemptClaimed(): bool
    {
        $path = $this->storage . '/.staging-bootstrap-attempt';
        clearstatcache(true, $path);

        return is_dir($path)
            && ! is_link($path)
            && realpath($path) === $path
            && (fileperms($path) & 0777) === 0700;
    }

    public function replacementPassword(): ?string
    {
        return $this->permitted() && $this->attemptClaimed() ? $this->password : null;
    }

    public function postflightReady(): bool
    {
        return $this->permitted()
            && $this->attemptClaimed()
            && is_dir($this->storage)
            && ! file_exists($this->storage . '/.staging-bootstrap-complete')
            && ! is_link($this->storage . '/.staging-bootstrap-complete');
    }
};

$service = new StagingBootstrapStateService;
$adapter = new LaravelStagingBootstrapStages($service, new SealDefaultAdminService, $gate);
$coordinator = new StagingBootstrapCoordinator;

if ($scenario === 'interrupted') {
    // Deliberately stop AFTER real migrations but BEFORE default admin sealing.
    // No rollback or retry: this must leave a nonempty schema and the attempt
    // record as durable refusal evidence on the disposable runner.
    $fault = new class($adapter) implements StagingBootstrapStages
    {
        public function __construct(private readonly LaravelStagingBootstrapStages $delegate) {}

        public function fresh(): bool
        {
            return $this->delegate->fresh();
        }

        public function migrate(): bool
        {
            return $this->delegate->migrate();
        }

        public function migrated(): bool
        {
            verify($this->delegate->migrated(), 'real schema and initial seeded admin before injected stop');

            return false;
        }

        public function seal(): bool
        {
            throw new RuntimeException('seal must not follow injected interruption');
        }

        public function sealed(): bool
        {
            throw new RuntimeException('sealed must not follow injected interruption');
        }

        public function postflight(): bool
        {
            throw new RuntimeException('postflight must not follow injected interruption');
        }
    };

    verify(! $coordinator->run($storage, $fault), 'fault must prevent completion');
    verify($gate->attemptClaimed(), 'failed attempt must remain');
    verify(! file_exists($storage . '/.staging-bootstrap-complete'), 'no marker after interruption');
    verify($service->hasCompleteMigrationLedger() && $service->isMigrated(), 'real migrated Core schema is present but unsealed');
    verify(! $adapter->fresh(), 'partial installation must not appear fresh');
    verify(! $coordinator->run($storage, $adapter), 'coordinator must refuse retry');
    verify((new SealDefaultAdminService)->ready(), 'default Core admin still needs sealing');
    verify(config('app.key') === $originalKey, 'APP_KEY unchanged after migration');

    echo "synthetic CI: real Core migrate succeeded; injected interruption withheld marker and retry\n";

    exit(0);
}

verify($coordinator->run($storage, $adapter), 'full real Core migration + admin sealing + marker');
verify($service->hasCompleteMigrationLedger() && $service->isSealed($password), 'exact Core ledger/admin/mail seal');
verify(! (new SealDefaultAdminService)->ready(), 'seeded default administrator is no longer valid');
verify(DB::table('users')->count() === 1 && DB::table('settings')->count() === 1, 'single seeded rows');
verify(Hash::check($password, (string) DB::table('users')->where('id', 1)->value('password')), 'new password hash');
verify(DB::table('settings')->where('id', 1)->value('mail_driver') === 'log', 'database mail transport log-only');
verify(file_get_contents($storage . '/.staging-bootstrap-complete') === 'staging-bootstrap-v1', 'completion marker');
verify((fileperms($storage . '/.staging-bootstrap-attempt') & 0777) === 0700, 'private attempt');
verify(! $coordinator->run($storage, $adapter), 'successful bootstrap cannot be repeated');
verify(config('app.key') === $originalKey, 'APP_KEY unchanged after full install');

echo "synthetic CI: full real Core migrate/admin seal/marker passed; rerun refused\n";
