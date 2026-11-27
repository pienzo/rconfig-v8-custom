<?php

/** Hosted disposable upgrade rehearsal only; never run on an operator VM. */

use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

function upgradeCheck(bool $ok, string $label): void
{
    if (! $ok) {
        fwrite(STDERR, 'Synthetic upgrade refused: ' . $label . PHP_EOL);
        exit(1);
    }
}

$phase = getenv('RCONFIG_F02_UPGRADE_PHASE');
$root = '/var/www/html/rconfig';
upgradeCheck(getenv('GITHUB_ACTIONS') === 'true' && getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
    && getenv('IS_DOCKER') === 'true' && posix_geteuid() === 1000 && posix_getegid() === 33
    && getcwd() === $root && in_array($phase, ['seed', 'apply', 'verify', 'noop'], true), 'hosted synthetic identity');
upgradeCheck(is_file($root . '/.env') && ! is_link($root . '/.env')
    && fileowner($root . '/.env') === 0 && filegroup($root . '/.env') === 33
    && (fileperms($root . '/.env') & 0777) === 0640
    && ! file_exists('/run/secrets/staging_admin_password')
    && ! file_exists('/mnt/f02-synthetic'), 'protected mounts and no administrator secret');
$marker = $root . '/storage/.staging-bootstrap-complete';
upgradeCheck(is_file($marker) && ! is_link($marker)
    && file_get_contents($marker) === 'staging-bootstrap-v1', 'original bootstrap marker');

require $root . '/vendor/autoload.php';
Env::disablePutenv();
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Synthetic upgrade unexpected exception: ' . get_class($error) . PHP_EOL);
    exit(1);
});
upgradeCheck(! $app->configurationIsCached() && $app->environmentFile() === '.env'
    && config('app.env') === 'production' && config('app.debug') === false
    && config('database.default') === 'mysql' && config('database.connections.mysql.host') === 'db'
    && config('database.connections.mysql.database') === 'rconfig_staging'
    && config('database.connections.mysql.username') === 'rconfig_staging'
    && config('database.redis.default.host') === 'redis' && config('database.redis.cache.host') === 'redis'
    && (string) config('database.redis.default.database') === '0'
    && (string) config('database.redis.cache.database') === '1'
    && config('cache.default') === 'redis' && config('mail.default') === 'log', 'isolated configuration');
foreach ([
    'external-backups.runtime_enabled', 'external-backups.queue_enabled',
    'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved',
    'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-backup-email.enabled',
    'rconfig-whatsapp.enabled', 'rconfig-whatsapp.policy_approved',
] as $flag) {
    upgradeCheck(config($flag) === false, 'existing feature disabled ' . $flag);
}
if ($phase !== 'seed') {
    foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled',
        'catchup_enabled', 'activation_api_enabled', 'manual_probe_enabled', 'manual_dispatch_enabled', 'manual_api_enabled'] as $flag) {
        upgradeCheck(config('local-backup-presence.' . $flag) === false, 'new feature disabled ' . $flag);
    }
    upgradeCheck(config('local-backup-presence.roots') === [], 'no backup mappings');
}

$expected = [
    '2026_11_25_013000_create_local_backup_presence_schema',
    '2026_11_25_020000_add_criteria_snapshot_to_local_backup_presence_runs',
    '2026_11_25_030000_add_observation_baseline_to_local_backup_presence_runs',
    '2026_11_25_040000_add_dispatch_cooldown_to_local_backup_presence_runs',
    '2026_11_25_050000_add_scheduler_checkpoint_to_local_backup_presence_rules',
    '2026_11_25_060000_create_local_backup_presence_activation_lock',
    '2026_11_26_010000_create_local_backup_presence_manual_sessions',
    '2026_11_26_020000_add_dispatch_cooldown_to_local_backup_presence_manual_sessions',
];
$tables = ['local_backup_presence_rules', 'local_backup_presence_runs',
    'local_backup_presence_activation_lock', 'local_backup_presence_manual_sessions'];
