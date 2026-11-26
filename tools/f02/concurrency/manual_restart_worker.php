<?php

use App\Models\LocalBackupPresenceManualSession;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (getenv('F02_SYNTHETIC_MANUAL_RESTART') !== '1') {
    exit(2);
}
require __DIR__ . '/manual_admission_boot.php';

try {
    admissionCheck(getenv('F02_SYNTHETIC_MANUAL_RESTART') === '1' && $argc === 7, 'manual restart worker arguments');
    [$script, $mode, $rawId, $marker, $operation, $expected, $offset] = $argv;
    admissionCheck(in_array($operation, ['restart', 'prune', 'start'], true)
        && Str::isUuid($expected) && in_array($offset, ['60', '240'], true), 'restart operation and clock');
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds((int) $offset));
    $ruleId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $folder = realpath(dirname($marker));
    $temp = realpath(sys_get_temp_dir());
    admissionCheck(in_array($mode, ['hold', 'follow'], true) && $ruleId !== false
        && $folder !== false && $temp !== false
        && str_starts_with($folder, $temp . '/rconfig_f02_manual_restart_')
        && (fileperms($folder) & 0777) === 0700
        && in_array(basename($marker), ['holder', 'follower'], true), 'private manual barrier');

    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    $count = LocalBackupPresenceManualSession::count();
    admissionMark($marker . '.ready', json_encode([
        'connection' => admissionConnectionId(),
        'count' => $count,
    ], JSON_THROW_ON_ERROR));

    try {
        $service = app(LocalBackupPresenceManualSessions::class);
        if ($operation === 'prune') {
            $result = 'pruned:' . $service->pruneExpired();
            admissionCheck($result === 'pruned:1', 'exact expiry prune');
        } else {
            $session = $operation === 'restart' ? $service->restart($ruleId, $expected) : $service->start($ruleId);
            admissionCheck((int) $session->rule_id === $ruleId && $session->status === 'queued'
                && $session->id !== $expected && Str::isUuid($session->id), 'fresh manual session');
            $result = $session->id;
        }
    } catch (DomainException $e) {
        admissionCheck($mode === 'follow' && $operation === 'restart'
            && $e->getMessage() === 'Manual local backup session is no longer restartable.', 'unexpected manual refusal');
        DB::rollBack();
        Bus::assertNothingDispatched();
        echo "stale\n";
        exit(0);
    }

    Bus::assertNothingDispatched();
    if ($mode === 'hold') {
        admissionCheck(DB::transactionLevel() === 1, 'manual outer transaction retained');
        admissionMark($marker . '.held', $result);
        $deadline = microtime(true) + 25;
        while (! is_file($marker . '.release')) {
            admissionCheck(microtime(true) < $deadline, 'manual holder release timeout');
            usleep(10000);
        }
    }
    DB::commit();
    echo $result . PHP_EOL;
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'F02 manual admission worker failed: ' . get_class($e) . PHP_EOL);
    exit(1);
}
