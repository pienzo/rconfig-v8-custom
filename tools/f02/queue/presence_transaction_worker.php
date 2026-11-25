<?php

/**
 * Hosted CI only. Run the actual queued Local Presence job with test-only
 * transaction barriers. Never install these listeners in application code.
 */

use App\Models\LocalBackupPresenceRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

if ($argc !== 3 || getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_PRESENCE_HORIZON') !== '1'
    || ! in_array(getenv('F02_PRESENCE_LOAD'), ['3', '4'], true)
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'v8core_test'
    || getenv('REDIS_HOST') !== '127.0.0.1' || getenv('REDIS_DB') !== '5' || getenv('REDIS_CACHE_DB') !== '6') {
    exit(2);
}

$phase = $argv[1];
$runId = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fixture = getenv('F02_PRESENCE_FIXTURE');
if (! in_array($phase, ['precommit', 'postcommit', 'replay'], true) || $runId === false
    || ! is_string($fixture) || ! is_dir($fixture) || is_link($fixture)
    || realpath($fixture) !== $fixture || ! str_starts_with(basename($fixture), 'rconfig-f02-presence.')
    || (fileperms($fixture) & 0777) !== 0700) {
    exit(2);
}
$marker = $fixture . '/transaction-barrier';
if (file_exists($marker) || is_link($marker)) {
    exit(2);
}

$failClosed = static function (Throwable $exception): never {
    fwrite(STDERR, 'F02 presence transaction child failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
};
set_exception_handler($failClosed);
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler($failClosed);

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || DB::connection()->getDatabaseName() !== 'v8core_test'
    || config('database.redis.default.host') !== '127.0.0.1'
    || (string) config('database.redis.default.database') !== '5'
    || (string) config('database.redis.cache.database') !== '6'
    || config('queue.connections.redis_local_backup_presence.retry_after') !== 120
    || config('queue.connections.redis_local_backup_presence.connection') !== 'default'
    || config('local-backup-presence.runtime_enabled') !== true
    || config('local-backup-presence.queue_enabled') !== true
    || config('local-backup-presence.roots') !== ['synthetic' => $fixture]
    || config('mail.default') !== 'log' || config('rConfig.is_demo', false) !== false) {
    exit(2);
}
$run = LocalBackupPresenceRun::query()->findOrFail($runId);
if ($phase !== 'replay' && $run->status !== 'pending') {
    exit(2);
}

$barrier = static function () use ($marker, $phase): never {
    if (file_put_contents($marker, $phase . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Cannot signal transaction boundary.');
    }
    // Bounded orphan safety. The parent must kill this exact child.
    usleep(20000000);

    throw new RuntimeException('Transaction barrier was not interrupted.');
};

if ($phase === 'replay') {
    Event::listen(JobProcessing::class, static function (JobProcessing $event) use ($marker): void {
        $uuid = $event->job->payload()['uuid'] ?? null;
        if ($event->connectionName !== 'redis_local_backup_presence'
            || $event->job->getQueue() !== 'LocalBackupPresence'
            || $event->job->attempts() !== 2 || ! is_string($uuid)
            || file_put_contents($marker, $uuid . ':2' . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unexpected redelivery.');
        }
    });
} else {
    $observedWrite = false;
    DB::listen(static function (QueryExecuted $event) use ($phase, $barrier, &$observedWrite): void {
        if (! str_starts_with(strtolower($event->sql), 'update `local_backup_presence_runs`')
            || ! in_array('present', $event->bindings, true)) {
            return;
        }
        if ($event->connection->transactionLevel() < 1) {
            throw new RuntimeException('Observation update outside transaction.');
        }
        $observedWrite = true;
        if ($phase === 'precommit') {
            $barrier();
        }
    });
    Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use ($phase, $barrier, &$observedWrite): void {
        if ($phase === 'postcommit' && $observedWrite && $event->connection->transactionLevel() === 0) {
            $barrier();
        }
    });
}

exit($kernel->call('queue:work', [
    'connection' => 'redis_local_backup_presence',
    '--queue' => 'LocalBackupPresence',
    '--once' => true,
    '--sleep' => 0,
    '--timeout' => 30,
    '--tries' => 2,
    '--no-interaction' => true,
]));