$files = [];
foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
    $files[basename($file, '.php')] = hash_file('sha256', $file);
}
ksort($files);
$ledger = static fn (): array => DB::table('migrations')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
$digest = static fn (mixed $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
$coreState = static function () use ($root, $marker, $digest): array {
    $rows = [];
    foreach (['users', 'settings'] as $table) {
        $rows[$table] = $digest(DB::table($table)->orderBy('id')->get()->all());
    }

    return [
        'core' => $rows,
        'dotenv' => hash_file('sha256', $root . '/.env'),
        'marker' => hash_file('sha256', $marker),
        'marker_mode' => fileperms($marker) & 0777,
        'marker_uid' => fileowner($marker),
        'marker_gid' => filegroup($marker),
    ];
};
$emptyOtherLedgers = static function (): void {
    foreach (['external_backup_check_runs', 'external_backup_file_receipts',
        'custom_backup_notification_deliveries', 'failed_jobs'] as $table) {
        upgradeCheck(DB::table($table)->count() === 0, 'unrelated ledger remains empty ' . $table);
    }
};
$statePath = storage_path('.synthetic-f02-upgrade-state');
$redisKey = 'synthetic-f02-upgrade-v1';
$emptyOtherLedgers();
upgradeCheck(DB::table('users')->count() === 1 && DB::table('settings')->count() === 1
    && DB::table('settings')->where('id', 1)->value('mail_driver') === 'log'
    && DB::table('users')->where('id', 1)->value('email') === 'staging-admin@example.invalid', 'sealed baseline admin and mail');

if ($phase === 'seed') {
    upgradeCheck(count($files) === 82 && count($ledger()) === 82
        && (new StagingBootstrapStateService)->hasCompleteMigrationLedger(), 'exact historical source ledger');
    foreach ($tables as $table) {
        upgradeCheck(! Schema::hasTable($table), 'new table absent from baseline');
    }
    upgradeCheck(! file_exists($statePath) && ! is_link($statePath)
        && Redis::connection('default')->get($redisKey) === null
        && Cache::store('redis')->get($redisKey) === null, 'fresh owned fixture');
    $value = bin2hex(random_bytes(32));
    upgradeCheck((string) Redis::connection('default')->setex($redisKey, 3600, $value) === 'OK'
        && Cache::store('redis')->put($redisKey, $value, 3600), 'seed Redis DB0 and DB1');
    $state = [
        'files' => $files,
        'ledger' => $ledger(),
        'baseline' => $coreState(),
        'ciphertext' => Crypt::encryptString($value),
        'value_digest' => hash('sha256', $value),
    ];
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    umask(0077);
    $handle = fopen($statePath, 'x');
    upgradeCheck(is_resource($handle), 'exclusive state file');
    upgradeCheck(fwrite($handle, $json) === strlen($json) && fclose($handle), 'persist private synthetic state');
    echo "STAGING_F02_UPGRADE_BASELINE_OK (SYNTHETIC_ONLY)\n";

    exit(0);
}

upgradeCheck(is_file($statePath) && ! is_link($statePath) && fileowner($statePath) === 1000
    && (fileperms($statePath) & 0777) === 0600, 'owned protected state file');
$saved = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
upgradeCheck(count($files) === 90 && count($saved['files']) === 82
    && array_intersect_key($files, $saved['files']) === $saved['files']
    && array_keys(array_diff_key($files, $saved['files'])) === $expected, 'exact additive migration file allowlist');
$assertPreserved = static function () use ($saved, $coreState, $redisKey, $emptyOtherLedgers): void {
    upgradeCheck($coreState() === $saved['baseline'], 'admin, settings, dotenv and marker preserved');
    $value = Crypt::decryptString($saved['ciphertext']);
    upgradeCheck(hash_equals($saved['value_digest'], hash('sha256', $value))
        && Redis::connection('default')->get($redisKey) === $value
        && Cache::store('redis')->get($redisKey) === $value, 'APP_KEY and both Redis fixtures preserved');
    $emptyOtherLedgers();
};
$assertUpgraded = static function () use ($ledger, $saved, $expected, $tables, $assertPreserved): void {
    $rows = $ledger();
    upgradeCheck(count($rows) === 90 && array_slice($rows, 0, 82) === $saved['ledger']
        && array_column(array_slice($rows, 82), 'migration') === $expected
        && (new StagingBootstrapStateService)->hasCompleteMigrationLedger(), 'original ledger and exact eight additions');
    foreach ($tables as $table) {
        upgradeCheck(Schema::hasTable($table), 'new table exists ' . $table);
        if ($table !== 'local_backup_presence_activation_lock') {
            upgradeCheck(DB::table($table)->count() === 0, 'new ledger empty ' . $table);
        }
    }
    upgradeCheck(DB::table('local_backup_presence_activation_lock')->count() === 1
        && (int) DB::table('local_backup_presence_activation_lock')->value('id') === 1, 'singleton activation lock');
    upgradeCheck(Schema::hasColumns('local_backup_presence_runs', ['criteria_snapshot', 'directory_identity', 'stability_baseline', 'dispatch_not_before_utc'])
        && Schema::hasColumns('local_backup_presence_rules', ['scheduler_checked_minute_utc', 'scheduler_revision'])
        && Schema::hasColumn('local_backup_presence_manual_sessions', 'dispatch_not_before'), 'additive schema columns');
    $assertPreserved();
};
$assertPreserved();
if ($phase === 'apply') {
    upgradeCheck($ledger() === $saved['ledger'], 'pre-upgrade exact baseline ledger');
    foreach ($tables as $table) {
        upgradeCheck(! Schema::hasTable($table), 'no partial migration state');
    }
    upgradeCheck(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'actual non-fresh additive migration');
    $assertUpgraded();
    echo "STAGING_F02_UPGRADE_MIGRATED_OK (SYNTHETIC_ONLY)\n";
} elseif ($phase === 'verify') {
    $assertUpgraded();
    echo "STAGING_F02_UPGRADE_PRESERVED_OK (SYNTHETIC_ONLY)\n";
} else {
    $assertUpgraded();
    $before = $ledger();
    upgradeCheck(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0
        && $ledger() === $before, 'second migration is a no-op');
    $assertUpgraded();
    upgradeCheck(unlink($statePath), 'owned private inspector cleanup');
    echo "STAGING_F02_UPGRADE_NOOP_OK (SYNTHETIC_ONLY)\n";
}
