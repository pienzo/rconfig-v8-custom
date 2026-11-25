<?php

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapContainerGate;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;

// Separate synthetic-only operator entrypoint. Host snapshot, volume identity,
// exclusive writers and network boundary must be independently reviewed.
function bootstrapRequire(bool $ok, string $reason): void
{
    if (! $ok) {
        fwrite(STDERR, 'staging operator bootstrap refused: ' . $reason . PHP_EOL);
        exit(1);
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$root = '/var/www/html/rconfig';
bootstrapRequire(PHP_SAPI === 'cli' && ($argv ?? []) === [__FILE__, '--apply']
    && getcwd() === $root && posix_geteuid() === 1000 && posix_getegid() === 33
    && getenv('IS_DOCKER') === 'true'
    && getenv('RCONFIG_STAGING_BOOTSTRAP') === 'core-synthetic-v1'
    && getenv('RCONFIG_STAGING_BOOTSTRAP_APPROVAL') === 'operator-reviewed-v1', 'explicit synthetic identity and approval required');

try {
    // Exact original provisioner format, including the log-only transition.
    // Dynamic values are checked and masked in memory; never emit contents.
    $expected = <<<'ENV'
APP_NAME="rConfig V8 Core Synthetic Staging"
APP_ENV=production
APP_KEY=<protected>
APP_DEBUG=false
APP_URL=http://127.0.0.1:18080
APP_FORCE_HTTPS=false
APP_DIR_PATH=/var/www/html/rconfig
LOG_CHANNEL=daily
LOG_LEVEL=warning
MAIL_MAILER=log
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=rconfig_staging
DB_USERNAME=rconfig_staging
DB_PASSWORD=<protected>
BROADCAST_DRIVER=log
CACHE_DRIVER=redis
CACHE_PREFIX=rconfig_staging
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<protected>
REDIS_DB=0
REDIS_CACHE_DB=1
HORIZON_PREFIX=rconfig_staging_horizon:
HORIZON_MIN_PROCESS=1
HORIZON_MAX_PROCESS=1
HORIZON_DEFAULT_MAX_PROCESS=1
RCONFIG_F02_QUEUE_ENABLED=false
HEALTHCHECK_PING_ENABLED=false
HEALTHCHECK_OFFLINE_MODE=true
HEALTHCHECK_AIR_GAPPED=true
SECURITY_CHECK_SCHEDULE=false
RCONFIG_CONFIG_FILE_MODE=0440
RCONFIG_CONFIG_DIR_MODE=0750
ENV;
    $envPath = $root . '/.env';
    bootstrapRequire(is_file($envPath) && ! is_link($envPath)
        && fileowner($envPath) === 0 && filegroup($envPath) === 33
        && (fileperms($envPath) & 0777) === 0640 && filesize($envPath) <= 8192, 'protected dotenv');
    $dotenv = file_get_contents($envPath);
    bootstrapRequire(is_string($dotenv), 'dotenv unavailable');
    $masked = preg_replace([
        '/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m',
        '/^DB_PASSWORD=[0-9a-fA-F]{64}$/m',
        '/^REDIS_PASSWORD=[0-9a-fA-F]{64}$/m',
    ], ['APP_KEY=<protected>', 'DB_PASSWORD=<protected>', 'REDIS_PASSWORD=<protected>'], $dotenv, -1, $replacements);
    bootstrapRequire($replacements === 3 && $masked === $expected . "\n", 'unexpected synthetic dotenv layout');
    unset($dotenv, $masked);

    // Refuse ambient overrides before Laravel can load service providers.
    $keys = array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER));
    foreach ($keys as $key) {
        if (in_array($key, ['RCONFIG_STAGING_BOOTSTRAP', 'RCONFIG_STAGING_BOOTSTRAP_APPROVAL'], true)) {
            continue;
        }
        bootstrapRequire(preg_match('/^(APP_|DB_|REDIS_|RCONFIG_|MAIL_|CACHE_|SESSION_|QUEUE_|HORIZON_|HEALTHCHECK_|SECURITY_CHECK_|BROADCAST_|LARAVEL_)/', (string) $key) !== 1, 'ambient configuration override');
    }
    $cache = $root . '/bootstrap/cache/config.php';
    bootstrapRequire(! file_exists($cache) && ! is_link($cache), 'cached configuration forbidden');

    require $root . '/vendor/autoload.php';
    Env::disablePutenv();
    $app = require $root . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    bootstrapRequire(! $app->configurationIsCached() && $app->environmentFile() === '.env', 'protected dotenv selection');

    foreach ([
        'external-backups.runtime_enabled', 'external-backups.queue_enabled',
        'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved',
        'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
        'rconfig-telegram.enabled', 'rconfig-backup-email.enabled',
        'rconfig-whatsapp.enabled', 'rconfig-whatsapp.policy_approved',
    ] as $flag) {
        bootstrapRequire(config($flag) === false, 'custom feature must remain disabled');
    }
    bootstrapRequire(config('external-backups.intake_root') === '', 'external intake must remain disabled');

    $gate = new StagingBootstrapContainerGate;
    $state = new StagingBootstrapStateService;
    $stages = new LaravelStagingBootstrapStages($state, new SealDefaultAdminService, $gate);
    bootstrapRequire($gate->permitted() && $gate->postflightReady() && $state->isFresh(), 'strict fresh-state gate');
    bootstrapRequire((new StagingBootstrapCoordinator)->run(storage_path(), $stages), 'one-shot coordinator failed; preserve attempt and recover');
    echo "STAGING_OPERATOR_BOOTSTRAP_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable) {
    fwrite(STDERR, "staging operator bootstrap failed; preserve state and review recovery\n");
    exit(1);
}
