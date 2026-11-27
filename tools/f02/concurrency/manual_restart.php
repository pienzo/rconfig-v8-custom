<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceManualWorker;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (getenv('F02_SYNTHETIC_MANUAL_RESTART') !== '1') {
    exit(2);
}
require __DIR__ . '/manual_admission_boot.php';

$folder = sys_get_temp_dir() . '/rconfig_f02_manual_restart_' . bin2hex(random_bytes(8));
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
    $device = Device::factory()->create(['device_name' => 'F02-MANUAL-RESTART-CI', 'device_ip' => '192.0.2.101']);
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
    $instant = CarbonImmutable::now('UTC');
    for ($index = 1; $index <= 10; $index++) {
        $criteria['deadline_seconds'] = $index === 10 ? 180 : 600;
        $rule = $lifecycle->create((int) $device->id, 'Synthetic manual restart ' . $index, '* * * * *', $criteria);
        $rules[$index] = (int) $rule->id;
        $tasks[$index] = (int) $rule->task_id;
        $ruleStates[$index] = [$rule->getRawOriginal(), $rule->task()->firstOrFail()->getRawOriginal()];
        $service->start((int) $rule->id);
    }
    $a = $rules[10];
    $markTerminal = static function (int $ruleId): LocalBackupPresenceManualSession {
        $session = LocalBackupPresenceManualSession::where('rule_id', $ruleId)->firstOrFail();
        $session->status = 'present';
        $session->result = ['status' => 'present', 'reason' => 'synthetic_terminal_fixture'];
        $session->next_observation_at = null;
        $session->dispatch_not_before = $session->requested_at + 55;
        $session->save();

        return $session->refresh();
    };
    $old = $markTerminal($a);
    $baseline = manualAdmissionRows();
    admissionCheck(count($baseline) === 10, 'ten existing manual sessions');
    $unaffected = array_diff_key($baseline, [$old->id => true]);

    // Establish a stale consistent-read snapshot, then commit a pivot
    // mutation via a second connection before taking the rule/task locks.
    // The authoritative linkage check must see the current missing pivot.
    $writerName = 'f02_linkage_writer';
    admissionCheck(config('database.connections.' . $writerName) === null, 'unused writer connection name');
    config(['database.connections.' . $writerName => config('database.connections.mysql')]);
    $writer = DB::connection($writerName);
    $pivot = $writer->table('device_task')->where('task_id', $tasks[10])->where('device_id', $device->id);
    $originalPivot = $pivot->first();
    $removed = false;
    try {
        admissionCheck($writer->getDatabaseName() === 'v8core_test'
            && (int) $writer->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id !== admissionConnectionId()
            && $originalPivot !== null && $pivot->count() === 1, 'independent writer and owned pivot');
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        $readerPivot = DB::table('device_task')->where('task_id', $tasks[10])->where('device_id', $device->id);
        admissionCheck($readerPivot->count() === 1, 'consistent-read snapshot established');
        $deleted = $pivot->delete();
        $removed = $deleted === 1;
        admissionCheck($removed && $pivot->count() === 0 && $readerPivot->count() === 1,
            'committed missing pivot is invisible to old snapshot');
        foreach (['start', 'restart'] as $operation) {
            try {
                if ($operation === 'start') {
                    $service->start($a);
                } else {
                    $service->restart($a, $old->id);
                }
                admissionCheck(false, 'stale snapshot admitted corrupt linkage');
            } catch (DomainException $e) {
                admissionCheck($e->getMessage() === 'Linked local backup task is inconsistent.',
                    'current pivot read refuses stale snapshot');
            }
        }
        admissionCheck(DB::transactionLevel() === 1 && manualAdmissionRows() === $baseline,
            'refusals preserve outer transaction and session snapshot');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($removed) {
            admissionCheck($writer->table('device_task')->insert((array) $originalPivot), 'restore exact owned pivot');
        }
        DB::purge($writerName);
        config(['database.connections.' . $writerName => null]);
    }
    admissionCheck(manualAdmissionRows() === $baseline
        && DB::table('device_task')->where('task_id', $tasks[10])->where('device_id', $device->id)->count() === 1,
        'committed session snapshot unchanged and owned pivot restored');
    echo "F02_MANUAL_LINKAGE_CURRENT_READ_OK (SYNTHETIC_ONLY)\n";

    $race = static function (int $ruleId, string $expected, string $first, string $second, int $offset, bool $kill, bool $stale) use ($folder, $root): array {
        $baseline = manualAdmissionRows();
        $holderMarker = $folder . '/holder';
        $followerMarker = $folder . '/follower';
        $worker = $root . '/tools/f02/concurrency/manual_restart_worker.php';
        $holder = new Process([PHP_BINARY, $worker, 'hold', (string) $ruleId, $holderMarker, $first, $expected, (string) $offset], $root, null, null, 45);
        $follower = new Process([PHP_BINARY, $worker, 'follow', (string) $ruleId, $followerMarker, $second, $expected, (string) $offset], $root, null, null, 45);
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
            admissionCheck($first === 'prune' ? $heldId === 'pruned:1' : Str::isUuid($heldId) && $heldId !== $expected, 'held operation result');
            $follower->start();
            $wait(static fn (): bool => is_file($followerMarker . '.ready'), $follower, 'manual follower established snapshot');
            $holderInfo = json_decode(file_get_contents($holderMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            $followerInfo = json_decode(file_get_contents($followerMarker . '.ready'), true, 512, JSON_THROW_ON_ERROR);
            admissionCheck($holderInfo['count'] === 10 && $followerInfo['count'] === 10
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
                admissionCheck(! $holder->isSuccessful() && $holder->hasBeenSignaled()
                    && $holder->getTermSignal() === SIGKILL, 'owned manual holder interrupted');
            } else {
                admissionMark($holderMarker . '.release', "release\n");
                $holder->wait();
                admissionCheck($holder->isSuccessful() && trim($holder->getOutput()) === $heldId, 'manual holder committed');
            }
            $follower->wait();
            $followId = trim($follower->getOutput());
            admissionCheck($follower->isSuccessful()
                && ($stale ? $followId === 'stale' : Str::isUuid($followId) && $followId !== $expected), 'manual follower exact result');
            $actual = manualAdmissionRows();
            $unaffected = array_diff_key($baseline, [$expected => true]);
            admissionCheck(count($actual) === 10 && ! isset($actual[$expected])
                && array_intersect_key($actual, $unaffected) === $unaffected,
                'exact capacity, old UUID removed and unrelated sessions unchanged');

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

    $assertFresh = static function (string $id, string $previous, int $offset) use ($a, $instant, $service, $unaffected): void {
        CarbonImmutable::setTestNow($instant->addSeconds($offset));
        $session = LocalBackupPresenceManualSession::findOrFail($id);
        admissionCheck($id !== $previous && (int) $session->rule_id === $a
            && $session->status === 'queued' && $session->result === null
            && $session->continuation === null && $session->dispatch_not_before === null
            && $session->requested_at === $instant->addSeconds($offset)->getTimestamp()
            && $session->expires_at === $session->requested_at + 180
            && $session->next_observation_at === $session->requested_at, 'clean replacement fields');
        $rows = manualAdmissionRows();
        admissionCheck(! isset($rows[$previous]) && array_intersect_key($rows, $unaffected) === $unaffected,
            'unaffected baseline and old UUID absent');
        app(LocalBackupPresenceManualWorker::class)->process($a, $previous);
        admissionCheck(manualAdmissionRows() === $rows && $service->start($a)->id === $id,
            'stale worker no-op and ordinary admission replay');
        try {
            $service->restart($a, $previous);
            admissionCheck(false, 'stale restart unexpectedly succeeded');
        } catch (DomainException $e) {
            admissionCheck($e->getMessage() === 'Manual local backup session is no longer restartable.',
                'exact stale restart refusal');
        }
        admissionCheck(manualAdmissionRows() === $rows, 'refusal leaves rows unchanged');
    };

    [$held, $refused] = $race($a, $old->id, 'restart', 'restart', 60, false, true);
    admissionCheck($refused === 'stale', 'one restart wins at full capacity');
    $assertFresh($held, $old->id, 60);
    echo "F02_MANUAL_RESTART_COMMIT_OK (SYNTHETIC_ONLY)\n";

    $old = $markTerminal($a);
    [$rolledBack, $admitted] = $race($a, $old->id, 'restart', 'restart', 60, true, false);
    admissionCheck($rolledBack !== $admitted && LocalBackupPresenceManualSession::find($rolledBack) === null,
        'interrupted replacement rolled back and follower admitted');
    $assertFresh($admitted, $old->id, 60);
    echo "F02_MANUAL_RESTART_ROLLBACK_OK (SYNTHETIC_ONLY)\n";

    $old = $markTerminal($a);
    CarbonImmutable::setTestNow($instant->addSeconds(239));
    admissionCheck($service->pruneExpired() === 0, 'not expired one second before boundary');
    [$pruned, $fresh] = $race($a, $old->id, 'prune', 'start', 240, false, false);
    admissionCheck($pruned === 'pruned:1', 'prune committed exactly one expiry');
    $assertFresh($fresh, $old->id, 240);
    echo "F02_MANUAL_RESTART_EXPIRY_OK (SYNTHETIC_ONLY)\n";

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
    echo "F02_MANUAL_RESTART_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
}
exit($failed ? 1 : 0);
