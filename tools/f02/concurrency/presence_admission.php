<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceRuleActivation;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__ . '/presence_admission_boot.php';

$folder = sys_get_temp_dir() . '/rconfig_f02_admission_' . bin2hex(random_bytes(8));
$device = null;
$rules = [];
$tasks = [];
$failed = false;

function admissionState(int $id): array
{
    $rule = LocalBackupPresenceRule::findOrFail($id);
    return [$rule->getRawOriginal(), $rule->task()->firstOrFail()->getRawOriginal()];
}

function admissionAssertState(int $id, bool $enabled): void
{
    $rule = LocalBackupPresenceRule::findOrFail($id);
    admissionCheck($rule->enabled === $enabled
        && (bool) $rule->task->is_paused === ! $enabled
        && $rule->runs()->count() === 0
        && $rule->scheduler_checked_minute_utc === null
        && $rule->scheduler_revision === null
        && (! $enabled || $rule->getRawOriginal('activated_at_utc') === '2026-11-25 06:01:00'), 'rule/task state');
}

try {
    admissionCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 0
        && DB::table('local_backup_presence_activation_lock')->where('id', 1)->exists()
        && DB::transactionLevel() === 0, 'empty admission fixture');
    admissionCheck(mkdir($folder, 0700), 'private barrier directory');
    $before = [LocalBackupPresenceRule::count(), LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(),
        ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count(), Task::count(), Device::count()];
    $device = Device::factory()->create(['device_name' => 'F02-ADMISSION-CI', 'device_ip' => '192.0.2.100']);
    $lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $activation = app(LocalBackupPresenceRuleActivation::class);
    $criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => null,
        'deadline_seconds' => 180,
        'max_entries' => 1000,
        'max_matches' => 100,
        'max_scan_millis' => 2000,
    ];
    for ($index = 1; $index <= 101; $index++) {
        $rule = $lifecycle->create($device->id, 'Synthetic admission ' . $index, '* * * * *', $criteria);
        $rules[$index] = (int) $rule->id;
        $tasks[$index] = (int) $rule->task_id;
        if ($index <= 99) {
            $activation->change($rule->id, true);
        }
    }
    admissionCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 99, '99 active rules');

    // Hold an actual completed service call within an uncommitted outer
    // transaction; prove the other connection is blocked on the exact mutex.
    $race = static function (int $first, string $firstAction, int $second, string $secondAction, bool $kill, int $expectedBefore, string $expectedSecond) use ($folder, $root): void {
        $holderMarker = $folder . '/holder';
        $followerMarker = $folder . '/follower';
        $worker = $root . '/tools/f02/concurrency/presence_admission_worker.php';
        $holder = new Process([PHP_BINARY, $worker, 'hold', (string) $first, $firstAction, $holderMarker], $root, null, null, 45);
        $follower = new Process([PHP_BINARY, $worker, 'follow', (string) $second, $secondAction, $followerMarker], $root, null, null, 45);
        $firstBefore = admissionState($first);
        $secondBefore = admissionState($second);
        $wait = static function (callable $condition, Process $process, string $label): void {
            $deadline = microtime(true) + 10;
            while (! $condition()) {
                admissionCheck($process->isRunning() && microtime(true) < $deadline, $label);
                usleep(10000);
            }
        };
        try {
            $holder->start();
            $wait(static fn (): bool => is_file($holderMarker . '.held'), $holder, 'holder reached actual service boundary');
            $follower->start();
            $wait(static fn (): bool => is_file($followerMarker . '.ready'), $follower, 'follower established snapshot');
            $holderInfo = json_decode(file_get_contents($holderMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            $followerInfo = json_decode(file_get_contents($followerMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            admissionCheck($holderInfo['count'] === $expectedBefore && $followerInfo['count'] === $expectedBefore
                && $holderInfo['connection'] !== $followerInfo['connection']
                && $holderInfo['connection'] !== admissionConnectionId()
                && $followerInfo['connection'] !== admissionConnectionId(), 'independent precommit snapshots');

            $wait(static function () use ($holderInfo, $followerInfo): bool {
                return DB::selectOne(
                    "SELECT 1 AS waiting FROM performance_schema.data_lock_waits w
                     JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                     JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                     JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                     WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ?
                       AND l.OBJECT_SCHEMA = 'v8core_test'
                       AND l.OBJECT_NAME = 'local_backup_presence_activation_lock'
                       AND l.LOCK_TYPE = 'RECORD' LIMIT 1",
                    [$followerInfo['connection'], $holderInfo['connection']]
                ) !== null;
            }, $follower, 'actual singleton lock contention');
            admissionCheck($holder->isRunning() && $follower->isRunning()
                && admissionState($first) === $firstBefore && admissionState($second) === $secondBefore
                && LocalBackupPresenceRule::where('enabled', true)->count() === $expectedBefore, 'uncommitted state invisible');

            if ($kill) {
                $holder->signal(SIGKILL);
                $holder->wait();
                admissionCheck(! $holder->isSuccessful(), 'owned holder interrupted');
            } else {
                admissionMark($holderMarker . '.release', "release\n");
                $holder->wait();
                admissionCheck($holder->isSuccessful() && trim($holder->getOutput()) === 'committed', 'holder committed');
            }
            $follower->wait();
            admissionCheck($follower->isSuccessful() && trim($follower->getOutput()) === $expectedSecond, 'follower exact result');
            if ($kill) {
                admissionCheck(admissionState($first) === $firstBefore, 'full rollback of rule and task');
            }
            if ($expectedSecond === 'capacity') {
                admissionCheck(admissionState($second) === $secondBefore, 'refused draft untouched');
            }
        } finally {
            foreach ([$holder, $follower] as $child) {
                if ($child->isRunning()) {
                    $child->stop(1, SIGKILL);
                }
            }
            foreach (glob($folder . '/holder.*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($folder . '/follower.*') ?: [] as $file) {
                unlink($file);
            }
        }
    };

    $a = $rules[100];
    $b = $rules[101];
    $race($a, 'enable', $b, 'enable', false, 99, 'capacity');
    admissionAssertState($a, true);
    admissionAssertState($b, false);
    admissionCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 100, 'exact 100 after commit');
    echo "F02_PRESENCE_ADMISSION_COMMIT_OK (SYNTHETIC_ONLY)\n";

    $activation->change($a, false);
    $race($a, 'enable', $b, 'enable', true, 99, 'committed');
    admissionAssertState($a, false);
    admissionAssertState($b, true);
    admissionCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 100, 'exact 100 after crash');
    echo "F02_PRESENCE_ADMISSION_ROLLBACK_OK (SYNTHETIC_ONLY)\n";

    $race($b, 'disable', $a, 'enable', false, 100, 'committed');
    admissionAssertState($a, true);
    admissionAssertState($b, false);
    admissionCheck(LocalBackupPresenceRule::where('enabled', true)->count() === 100, 'exact 100 after suspension');
    echo "F02_PRESENCE_ADMISSION_SUSPEND_OK (SYNTHETIC_ONLY)\n";

    $replayBefore = admissionState($a);
    $race($a, 'enable', $a, 'enable', false, 100, 'committed');
    admissionCheck(admissionState($a) === $replayBefore
        && LocalBackupPresenceRule::where('enabled', true)->count() === 100, 'exact duplicate replay');
    echo "F02_PRESENCE_ADMISSION_REPLAY_OK (SYNTHETIC_ONLY)\n";

    foreach ($rules as $index => $id) {
        admissionAssertState($id, $index <= 100);
    }
    Bus::assertNothingDispatched();
    admissionCheck([LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count()] === array_slice($before, 1, 4), 'no occurrence, receipt or delivery effects');
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'F02 admission probe failed: ')
        ? $e->getMessage() : 'F02 admission probe failed: ' . get_class($e)) . PHP_EOL);
} finally {
    try {
        // Delete only owned synthetic rows. Never delete a real rule or run.
        if ($rules !== []) {
            admissionCheck(! LocalBackupPresenceRun::whereIn('rule_id', $rules)->exists(), 'no synthetic history to destroy');
            LocalBackupPresenceRule::whereIn('id', $rules)->delete();
            DB::table('device_task')->whereIn('task_id', $tasks)->delete();
            Task::whereIn('id', $tasks)->delete();
        }
        if ($device !== null) {
            $device->delete();
        }
        if (is_dir($folder)) {
            admissionCheck(rmdir($folder), 'barrier cleanup');
        }
        if (isset($before)) {
            admissionCheck([LocalBackupPresenceRule::count(), LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(),
                ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count(), Task::count(), Device::count()] === $before, 'fixture cleanup integrity');
        }
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, 'F02 admission cleanup failed: ' . get_class($e) . PHP_EOL);
    }
}
if (! $failed) {
    echo "F02_PRESENCE_ADMISSION_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
}
exit($failed ? 1 : 0);
