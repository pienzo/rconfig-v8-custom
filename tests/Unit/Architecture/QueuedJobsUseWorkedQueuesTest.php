<?php

use App\Jobs\CheckForUpdateJob;
use App\Jobs\LocalBackupPresenceObserveJob;
use App\Services\ExternalBackups\LocalBackupPresenceQueueConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

/**
 * Architecture guard (RCO-2244). A queued job with no queue of its own lands on "default", which no
 * Horizon supervisor in config/horizon.php works, so it never runs. Every job sets its queue in its
 * constructor ($this->onQueue('...')) or $queue property, and that queue must be worked by Horizon.
 */

/**
 * Queues worked by a supervisor in every Horizon environment.
 *
 * @return list<string>
 */
function horizonWorkedQueues(): array
{
    $perEnvironment = [];

    foreach ((array) config('horizon.environments', []) as $supervisors) {
        $queues = [];

        foreach ((array) $supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[] = $queue;
            }
        }

        $perEnvironment[] = array_unique($queues);
    }

    return $perEnvironment === [] ? [] : array_values(array_intersect(...$perEnvironment));
}

/**
 * Every concrete class under app/Jobs that can be queued.
 *
 * @return list<class-string>
 */
function queueableJobClasses(): array
{
    $classes = [];

    foreach (File::allFiles(app_path('Jobs')) as $file) {
        $class = 'App\\Jobs\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $queueable = $reflection->implementsInterface(ShouldQueue::class) || in_array(Queueable::class, class_uses_recursive($class), true);

        if ($queueable && ! $reflection->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * The queue a job sets for itself, read from its $queue default or its constructor.
 */
function pinnedQueue(ReflectionClass $reflection): ?string
{
    $default = $reflection->getDefaultProperties()['queue'] ?? null;

    if (is_string($default) && $default !== '') {
        return $default;
    }

    $constructor = $reflection->getConstructor();

    if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
        return null;
    }

    $lines = array_slice(file($constructor->getFileName()), $constructor->getStartLine() - 1, $constructor->getEndLine() - $constructor->getStartLine() + 1);
    $source = implode('', $lines);

    if (preg_match('/onQueue\(\s*\(string\)\s*config\(\s*[\'"]([^\'"]+)[\'"]/', $source, $match)) {
        return (string) config($match[1]);
    }

    if (preg_match('/onQueue\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $match) || preg_match('/\$this->queue\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match)) {
        return $match[1];
    }

    return null;
}

/**
 * A disabled-by-default optional F02 queue is intentionally absent from
 * active Horizon supervisors. It is valid only when its isolated connection
 * and gated supervisor declaration exist. Native queues still must be worked.
 */
function isDormantExternalBackupQueue(?string $queue): bool
{
    if ($queue !== 'ExternalBackupChecks'
        || config('external-backups.queue_enabled', false) !== false
        || config('queue.connections.redis_external_backups.queue') !== 'ExternalBackupChecks'
        || config('queue.connections.redis_external_backups.driver') !== 'redis') {
        return false;
    }

    $horizonSource = File::get(config_path('horizon.php'));

    return str_contains($horizonSource, "'queue' => ['ExternalBackupChecks']")
        && str_contains($horizonSource, 'RCONFIG_F02_QUEUE_ENABLED');
}

/**
 * Load the actual Horizon config with the notification gate enabled, then
 * restore the process environment. No worker or Redis connection is started.
 */
function enabledBackupNotificationSupervisors(): array
{
    $key = 'RCONFIG_CUSTOM_BACKUP_NOTIFICATIONS_ENABLED';
    $previousEnv = $_ENV[$key] ?? null;
    $previousServer = $_SERVER[$key] ?? null;
    $_ENV[$key] = 'true';
    $_SERVER[$key] = 'true';

    try {
        $settings = require config_path('horizon.php');

        return $settings['environments'];
    } finally {
        if ($previousEnv === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $previousServer;
        }
    }
}

function isDormantBackupNotificationQueue(?string $queue): bool
{
    if ($queue !== 'BackupNotifications'
        || config('custom-backup-notifications.enabled', false) !== false
        || config('queue.connections.redis_backup_notifications.queue') !== $queue
        || config('queue.connections.redis_backup_notifications.driver') !== 'redis'
        || config('queue.connections.redis_backup_notifications.after_commit') !== true) {
        return false;
    }

    $environments = enabledBackupNotificationSupervisors();
    if ($environments === [] || array_keys($environments) !== array_keys(config('horizon.environments'))) {
        return false;
    }

    foreach ($environments as $supervisors) {
        $worker = $supervisors['HorizonBackupNotifications'] ?? [];
        if (($worker['queue'] ?? []) !== [$queue]
            || ($worker['connection'] ?? null) !== 'redis_backup_notifications'
            || ($worker['minProcesses'] ?? 0) < 1
            || ($worker['tries'] ?? null) !== 1
            || ($worker['timeout'] ?? 0) < 1
            || $worker['timeout'] >= config('queue.connections.redis_backup_notifications.retry_after')) {
            return false;
        }
    }

    return true;
}

/**
 * A dormant queue is allowed only with a real gated supervisor in every
 * environment and a valid timeout/connection contract.
 */
function isDormantLocalBackupPresenceQueue(?string $queue): bool
{
    if ($queue !== 'LocalBackupPresence'
        || config('local-backup-presence.runtime_enabled') !== false
        || config('local-backup-presence.queue_enabled') !== false
        || config('queue.connections.redis_local_backup_presence.queue') !== $queue
        || config('queue.connections.redis_local_backup_presence.driver') !== 'redis'
        || config('queue.connections.redis_local_backup_presence.after_commit') !== true) {
        return false;
    }

    $source = File::get(config_path('horizon.php'));
    if (! str_contains($source, 'LocalBackupPresenceQueueConfiguration::withSupervisors(')
        || ! str_contains($source, "require __DIR__ . '/local-backup-presence.php'")) {
        return false;
    }

    $base = config('horizon.environments');
    $enabled = LocalBackupPresenceQueueConfiguration::withSupervisors($base, [
        'runtime_enabled' => true,
        'queue_enabled' => true,
    ]);

    if ($enabled === [] || array_keys($base) !== array_keys($enabled)) {
        return false;
    }

    foreach ($enabled as $supervisors) {
        $worker = $supervisors['HorizonLocalBackupPresence'] ?? [];
        if (($worker['queue'] ?? []) !== [$queue]
            || ($worker['connection'] ?? null) !== 'redis_local_backup_presence'
            || ($worker['minProcesses'] ?? null) !== 1
            || ($worker['maxProcesses'] ?? null) !== 1
            || ($worker['tries'] ?? null) !== 2
            || ($worker['timeout'] ?? 0) <= (new LocalBackupPresenceObserveJob(1, 1))->timeout
            || $worker['timeout'] >= config('queue.connections.redis_local_backup_presence.retry_after')) {
            return false;
        }
    }

    return true;
}

test('local presence supervisor requires both explicit config gates and valid queue limits', function () {
    $actual = require config_path('horizon.php');
    $base = $actual['environments'];
    expect($base)->toBe(config('horizon.environments'));
    expect(horizonWorkedQueues())->not->toContain('LocalBackupPresence');
    expect(isDormantLocalBackupPresenceQueue('LocalBackupPresence'))->toBeTrue();
    expect(isDormantLocalBackupPresenceQueue('default'))->toBeFalse();

    foreach ([[false, false], [true, false], [false, true], ['true', true]] as [$runtime, $queue]) {
        expect(LocalBackupPresenceQueueConfiguration::withSupervisors($base, [
            'runtime_enabled' => $runtime,
            'queue_enabled' => $queue,
        ]))->toBe($base);
    }

    $enabled = LocalBackupPresenceQueueConfiguration::withSupervisors($base, [
        'runtime_enabled' => true,
        'queue_enabled' => true,
    ]);
    foreach ($enabled as $environment => $supervisors) {
        expect($supervisors['HorizonLocalBackupPresence']['queue'])->toBe(['LocalBackupPresence']);
        unset($supervisors['HorizonLocalBackupPresence']);
        expect($supervisors)->toBe($base[$environment]);
    }

    config()->set('queue.connections.redis_local_backup_presence.retry_after', 45);
    expect(isDormantLocalBackupPresenceQueue('LocalBackupPresence'))->toBeFalse();
});

test('optional notification supervisor is absent by default and works every environment when enabled', function () {
    expect(config('custom-backup-notifications.enabled'))->toBeFalse();
    expect(horizonWorkedQueues())->not->toContain('BackupNotifications');
    expect(isDormantBackupNotificationQueue('BackupNotifications'))->toBeTrue();
    expect(isDormantBackupNotificationQueue('default'))->toBeFalse();

    config()->set('queue.connections.redis_backup_notifications.retry_after', 60);
    expect(isDormantBackupNotificationQueue('BackupNotifications'))->toBeFalse();
});

test('Horizon works rConfigDefault in every environment', function () {
    expect(horizonWorkedQueues())->toContain('rConfigDefault');
});

test('optional F02 supervisor remains gated while Core queues stay worked', function () {
    expect(config('external-backups.queue_enabled'))->toBeFalse();
    expect(horizonWorkedQueues())->not->toContain('ExternalBackupChecks');
    expect(isDormantExternalBackupQueue('ExternalBackupChecks'))->toBeTrue();
    expect(config('queue.connections.redis_external_backups.after_commit'))->toBeTrue();
});

test('job discovery finds the queued jobs', function () {
    expect(queueableJobClasses())->toContain(CheckForUpdateJob::class)
        ->and(count(queueableJobClasses()))->toBeGreaterThanOrEqual(10);
});

test('every queued job sets a queue that Horizon works', function () {
    $worked = horizonWorkedQueues();
    $offenders = [];

    foreach (queueableJobClasses() as $class) {
        $queue = pinnedQueue(new ReflectionClass($class));

        if ($queue === null || (! in_array($queue, $worked, true) && ! isDormantExternalBackupQueue($queue) && ! isDormantBackupNotificationQueue($queue) && ! isDormantLocalBackupPresenceQueue($queue))) {
            $offenders[] = $class . ' -> ' . ($queue ?? 'no queue set (lands on "default")');
        }
    }

    expect($offenders)->toBeEmpty(
        "These jobs would never run, because no Horizon supervisor works their queue:\n"
        . implode("\n", $offenders)
        . "\nSet one in the constructor, e.g. \$this->onQueue('rConfigDefault'), using a queue listed in config/horizon.php."
    );
});

test('every onQueue() call in app/ names a queue that Horizon works', function () {
    $worked = horizonWorkedQueues();
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('#^\s*(//|\*|/\*|\#)#', $line)) {
                continue;
            }

            if (preg_match_all('/onQueue\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches)) {
                foreach ($matches[1] as $queue) {
                    if (! in_array($queue, $worked, true) && ! isDormantExternalBackupQueue($queue) && ! isDormantBackupNotificationQueue($queue) && ! isDormantLocalBackupPresenceQueue($queue)) {
                        $offenders[] = $file->getRelativePathname() . ':' . ($number + 1) . ' -> ' . $queue;
                    }
                }
            }
        }
    }

    expect($offenders)->toBeEmpty("These dispatches name a queue no Horizon supervisor works:\n" . implode("\n", $offenders));
});
