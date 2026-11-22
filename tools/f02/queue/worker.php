<?php

/**
 * Invoke Laravel's REAL Redis queue:work command in an isolated CI subprocess.
 *
 * Retry timeout is shortened ONLY in this process's in-memory config. No
 * production queue config file, Horizon supervisor or process is modified.
 */

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$queue = $argv[1] ?? '';

if ($argc !== 2 || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('F02_SYNTHETIC_REDIS_QUEUE') !== '1'
    || preg_match('/\Af02_ci_[a-f0-9]{16}\z/', $queue) !== 1) {
    fwrite(STDERR, "F02 Redis worker refused: isolated CI gate required.\n");
    exit(2);
}

try {
    $app = require $root . '/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    if (! $app->environment('testing')
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'v8core_test'
        || config('database.redis.default.host') !== '127.0.0.1') {
        throw new RuntimeException('Redis queue worker is restricted to ephemeral CI services.');
    }

    // No live application config change: short reservation for the synthetic
    // queue allows CI to prove at-least-once redelivery in bounded time.
    config()->set('queue.connections.redis.retry_after', 4);

    exit($kernel->call('queue:work', [
        'connection' => 'redis',
        '--queue' => $queue,
        '--once' => true,
        '--sleep' => 0,
        '--timeout' => 20,
        '--tries' => 2,
        '--no-interaction' => true,
    ]));
} catch (Throwable $exception) {
    fwrite(STDERR, 'F02 Redis worker failed: ' . get_class($exception) . "\n");
    exit(1);
}
