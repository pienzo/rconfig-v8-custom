<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('F02_SYNTHETIC_PRESENCE_ADMISSION') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test') {
    exit(2);
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || DB::connection()->getDatabaseName() !== 'v8core_test'
    || config('mail.default') !== 'log' || config('rConfig.is_demo', false) !== false
    || config('local-backup-presence.roots') !== []) {
    exit(2);
}
foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled', 'catchup_enabled'] as $flag) {
    if (config('local-backup-presence.' . $flag) !== false) {
        exit(2);
    }
}
config([
    'app.timezone' => 'UTC',
    'local-backup-presence.runtime_enabled' => true,
    'local-backup-presence.queue_enabled' => true,
    'local-backup-presence.scheduler_enabled' => true,
    'local-backup-presence.dispatch_enabled' => true,
    'local-backup-presence.max_rules_per_tick' => 100,
    'local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only'],
]);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 06:00:15', 'UTC'));
Bus::fake();

function admissionCheck(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('F02 admission probe failed: ' . $label);
    }
}

function admissionConnectionId(): int
{
    return (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
}

function admissionMark(string $path, string $value): void
{
    // Publish complete marker atomically, rather than exposing partial JSON.
    admissionCheck(file_put_contents($path . '.tmp', $value, LOCK_EX) !== false
        && rename($path . '.tmp', $path), 'barrier marker write');
}
