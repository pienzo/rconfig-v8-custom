<?php

declare(strict_types=1);

/** Hosted-only fault probe; never installed in the operator image. */

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapContainerGate;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapStages;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;

function ciAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('synthetic first-install assertion failed: ' . $label);
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');

try {
    ciAssert(PHP_SAPI === 'cli' && getenv('GITHUB_ACTIONS') === 'true'
        && getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
        && getenv('RCONFIG_STAGING_CI_INTEGRATION') === 'disposable-v1'
        && getenv('RCONFIG_STAGING_BOOTSTRAP') === 'core-synthetic-v1'
        && getenv('IS_DOCKER') === 'true'
        && posix_geteuid() === 1000 && posix_getegid() === 33, 'hosted synthetic identity');
    ciAssert($argc === 2 && in_array($argv[1], ['crash', 'inspect-crash'], true), 'scenario');
    $root = '/var/www/html/rconfig';
    ciAssert(getcwd() === $root, 'exact application directory');
    foreach (array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER)) as $key) {
        if (in_array($key, ['RCONFIG_STAGING_BOOTSTRAP', 'RCONFIG_STAGING_CI_INTEGRATION'], true)) {
            continue;
        }
        ciAssert(preg_match('/^(APP_|DB_|REDIS_|RCONFIG_|MAIL_|CACHE_|SESSION_|QUEUE_|HORIZON_|HEALTHCHECK_|SECURITY_CHECK_|BROADCAST_|LARAVEL_)/', (string) $key) !== 1, 'ambient override refused');
    }
    ciAssert(! file_exists($root . '/bootstrap/cache/config.php')
        && ! is_link($root . '/bootstrap/cache/config.php'), 'no cached configuration');

    require $root . '/vendor/autoload.php';
    Env::disablePutenv();
    final class CiKillAfterMigratedStages implements StagingBootstrapStages
    {
        public function __construct(private readonly StagingBootstrapStages $real) {}

        public function fresh(): bool
        {
            if (! $this->real->fresh()) {
                throw new RuntimeException('synthetic CI crash gate: freshness refused');
            }

            return true;
        }

        public function migrate(): bool
        {
            if (! $this->real->migrate()) {
                throw new RuntimeException('synthetic CI crash gate: migration refused');
            }

            return true;
        }

        public function migrated(): bool
        {
            if (! $this->real->migrated()) {
                throw new RuntimeException('synthetic CI crash gate: Core seed inspection refused');
            }

            fwrite(STDERR, "disposable SIGKILL injection reached after real migrations\n");
            $sent = posix_kill(posix_getpid(), SIGKILL);

            if (! $sent) {
                throw new RuntimeException('synthetic CI crash gate: SIGKILL syscall refused errno=' . (int) posix_get_last_error());
            }

            // Delivery should terminate this process before this is reached.
            usleep(500000);
            throw new RuntimeException('synthetic CI crash gate: SIGKILL returned without termination');
        }

        public function seal(): bool
        {
            throw new RuntimeException('synthetic CI crash gate: unexpected admin seal');
        }

        public function sealed(): bool
        {
            throw new RuntimeException('synthetic CI crash gate: unexpected sealed state');
        }

        public function postflight(): bool
        {
            throw new RuntimeException('synthetic CI crash gate: unexpected postflight');
        }
    }

    if ($argv[1] === 'crash') {
        // Fork before framework bootstrap; PID 1 is not the fault target.
        $child = pcntl_fork();
        ciAssert($child !== -1, 'fork');
        if ($child > 0) {
            ciAssert(pcntl_waitpid($child, $status) === $child
                && pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'child SIGKILL');
            echo "STAGING_STRICT_POSTMIGRATION_SIGKILL_OK (SYNTHETIC_ONLY)\n";
            exit(137);
        }
    }

    $app = require $root . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    ciAssert(! $app->configurationIsCached() && $app->environmentFile() === '.env', 'dotenv selection');
    $gate = new StagingBootstrapContainerGate;
    $state = new StagingBootstrapStateService;
    $sealer = new SealDefaultAdminService;
    $stages = new LaravelStagingBootstrapStages($state, $sealer, $gate);
    $coordinator = new StagingBootstrapCoordinator;
    $storage = storage_path();
    $attempt = $storage . '/.staging-bootstrap-attempt';
    $marker = $storage . '/.staging-bootstrap-complete';
    ciAssert($gate->permitted() && $gate->postflightReady(), 'actual strict container gate');
    foreach (['local-backup-presence.runtime_enabled', 'local-backup-presence.queue_enabled',
        'external-backups.runtime_enabled', 'external-backups.queue_enabled',
        'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
        'rconfig-telegram.enabled', 'rconfig-backup-email.enabled', 'rconfig-whatsapp.enabled'] as $flag) {
        ciAssert(config($flag) === false, 'custom feature disabled');
    }

    if ($argv[1] === 'crash') {
        ciAssert($state->isFresh() && ! file_exists($attempt) && ! is_link($attempt), 'fresh disposable state');
        $coordinator->run($storage, new CiKillAfterMigratedStages($stages));
        throw new RuntimeException('synthetic CI crash gate: coordinator returned before SIGKILL');
    }

    ciAssert(is_dir($attempt) && ! is_link($attempt) && (fileperms($attempt) & 0777) === 0700, 'retained private attempt');
    ciAssert(! file_exists($marker) && ! is_link($marker)
        && ! file_exists($attempt . '/completion-marker.ready'), 'no completion marker');
    ciAssert(! $state->isFresh() && $state->hasCompleteMigrationLedger() && $sealer->ready(), 'complete ledger and unsealed seeds');
    ciAssert(! $coordinator->run($storage, $stages), 'coordinator retry refused');
    ciAssert(! file_exists($marker) && ! is_link($marker)
        && $state->hasCompleteMigrationLedger() && $sealer->ready(), 'retry leaves partial state unchanged');
    echo "STAGING_STRICT_POSTMIGRATION_INSPECT_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $failure) {
    $message = $failure->getMessage();
    fwrite(STDERR, (str_starts_with($message, 'synthetic first-install assertion failed: ')
        || str_starts_with($message, 'synthetic CI crash gate: ') ? $message : get_debug_type($failure)) . PHP_EOL);
    exit(1);
}
