<?php

declare(strict_types=1);

/**
 * GitHub-hosted disposable probe. NOT an Artisan command or runtime role.
 *
 * This is intentionally excluded by .dockerignore; the hosted workflow mounts
 * it read-only into a source-built, internal-network, unprivileged container.
 * No application or worker starts, and no non-CI environment can invoke it.
 */
use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapContainerGate;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapStages;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;

function ciAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('synthetic first-install assertion failed: ' . $label);
    }
}

try {
    ciAssert(PHP_SAPI === 'cli', 'PHP CLI only');
    ciAssert(getenv('GITHUB_ACTIONS') === 'true', 'GitHub-hosted CI only');
    ciAssert(getenv('RCONFIG_STAGING_CI_INTEGRATION') === 'disposable-v1', 'explicit synthetic CI identity');
    ciAssert(function_exists('posix_geteuid') && posix_geteuid() === 1000 && posix_getegid() === 33, 'nonroot bootstrap identity');
    ciAssert(count($argv) === 2 && in_array($argv[1], ['success', 'crash', 'inspect-crash'], true), 'scenario');
    ciAssert(is_readable('/var/www/html/rconfig/vendor/autoload.php'), 'source-built application dependencies');

    require '/var/www/html/rconfig/vendor/autoload.php';
    $app = require '/var/www/html/rconfig/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $storage = storage_path();
    $attempt = $storage . '/.staging-bootstrap-attempt';
    $marker = $storage . '/.staging-bootstrap-complete';
    $gate = new StagingBootstrapContainerGate;
    $state = new StagingBootstrapStateService;
    $sealer = new SealDefaultAdminService;
    $stages = new LaravelStagingBootstrapStages($state, $sealer, $gate);
    $coordinator = new StagingBootstrapCoordinator;

    if (! $gate->permitted()) {
        // Diagnostic labels only; never display any env value, secret,
        // password fingerprint, connection string or SQL exception.
        $protected = static fn (string $path): bool => is_file($path)
            && ! is_link($path)
            && fileowner($path) === 0
            && filegroup($path) === 33
            && (fileperms($path) & 0777) === 0640;
        $checks = [
            'uid1000-gid33' => posix_geteuid() === 1000 && posix_getegid() === 33,
            'docker-bootstrap-identity' => getenv('IS_DOCKER') === 'true'
                && getenv('RCONFIG_STAGING_BOOTSTRAP') === 'core-synthetic-v1',
            'app-env-debug' => config('app.env') === 'production' && config('app.debug') === false,
            'mysql-dedicated' => config('database.default') === 'mysql'
                && config('database.connections.mysql.host') === 'db'
                && (string) config('database.connections.mysql.port') === '3306'
                && config('database.connections.mysql.database') === 'rconfig_staging'
                && config('database.connections.mysql.username') === 'rconfig_staging',
            'redis-db-mapping' => $gate->redisIndicesValid(),
            'queue-cache-session' => config('queue.default') === 'redis'
                && config('cache.default') === 'redis'
                && config('session.driver') === 'redis',
            'framework-mail-log' => config('mail.default') === 'log',
            'f02-off' => config('rConfig.f02_queue_enabled', true) === false,
            'env-protected' => $protected(base_path('.env')),
            'admin-secret-protected' => $protected('/run/secrets/staging_admin_password'),
            'env-key-format' => preg_match('/\\Abase64:[A-Za-z0-9+\\/]{43}=\\z/D', (string) config('app.key')) === 1,
        ];

        foreach ($checks as $label => $allowed) {
            if (! $allowed) {
                fwrite(STDERR, 'disposable gate failed check: ' . $label . PHP_EOL);
            }
        }

        // getenv() after Laravel boot contains protected Dotenv values too.
        // Check only the original Linux process snapshot, without contents.
        if (! (new \App\Services\Staging\StagingBootstrapProcessEnvironmentGuard)->safe()) {
            fwrite(STDERR, "disposable gate failed check: original-process-environment\n");
        }
    }

    ciAssert($gate->permitted(), 'real synthetic container gate');
    ciAssert(config('mail.default') === 'log', 'Laravel mail transport log');
    ciAssert(config('rConfig.f02_queue_enabled') === false, 'F02 disabled');
    ciAssert(! is_link($storage) && realpath($storage) === $storage, 'canonical storage');

    if ($argv[1] === 'inspect-crash') {
        ciAssert(is_dir($attempt) && (fileperms($attempt) & 0777) === 0700, 'crash attempt retained');
        ciAssert(! file_exists($marker) && ! is_link($marker), 'no success marker after crash');
        ciAssert(! file_exists($attempt . '/completion-marker.ready'), 'no prepared marker after crash');
        ciAssert(! $state->isFresh(), 'migration changed fresh schema');
        ciAssert($state->hasCompleteMigrationLedger(), 'real migration ledger complete');
        ciAssert($sealer->ready(), 'known Core seeds still unsealed and isolated');
        ciAssert(! $coordinator->run($storage, $stages), 'retry is refused without any mutating phase');
        ciAssert(! file_exists($marker), 'retry did not forge a marker');

        echo "disposable real-migrations SIGKILL probe: partial attempt retained, no marker, no retry\n";
        exit(0);
    }

    ciAssert($state->isFresh(), 'brand-new MariaDB schema and Redis DB0/DB1');
    ciAssert(! file_exists($attempt) && ! is_link($attempt), 'no preexisting attempt');
    ciAssert(! file_exists($marker) && ! is_link($marker), 'no preexisting completion');
    $envFingerprint = hash_file('sha256', base_path('.env'));
    $keyFingerprint = hash('sha256', (string) config('app.key'));

    if ($argv[1] === 'crash') {
        // A real successful Laravel migrate and Core-seed verification occur
        // before SIGKILL. The process dies while the default admin is unsealed.
        // This MUST be confined to an internal-only disposable CI network.
        $fault = new class($stages) implements StagingBootstrapStages
        {
            public function __construct(private readonly StagingBootstrapStages $real) {}

            public function fresh(): bool
            {
                return $this->real->fresh();
            }

            public function migrate(): bool
            {
                return $this->real->migrate();
            }

            public function migrated(): bool
            {
                if (! $this->real->migrated()) {
                    return false;
                }

                posix_kill(posix_getpid(), SIGKILL);

                return false;
            }

            public function seal(): bool
            {
                throw new RuntimeException('crash path attempted to seal');
            }

            public function sealed(): bool
            {
                throw new RuntimeException('crash path attempted to check sealed');
            }

            public function postflight(): bool
            {
                throw new RuntimeException('crash path attempted to publish');
            }
        };

        // Never return normally from a successful injected SIGKILL.
        $coordinator->run($storage, $fault);
        throw new RuntimeException('SIGKILL injection was not reached');
    }

    ciAssert($coordinator->run($storage, $stages), 'actual first-install coordinator completed');
    ciAssert($state->hasCompleteMigrationLedger(), 'all Core migrations committed');
    ciAssert(is_dir($attempt) && (fileperms($attempt) & 0777) === 0700, 'private attempt persisted');
    ciAssert(is_file($marker) && ! is_link($marker), 'completion marker published');
    ciAssert(file_get_contents($marker) === 'staging-bootstrap-v1', 'exact success marker');
    ciAssert((fileperms($marker) & 0777) === 0640, 'marker mode');
    ciAssert($gate->permitted() && config('mail.default') === 'log', 'mailer and security gate unchanged');
    ciAssert(! $sealer->ready(), 'default admin seed sealed');
    $password = $gate->replacementPassword();
    ciAssert($password !== null, 'protected replacement password readable');
    ciAssert($state->isSealed($password), 'real protected administrator hash and SMTP neutralization');
    unset($password);
    ciAssert(hash_equals($envFingerprint, hash_file('sha256', base_path('.env'))), 'stable mounted .env');
    ciAssert(hash_equals($keyFingerprint, hash('sha256', (string) config('app.key'))), 'stable APP_KEY');
    ciAssert(! $coordinator->run($storage, $stages), 'second first-install invocation refused');
    ciAssert(file_get_contents($marker) === 'staging-bootstrap-v1', 'retry did not replace marker');

    echo "disposable real Core migration + seeded-admin seal + completion marker: PASSED\n";
} catch (Throwable $failure) {
    // A known assertion uses only a fixed, source-reviewed label. Other
    // exceptions may embed SQL, connection strings or credentials, so report
    // only their PHP class and safe SQLSTATE (never the exception message).
    $message = $failure->getMessage();

    if (str_starts_with($message, 'synthetic first-install assertion failed: ')) {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        $type = get_debug_type($failure);
        $code = (string) $failure->getCode();
        $safeCode = preg_match('/\A[A-Za-z0-9]{1,6}\z/D', $code) === 1 ? $code : 'unavailable';

        fwrite(STDERR, "disposable first-install exception: {$type}; safe-code={$safeCode}\n");
    }

    exit(1);
}
