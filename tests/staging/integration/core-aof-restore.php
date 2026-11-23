<?php

/**
 * Actual Core migration + seeded-admin sealing in disposable MariaDB 10.11,
 * then verification against a SECOND restored MariaDB 10.11 and Redis AOF.
 *
 * No production runtime gate is bypassed: this is a separate CI-only test
 * gate, NOT an operator command, Docker role, production or VM installer.
 */
use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

function coreRecoveryVerify(bool $ok, string $reason): void
{
    if (! $ok) {
        throw new RuntimeException('Synthetic Core recovery CI refused: ' . $reason);
    }
}

$phase = $argv[1] ?? '';
coreRecoveryVerify(count($argv) === 2 && in_array($phase, ['install', 'verify'], true), 'phase');
coreRecoveryVerify(
    getenv('GITHUB_ACTIONS') === 'true'
    && getenv('CI') === 'true'
    && getenv('STAGING_CORE_RESTORE_CI') === 'disposable-v1'
    && getenv('APP_ENV') === 'testing'
    && getenv('APP_DEBUG') === 'false'
    && getenv('DB_CONNECTION') === 'mysql'
    && getenv('DB_HOST') === '127.0.0.1'
    && getenv('DB_USERNAME') === 'root'
    && getenv('DB_PASSWORD') === ''
    && getenv('REDIS_HOST') === '127.0.0.1'
    && getenv('REDIS_DB') === '0'
    && getenv('REDIS_CACHE_DB') === '1'
    && getenv('MAIL_MAILER') === 'log'
    && getenv('RCONFIG_F02_QUEUE_ENABLED') === 'false',
    'hosted CI-only identity'
);

$source = $phase === 'install';
$database = $source ? 'rconfig_ci_core_restore_source' : 'rconfig_ci_core_restore_target';
$dbPort = $source ? '3306' : '3307';
$redisPort = $source ? '6387' : '6388';
coreRecoveryVerify(
    getenv('DB_DATABASE') === $database
    && getenv('DB_PORT') === $dbPort
    && getenv('REDIS_PORT') === $redisPort,
    'dedicated SQL/Redis ports'
);

$root = getenv('STAGING_CORE_RESTORE_ROOT');
coreRecoveryVerify(is_string($root)
    && preg_match('#\A/tmp/rconfig-core-restore-[A-Za-z0-9]{10}\z#D', $root) === 1
    && is_dir($root)
    && ! is_link($root)
    && realpath($root) === $root
    && (fileperms($root) & 0777) === 0700, 'private disposable fixture root');

$storage = $root . ($source ? '/source-storage' : '/recovered-storage');

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

coreRecoveryVerify(
    config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && config('database.connections.mysql.database') === $database
    && (string) config('database.connections.mysql.port') === $dbPort
    && config('database.redis.default.host') === '127.0.0.1'
    && (string) config('database.redis.default.port') === $redisPort
    && (string) config('database.redis.default.database') === '0'
    && (string) config('database.redis.cache.database') === '1'
    && config('mail.default') === 'log',
    'real Laravel DB/Redis/mail configuration'
);

$appKey = config('app.key');
coreRecoveryVerify(is_string($appKey)
    && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/D', $appKey) === 1,
    'stable ephemeral Laravel APP_KEY');

$service = new StagingBootstrapStateService;
$sealer = new SealDefaultAdminService;
$password = str_repeat('e', 64); // deliberately synthetic; never a real secret

if ($source) {
    coreRecoveryVerify(is_dir($storage)
        && ! is_link($storage)
        && realpath($storage) === $storage
        && (fileperms($storage) & 0777) === 0750
        && $service->isFresh(), 'dedicated fresh SQL and Redis DB0/DB1');

    $gate = new class($storage, $appKey, $password) implements StagingBootstrapRuntimeGate
    {
        public function __construct(
            private readonly string $storage,
            private readonly string $key,
            private readonly string $password,
        ) {}

        public function permitted(): bool
        {
            return getenv('STAGING_CORE_RESTORE_CI') === 'disposable-v1'
                && config('app.key') === $this->key
                && config('mail.default') === 'log'
                && config('database.connections.mysql.database') === 'rconfig_ci_core_restore_source';
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
                && ! file_exists($this->storage . '/.staging-bootstrap-complete');
        }
    };

    $adapter = new LaravelStagingBootstrapStages($service, $sealer, $gate);
    coreRecoveryVerify((new StagingBootstrapCoordinator)->run($storage, $adapter),
        'actual Core migration/seal/marker');
    coreRecoveryVerify(! (new StagingBootstrapCoordinator)->run($storage, $adapter),
        'successful Core installation must refuse second attempt');
} else {
    // The target is an imported SQL dump and an AOF recovered to a DIFFERENT
    // Redis instance. Never invoke any migration or sealing on recovery.
    coreRecoveryVerify(! $service->isFresh(), 'recovery cannot be treated as fresh');
}

coreRecoveryVerify($service->hasCompleteMigrationLedger()
    && ! $sealer->ready()
    && $service->isSealed($password), 'exact Core migrated/sealed state');
coreRecoveryVerify(
    DB::table('users')->count() === 1
    && DB::table('settings')->count() === 1
    && DB::table('users')->where('id', 1)->value('name') === 'Staging administrator'
    && DB::table('settings')->where('id', 1)->value('mail_driver') === 'log',
    'single protected Core admin and log-only settings'
);
if ($source) {
    coreRecoveryVerify((int) Redis::connection('default')->dbsize() === 0
        && (int) Redis::connection('cache')->dbsize() === 0,
        'no Redis fixture keys until after real Core migration');
} else {
    coreRecoveryVerify(
        Redis::connection('default')->get('ci:core-state') === 'snapshot-A'
        && Redis::connection('cache')->get('ci:core-cache') === 'snapshot-A'
        && (int) Redis::connection('default')->dbsize() === 1
        && (int) Redis::connection('cache')->dbsize() === 1,
        'recovered Redis AOF DB0/DB1 retains precrash generation'
    );
}

$attempt = $storage . '/.staging-bootstrap-attempt';
$marker = $storage . '/.staging-bootstrap-complete';
coreRecoveryVerify(is_dir($attempt)
    && ! is_link($attempt)
    && realpath($attempt) === $attempt
    && (fileperms($attempt) & 0777) === 0700
    && is_file($marker)
    && ! is_link($marker)
    && file_get_contents($marker) === 'staging-bootstrap-v1'
    && (fileperms($marker) & 0777) === 0640, 'private Core bootstrap records restored');

echo $source
    ? "CI real Core migration/seal succeeded on fresh MariaDB10.11; private marker present\n"
    : "CI recovered complete Core schema/admin/mail state and private marker on second MariaDB10.11\n";
