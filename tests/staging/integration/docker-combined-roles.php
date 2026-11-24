<?php

/**
 * Read-only independent observer for the hosted disposable three-role test.
 * Never run this probe on a persistent VM; it neither dispatches nor runs jobs.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

function combinedCheck(bool $ok, string $label): void
{
    if ($ok === false) {
        file_put_contents('php://stderr', 'Combined roles integration failed: ' . $label . PHP_EOL);
        exit(1);
    }
}

$root = '/var/www/html/rconfig';
$phase = getenv('RCONFIG_COMBINED_ROLE_PHASE');
combinedCheck(getenv('GITHUB_ACTIONS') === 'true' && getenv('IS_DOCKER') === 'true'
    && in_array($phase, ['baseline', 'live'], true)
    && posix_geteuid() === 1000 && posix_getegid() === 33
    && getcwd() === $root, 'hosted synthetic identity');
combinedCheck(is_file($root . '/.env') && is_link($root . '/.env') === false
    && fileowner($root . '/.env') === 0 && filegroup($root . '/.env') === 33
    && (fileperms($root . '/.env') & 0777) === 0640, 'protected dotenv');
combinedCheck(file_exists('/run/secrets/staging_admin_password') === false, 'no administrator secret in observer');
combinedCheck(file_get_contents($root . '/storage/.staging-bootstrap-complete') === 'staging-bootstrap-v1', 'sealed storage marker');

require $root . '/vendor/autoload.php';
Env::disablePutenv();
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    file_put_contents('php://stderr', 'Combined roles unexpected exception: ' . get_class($error) . PHP_EOL);
    exit(1);
});

combinedCheck($app->configurationIsCached() === false && $app->environmentFile() === '.env'
    && config('app.env') === 'production' && config('app.debug') === false
    && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === 'db'
    && config('database.connections.mysql.database') === 'rconfig_staging'
    && config('database.connections.mysql.username') === 'rconfig_staging'
    && config('database.redis.default.host') === 'redis'
    && config('database.redis.cache.host') === 'redis'
    && (string) config('database.redis.default.database') === '0'
    && (string) config('database.redis.cache.database') === '1'
    && config('cache.default') === 'redis'
    && config('cache.stores.redis.connection') === 'cache'
    && config('queue.default') === 'redis'
    && config('horizon.use') === 'default'
    && config('mail.default') === 'log', 'effective isolated configuration');
combinedCheck(config('health.ping.enabled') === false
    && config('health.ping.offline_mode') === true
    && config('health.ping.air_gapped') === true
    && config('security-checks.schedule_enabled') === false, 'offline health and security schedule');
foreach ([
    'external-backups.runtime_enabled', 'external-backups.queue_enabled',
    'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved',
    'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-backup-email.enabled',
    'rconfig-whatsapp.enabled', 'rconfig-whatsapp.policy_approved',
] as $flag) {
    combinedCheck(config($flag) === false, 'disabled feature ' . $flag);
}

$masters = app(MasterSupervisorRepository::class);
$supervisors = app(SupervisorRepository::class);
$heartbeatKey = 'health:checks:schedule:latestHeartbeatAt';

function combinedLedgerEmpty(): void
{
    combinedCheck(DB::table('external_backup_check_runs')->count() === 0, 'no external backup runs');
    combinedCheck(DB::table('custom_backup_notification_deliveries')->count() === 0, 'no custom deliveries');
    combinedCheck(DB::table('users')->count() === 1, 'only synthetic administrator');
    combinedCheck(DB::table('settings')->where('id', 1)->value('mail_driver') === 'log', 'stored mail remains log-only');
}

combinedLedgerEmpty();
if ($phase === 'baseline') {
    combinedCheck($masters->all() === [] && $supervisors->all() === [], 'no pre-existing Horizon processes');
    combinedCheck(Cache::get($heartbeatKey) === null, 'no pre-existing scheduler heartbeat');
    echo "STAGING_COMBINED_ROLE_BASELINE_OK (SYNTHETIC_ONLY)\n";

    exit(0);
}

$started = getenv('RCONFIG_COMBINED_ROLE_STARTED_AT');
combinedCheck(is_string($started) && ctype_digit($started), 'launch timestamp format');
$started = (int) $started;
combinedCheck($started > 0 && $started <= time() && time() - $started < 420, 'recent launch timestamp');

// Read the real Redis repositories and scheduled heartbeat; do not manufacture
// either marker. A five-minute cadence requires a bounded observation window.
$deadline = hrtime(true) + 360_000_000_000;
$expected = ['HorizonOne', 'HorizonTwo'];
do {
    $activeMasters = array_values($masters->all());
    $activeSupervisors = array_values($supervisors->all());
    $heartbeat = Cache::get($heartbeatKey);
    $master = count($activeMasters) === 1 ? $activeMasters[0] : null;
    $names = [];
    $workersReady = $master !== null && $master->status === 'running'
        && $master->environment === 'production' && (int) $master->pid > 0;

    foreach ($activeSupervisors as $supervisor) {
        $prefix = $master !== null ? $master->name . ':' : '';
        $matchesMaster = $prefix !== '' && str_starts_with($supervisor->name, $prefix)
            && $supervisor->master === $master->name;
        $names[] = $matchesMaster ? substr($supervisor->name, strlen($prefix)) : '';
        $workersReady = $workersReady && $matchesMaster && $supervisor->status === 'running'
            && (int) $supervisor->pid > 0 && is_array($supervisor->processes)
            && array_sum($supervisor->processes) > 0;
    }
    sort($names);
    $freshHeartbeat = is_int($heartbeat) && $heartbeat >= $started && $heartbeat <= time();

    if ($workersReady && $names === $expected && $freshHeartbeat) {
        combinedLedgerEmpty();
        echo "STAGING_COMBINED_HORIZON_WORKERS_OK (SYNTHETIC_ONLY)\n";
        echo "STAGING_COMBINED_SCHEDULER_HEARTBEAT_OK (SYNTHETIC_ONLY)\n";

        exit(0);
    }

    sleep(2);
} while (hrtime(true) < $deadline);

combinedCheck(false, 'timed out observing both native supervisors and a fresh scheduled heartbeat');
