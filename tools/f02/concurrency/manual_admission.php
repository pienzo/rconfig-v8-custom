<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__ . '/manual_admission_boot.php';

$folder = sys_get_temp_dir() . '/rconfig_f02_manual_admission_' . bin2hex(random_bytes(8));
$device = null;
$rules = [];
$tasks = [];
$failed = false;

function manualAdmissionRows(): array
{
    return LocalBackupPresenceManualSession::query()->orderBy('id')->get()
        ->mapWithKeys(static fn ($session): array => [$session->id => $session->getRawOriginal()])->all();
}

function manualAdmissionIntegrity(): array
{
    return [LocalBackupPresenceRule::count(), LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(),
        ExternalBackupFileReceipt::count(), DB::table('custom_backup_notification_deliveries')->count(),
        Task::count(), Device::count(), LocalBackupPresenceManualSession::count()];
}

try {
    admissionCheck(LocalBackupPresenceManualSession::count() === 0
        && LocalBackupPresenceRule::where('enabled', true)->count() === 0
        && DB::table('local_backup_presence_activation_lock')->where('id', 1)->exists()
        && DB::transactionLevel() === 0, 'empty manual admission fixture');
    admissionCheck(mkdir($folder, 0700), 'private manual barrier directory');
    $before = manualAdmissionIntegrity();
    $device = Device::factory()->create(['device_name' => 'F02-MANUAL-ADMISSION-CI', 'device_ip' => '192.0.2.101']);
    $lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $service = app(LocalBackupPresenceManualSessions::class);
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
    $ruleStates = [];
    for ($index = 1; $index <= 11; $index++) {
        $rule = $lifecycle->create((int) $device->id, 'Synthetic manual admission ' . $index, '* * * * *', $criteria);
        $rules[$index] = (int) $rule->id;
        $tasks[$index] = (int) $rule->task_id;
        $ruleStates[$index] = [$rule->getRawOriginal(), $rule->task()->firstOrFail()->getRawOriginal()];
        if ($index <= 9) {
            $service->start((int) $rule->id);
        }
    }
    $baseline = manualAdmissionRows();
    admissionCheck(count($baseline) === 9, 'nine existing manual sessions');

    $race = static function (int $first, int $second, bool $kill, bool $capacity) use ($folder, $root, $baseline): array {
        $holderMarker = $folder . '/holder';
        $followerMarker = $folder . '/follower';
        $worker = $root . '/tools/f02/concurrency/manual_admission_worker.php';
        $holder = new Process([PHP_BINARY, $worker, 'hold', (string) $first, $holderMarker], $root, null, null, 45);
        $follower = new Process([PHP_BINARY, $worker, 'follow', (string) $second, $followerMarker], $root, null, null, 45);
        $wait = static function (callable $condition, Process $process, string $label): void {
            $deadline = microtime(true) + 10;
            while (! $condition()) {
                admissionCheck($process->isRunning() && microtime(true) < $deadline, $label);
                usleep(10000);
            }
        };
        try {
            $holder->start();
            $wait(static fn (): bool => is_file($holderMarker . '.held'), $holder, 'manual holder reached service boundary');
            $heldId = trim((string) file_get_contents($holderMarker . '.held'));
            admissionCheck(Str::isUuid($heldId), 'held manual UUID');
            $follower->start();
            $wait(static fn (): bool => is_file($followerMarker . '.ready'), $follower, 'manual follower established snapshot');
            $holderInfo = json_decode(file_get_contents($holderMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            $followerInfo = json_decode(file_get_contents($followerMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            admissionCheck($holderInfo['count'] === 9 && $followerInfo['count'] === 9
                && $holderInfo['connection'] !== $followerInfo['connection']
                && $holderInfo['connection'] !== admissionConnectionId()
                && $followerInfo['connection'] !== admissionConnectionId(), 'independent manual snapshots');
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
            }, $follower, 'actual manual singleton lock contention');
            admissionCheck($holder->isRunning() && $follower->isRunning()
                && manualAdmissionRows() === $baseline, 'uncommitted manual session invisible');

            if ($kill) {
                $holder->signal(SIGKILL);
                $holder->wait();
                admissionCheck(! $holder->isSuccessful(), 'owned manual holder interrupted');
            } else {
                admissionMark($holderMarker . '.release', "release\n");
                $holder->wait();
                admissionCheck($holder->isSuccessful() && trim($holder->getOutput()) === $heldId, 'manual holder committed');
            }
            $follower->wait();
            $followId = trim($follower->getOutput());
            admissionCheck($follower->isSuccessful()
                && ($capacity ? $followId === 'capacity' : Str::isUuid($followId)), 'manual follower exact result');
            $actual = manualAdmissionRows();
            admissionCheck(count($actual) === 10 && array_intersect_key($actual, $baseline) === $baseline,
                'exact capacity and unchanged existing sessions');

            return [$heldId, $followId];
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

    $a = $rules[10];
    $b = $rules[11];
    [$held, $refused] = $race($a, $b, false, true);
    admissionCheck($refused === 'capacity'
        && (int) LocalBackupPresenceManualSession::findOrFail($held)->rule_id === $a
        && ! LocalBackupPresenceManualSession::where('rule_id', $b)->exists(), 'tenth admitted, eleventh refused');
    echo "F02_MANUAL_ADMISSION_COMMIT_OK (SYNTHETIC_ONLY)\n";

    // Remove only the extra owned fixture session to reset to nine.
    admissionCheck(LocalBackupPresenceManualSession::whereKey($held)->where('rule_id', $a)->delete() === 1
        && manualAdmissionRows() === $baseline, 'reset first synthetic race');
    [$rolledBack, $admitted] = $race($a, $b, true, false);
    admissionCheck($rolledBack !== $admitted && LocalBackupPresenceManualSession::find($rolledBack) === null
        && ! LocalBackupPresenceManualSession::where('rule_id', $a)->exists()
        && (int) LocalBackupPresenceManualSession::findOrFail($admitted)->rule_id === $b, 'rollback releases capacity');
    echo "F02_MANUAL_ADMISSION_ROLLBACK_OK (SYNTHETIC_ONLY)\n";

    admissionCheck(LocalBackupPresenceManualSession::whereKey($admitted)->where('rule_id', $b)->delete() === 1
        && manualAdmissionRows() === $baseline, 'reset second synthetic race');
    [$held, $replayed] = $race($a, $a, false, false);
    admissionCheck($held === $replayed
        && $service->start($a)->id === $held
        && LocalBackupPresenceManualSession::where('rule_id', $a)->count() === 1, 'concurrent same-rule UUID replay');
    echo "F02_MANUAL_ADMISSION_REPLAY_OK (SYNTHETIC_ONLY)\n";

    foreach ($rules as $index => $id) {
        $rule = LocalBackupPresenceRule::findOrFail($id);
        admissionCheck([$rule->getRawOriginal(), $rule->task()->firstOrFail()->getRawOriginal()] === $ruleStates[$index]
            && $rule->enabled === false && $rule->runs()->count() === 0, 'unchanged dormant rule and task');
    }
    foreach (LocalBackupPresenceManualSession::all() as $session) {
        admissionCheck($session->status === 'queued' && $session->result === null
            && $session->continuation === null && $session->dispatch_not_before === null, 'no manual observation or dispatch');
    }
    Bus::assertNothingDispatched();
    admissionCheck(array_slice(manualAdmissionIntegrity(), 1, 4) === array_slice($before, 1, 4), 'no scheduled or notification side effects');
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'F02 admission probe failed: ')
        ? $e->getMessage() : 'F02 manual admission probe failed: ' . get_class($e)) . PHP_EOL);
} finally {
    try {
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
            admissionCheck(rmdir($folder), 'manual barrier cleanup');
        }
        if (isset($before)) {
            admissionCheck(manualAdmissionIntegrity() === $before, 'manual fixture cleanup integrity');
        }
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, 'F02 manual admission cleanup failed: ' . get_class($e) . PHP_EOL);
    }
}
if (! $failed) {
    echo "F02_MANUAL_ADMISSION_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
}
exit($failed ? 1 : 0);
