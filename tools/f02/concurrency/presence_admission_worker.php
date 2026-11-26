<?php

use App\Models\LocalBackupPresenceRule;
use App\Services\ExternalBackups\LocalBackupPresenceRuleActivation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/presence_admission_boot.php';

try {
    admissionCheck($argc === 5, 'worker arguments');
    [$script, $mode, $rawId, $action, $marker] = $argv;
    $ruleId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $folder = realpath(dirname($marker));
    $temp = realpath(sys_get_temp_dir());
    admissionCheck(in_array($mode, ['hold', 'follow'], true) && in_array($action, ['enable', 'disable'], true)
        && $ruleId !== false && $folder !== false && $temp !== false
        && str_starts_with($folder, $temp . '/rconfig_f02_admission_')
        && (fileperms($folder) & 0777) === 0700
        && in_array(basename($marker), ['holder', 'follower'], true), 'private barrier');
    // Establish an older consistent-read snapshot before the singleton read.
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    $count = LocalBackupPresenceRule::where('enabled', true)->count();
    admissionMark($marker . '.ready', json_encode([
        'connection' => admissionConnectionId(),
        'count' => $count,
    ], JSON_THROW_ON_ERROR));

    try {
        $rule = app(LocalBackupPresenceRuleActivation::class)->change($ruleId, $action === 'enable');
        admissionCheck($rule->enabled === ($action === 'enable'), 'actual service state');
    } catch (DomainException $e) {
        admissionCheck($mode === 'follow' && $e->getMessage() === 'Local backup active rule capacity reached.', 'unexpected refusal');
        DB::rollBack();
        Bus::assertNothingDispatched();
        echo "capacity\n";
        exit(0);
    }

    Bus::assertNothingDispatched();
    if ($mode === 'hold') {
        admissionCheck(DB::transactionLevel() === 1, 'outer transaction retained');
        admissionMark($marker . '.held', "held\n");
        $deadline = microtime(true) + 25;
        while (! is_file($marker . '.release')) {
            admissionCheck(microtime(true) < $deadline, 'holder release timeout');
            usleep(10000);
        }
    }
    DB::commit();
    echo "committed\n";
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'F02 admission worker failed: ' . get_class($e) . PHP_EOL);
    exit(1);
}
