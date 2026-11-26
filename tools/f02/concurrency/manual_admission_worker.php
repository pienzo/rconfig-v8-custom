<?php

use App\Models\LocalBackupPresenceManualSession;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/manual_admission_boot.php';

try {
    admissionCheck($argc === 4, 'manual worker arguments');
    [$script, $mode, $rawId, $marker] = $argv;
    $ruleId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $folder = realpath(dirname($marker));
    $temp = realpath(sys_get_temp_dir());
    admissionCheck(in_array($mode, ['hold', 'follow'], true) && $ruleId !== false
        && $folder !== false && $temp !== false
        && str_starts_with($folder, $temp . '/rconfig_f02_manual_admission_')
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
        $session = app(LocalBackupPresenceManualSessions::class)->start($ruleId);
        admissionCheck((int) $session->rule_id === $ruleId && $session->status === 'queued', 'actual manual admission');
    } catch (DomainException $e) {
        admissionCheck($mode === 'follow' && $e->getMessage() === 'Manual local backup session capacity reached.', 'unexpected manual refusal');
        DB::rollBack();
        Bus::assertNothingDispatched();
        echo "capacity\n";
        exit(0);
    }

    Bus::assertNothingDispatched();
    if ($mode === 'hold') {
        admissionCheck(DB::transactionLevel() === 1, 'manual outer transaction retained');
        admissionMark($marker . '.held', $session->id);
        $deadline = microtime(true) + 25;
        while (! is_file($marker . '.release')) {
            admissionCheck(microtime(true) < $deadline, 'manual holder release timeout');
            usleep(10000);
        }
    }
    DB::commit();
    echo $session->id . PHP_EOL;
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'F02 manual admission worker failed: ' . get_class($e) . PHP_EOL);
    exit(1);
}
