<?php

/**
 * Hosted CI only. Exercise the actual delivery claim with independent MySQL
 * connections and a local synthetic provider effect. No provider network call.
 */

use App\Services\Notifications\BackupNotificationDeliveryAttempt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('F03_SYNTHETIC_DELIVERY_CLAIM') !== '1') {
    fwrite(STDERR, "F03 delivery claim probe refused: hosted test-only gate required.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || DB::connection()->getDatabaseName() !== 'v8core_test') {
    fwrite(STDERR, "F03 probe requires ephemeral local MySQL.\n");
    exit(2);
}

config()->set('custom-backup-notifications.enabled', true);
$service = app(BackupNotificationDeliveryAttempt::class);
$table = 'custom_backup_notification_deliveries';

if (($argv[1] ?? '') === 'child') {
    $id = (int) ($argv[2] ?? 0);
    $folder = $argv[3] ?? '';
    $label = $argv[4] ?? '';
    $mode = $argv[5] ?? '';
    $identity = $argv[6] ?? '';
    if ($id < 1 || ! in_array($label, ['a', 'b', 'crash', 'retry'], true)
        || ! in_array($mode, ['normal', 'crash'], true)
        || preg_match('/\A[a-f0-9]{32}\z/', $identity) !== 1
        || ! preg_match('/\Arconfig_f03_[a-f0-9]{16}\z/', basename($folder))
        || dirname($folder) !== sys_get_temp_dir() || ! is_dir($folder) || is_link($folder)
        || ! DB::table($table)->where('id', $id)->where('source_type', 'ci-f03-claim')
            ->where('destination_key', $identity)->exists()) {
        exit(2);
    }

    if (! touch($folder . '/' . $label . '.ready')) {
        exit(3);
    }
    $deadline = microtime(true) + 15;
    while (! is_file($folder . '/release')) {
        if (microtime(true) >= $deadline) {
            exit(3);
        }
        usleep(10000);
    }

    $result = $service->attempt($id, static function () use ($folder, $label, $mode): void {
        // One append represents a provider side effect; no token, HTTP or SMTP.
        if (file_put_contents($folder . '/effects', $label . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Synthetic effect failed.');
        }
        if ($mode === 'crash') {
            if (! touch($folder . '/claimed')) {
                throw new RuntimeException('Synthetic marker failed.');
            }
            sleep(30);
        }
    });

    echo $result . "\n";
    exit(0);
}

if ($argc !== 1) {
    exit(2);
}

$folder = sys_get_temp_dir() . '/rconfig_f03_' . bin2hex(random_bytes(8));
$createdFolder = false;
$ids = [];
$children = [];
$failed = false;

try {
    if (! mkdir($folder, 0700)) {
        throw new RuntimeException('Synthetic directory creation failed.');
    }
    $createdFolder = true;
    $fixture = static function () use (&$ids, $table): array {
        $identity = bin2hex(random_bytes(16));
        $id = DB::table($table)->insertGetId([
            'source_type' => 'ci-f03-claim',
            'source_id' => 1,
            'event_type' => 'backup.success',
            'channel' => 'telegram',
            'destination_key' => $identity,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ids[] = $id;

        return [(int) $id, $identity];
    };
    $start = static function (int $id, string $identity, string $label, string $mode) use ($root, $folder, &$children): Process {
        $child = new Process([PHP_BINARY, __FILE__, 'child', (string) $id, $folder, $label, $mode, $identity], $root, null, null, 40);
        $children[] = $child;
        $child->start();

        return $child;
    };
    $awaitMarker = static function (string $marker, Process $child) use ($folder): void {
        $limit = microtime(true) + 15;
        while (! is_file($folder . '/' . $marker)) {
            if (! $child->isRunning() || microtime(true) >= $limit) {
                throw new RuntimeException('Synthetic worker failed to reach marker.');
            }
            usleep(10000);
        }
    };
    $finish = static function (Process $child): string {
        $child->wait();
        if (! $child->isSuccessful()) {
            throw new RuntimeException('Synthetic worker failed.');
        }

        return trim($child->getOutput());
    };

    [$id, $identity] = $fixture();
    $a = $start($id, $identity, 'a', 'normal');
    $b = $start($id, $identity, 'b', 'normal');
    $awaitMarker('a.ready', $a);
    $awaitMarker('b.ready', $b);
    if (! touch($folder . '/release')) {
        throw new RuntimeException('Synthetic barrier release failed.');
    }
    $results = [$finish($a), $finish($b)];
    sort($results);
    $effects = file($folder . '/effects', FILE_IGNORE_NEW_LINES);
    if ($results !== ['accepted', 'skipped'] || $effects === false || count($effects) !== 1
        || DB::table($table)->where('id', $id)->value('status') !== 'accepted') {
        throw new RuntimeException('Concurrent delivery claim was not exclusive.');
    }

    [$crashId, $crashIdentity] = $fixture();
    $crash = $start($crashId, $crashIdentity, 'crash', 'crash');
    $awaitMarker('claimed', $crash);
    if (DB::table($table)->where('id', $crashId)->value('status') !== 'sending' || ! $crash->isRunning()) {
        throw new RuntimeException('Claim was not committed before simulated effect.');
    }
    $crash->signal(9);
    $crash->wait();
    if ($crash->isSuccessful()) {
        throw new RuntimeException('Synthetic worker was not interrupted.');
    }

    $retry = $start($crashId, $crashIdentity, 'retry', 'normal');
    $result = $finish($retry);
    $effects = file($folder . '/effects', FILE_IGNORE_NEW_LINES);
    if ($result !== 'skipped' || $effects === false || count($effects) !== 2 || $effects[1] !== 'crash'
        || DB::table($table)->where('id', $crashId)->value('status') !== 'sending') {
        throw new RuntimeException('Stranded delivery was retried or altered.');
    }

    echo "F03 REAL_MYSQL_CONCURRENT_CLAIM_AND_SIGKILL_NO_RETRY_OK (SYNTHETIC_EFFECT_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'F03 synthetic delivery claim probe failed: ' . get_class($exception) . "\n");
} finally {
    foreach ($children as $child) {
        if ($child->isRunning()) {
            $child->stop(1, 9);
        }
    }
    if ($ids !== []) {
        try {
            DB::table($table)->where('source_type', 'ci-f03-claim')->whereIn('id', $ids)->delete();
        } catch (Throwable) {
            $failed = true;
        }
    }
    if ($createdFolder) {
        foreach (glob($folder . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($folder);
    }
}

exit($failed ? 1 : 0);
