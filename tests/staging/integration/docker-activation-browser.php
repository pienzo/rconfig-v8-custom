<?php

/** Included by the authenticated Apache probe, disposable hosted roles only. */

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

$activationPhase = getenv('RCONFIG_MANUAL_HTTP_PHASE');
apacheCheck(getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
    && in_array($activationPhase, ['seed', 'draft', 'terminal', 'suspended'], true)
    && $persistence === 'none' && $phase === 'http', 'scheduled browser disposable phase');
foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled', 'activation_api_enabled'] as $flag) {
    apacheCheck(config('local-backup-presence.' . $flag) === true, 'synthetic scheduled gate');
}
foreach (['catchup_enabled', 'manual_probe_enabled', 'manual_dispatch_enabled', 'manual_api_enabled'] as $flag) {
    apacheCheck(config('local-backup-presence.' . $flag) === false, 'other gates remain off');
}
apacheCheck(config('local-backup-presence.roots') === ['synthetic' => '/mnt/f02-synthetic']
    && ! file_exists('/mnt/f02-synthetic'), 'HTTP client has no fixture mount');
apacheCheck(config('queue.connections.redis_local_backup_presence.retry_after') === 120, 'unchanged Redis retry lease');
$scheduledQueue = Queue::connection('redis_local_backup_presence');
$unrelatedEmpty = static function (): void {
    foreach (['local_backup_presence_manual_sessions', 'external_backup_check_runs', 'external_backup_file_receipts',
        'custom_backup_notification_deliveries', 'failed_jobs'] as $table) {
        apacheCheck(DB::table($table)->count() === 0, 'empty isolated ledger ' . $table);
    }
    foreach (['TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'] as $queueName) {
        apacheCheck(Queue::connection('redis')->size($queueName) === 0, 'no native download work');
    }
};
$unrelatedEmpty();

if ($activationPhase === 'seed') {
    apacheCheck(Device::count() === 0 && LocalBackupPresenceRule::count() === 0
        && LocalBackupPresenceRun::count() === 0
        && Task::query()->where('task_command', 'rconfig:check-local-backup-presence')->count() === 0
        && $scheduledQueue->size('LocalBackupPresence') === 0, 'fresh scheduled fixture');
    Device::query()->create([
        'device_name' => 'F02-HTTP-SYNTHETIC',
        'device_ip' => '192.0.2.104',
        'device_default_creds_on' => 0,
        'device_username' => 'synthetic',
        'device_password' => 'synthetic-not-a-credential',
        'device_enable_password' => 'synthetic-not-a-credential',
        'device_cred_id' => 0,
        'device_main_prompt' => 'synthetic#',
        'device_enable_prompt' => 'synthetic>',
        'device_category_id' => 9999,
        'device_template' => 0,
        'device_model' => 'Synthetic model',
        'device_version' => '1',
        'device_added_by' => 'synthetic',
        'last_seen' => '2020-05-14 11:53:01',
        'status' => Device::STATUS_DISABLED,
    ]);
    echo "F02_BROWSER_ACTIVATION_SEEDED_OK (SYNTHETIC_ONLY)\n";

    return;
}

$rule = LocalBackupPresenceRule::query()->sole();
$device = Device::query()->sole();
$task = $rule->task()->firstOrFail();
apacheCheck($device->device_name === 'F02-HTTP-SYNTHETIC' && $rule->device_id === $device->id
    && $task->task_name === 'Synthetic browser scheduled presence'
    && $task->task_command === 'rconfig:check-local-backup-presence'
    && $task->task_cron === ['*/5', '*', '*', '*', '*']
    && ! $task->task_email_notify && ! $task->download_report_notify && ! $task->verbose_download_report_notify
    && $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() === [(int) $device->id]
    && $rule->directory_alias === 'synthetic' && $rule->relative_directory === 'device'
    && $rule->pattern_mode === 'glob' && $rule->filename_pattern === 'backup_*.cfg'
    && $rule->minimum_bytes === 1 && $rule->maximum_age_seconds === null && $rule->quiet_seconds === 30
    && $rule->deadline_seconds === 240 && $rule->max_entries === 1000 && $rule->max_matches === 100
    && $rule->max_scan_millis === 2000, 'exact browser criteria and Core task linkage');
$statePath = storage_path('.synthetic-scheduled-browser-state');

if ($activationPhase === 'draft') {
    apacheCheck(! $rule->enabled && $rule->activated_at_utc === null && (bool) $task->is_paused
        && LocalBackupPresenceRun::count() === 0 && $scheduledQueue->size('LocalBackupPresence') === 0
        && ! file_exists($statePath), 'dormant rule has no occurrence or dispatch');
    echo "F02_BROWSER_ACTIVATION_PERSISTED_DRAFT_OK (SYNTHETIC_ONLY)\n";

    return;
}

if ($activationPhase === 'terminal') {
    apacheCheck($rule->enabled && $rule->activated_at_utc !== null && ! (bool) $task->is_paused,
        'actual activation persisted with linked task unpaused');
    $deadline = hrtime(true) + 480_000_000_000;
    $pending = false;
    do {
        $runs = LocalBackupPresenceRun::query()->get();
        apacheCheck($runs->count() <= 1, 'no duplicate scheduled occurrence');
        $run = $runs->first();
        if ($run !== null) {
            apacheCheck($run->rule_id === $rule->id && $run->device_id === $device->id
                && $run->getRawOriginal('scheduled_at_utc') >= $rule->getRawOriginal('activated_at_utc')
                && CarbonImmutable::parse($run->getRawOriginal('scheduled_at_utc'), 'UTC')->minute % 5 === 0,
                'correct scheduled occurrence ownership and minute');
            if ($run->status === LocalBackupPresenceRun::PENDING && $run->reason === 'stability_wait') {
                $pending = true;
            }
            if ($run->status === LocalBackupPresenceRun::PRESENT && $scheduledQueue->size('LocalBackupPresence') === 0) {
                apacheCheck($pending && $run->reason === 'stable_matching_file_observed'
                    && $run->matched_filename === 'backup_a.cfg'
                    && $run->first_observed_at_utc !== null && $run->last_observed_at_utc !== null
                    && $run->directory_identity === null && $run->stability_baseline === null,
                    'actual scheduled stable metadata result');
                $value = json_encode([
                    'rule_id' => $rule->id, 'task_id' => $task->id,
                    'activation' => $rule->getRawOriginal('activated_at_utc'),
                    'run_id' => $run->id, 'digest' => hash('sha256', serialize($run->getRawOriginal())),
                    'next_due' => CarbonImmutable::parse($run->getRawOriginal('scheduled_at_utc'), 'UTC')->addMinutes(5)->timestamp,
                ], JSON_THROW_ON_ERROR);
                $handle = fopen($statePath, 'x');
                apacheCheck(is_resource($handle), 'owned exclusive scheduled state');
                apacheCheck(fwrite($handle, $value) === strlen($value) && fclose($handle), 'record scheduled state');
                $unrelatedEmpty();
                echo "F02_BROWSER_ACTIVATION_SCHEDULED_PRESENT_OK (SYNTHETIC_ONLY)\n";

                return;
            }
            apacheCheck(in_array($run->status, [LocalBackupPresenceRun::PENDING, LocalBackupPresenceRun::PRESENT], true),
                'expected scheduled progression');
        }
        sleep(1);
    } while (hrtime(true) < $deadline);
    apacheCheck(false, 'scheduled stable result timeout');
}

$saved = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
apacheCheck(! $rule->enabled && (bool) $task->is_paused && $rule->id === $saved['rule_id']
    && $task->id === $saved['task_id'] && $rule->getRawOriginal('activated_at_utc') === $saved['activation'],
    'actual suspension persisted with prior activation retained');
$deadline = hrtime(true) + 380_000_000_000;
do {
    $run = LocalBackupPresenceRun::query()->sole();
    apacheCheck($run->id === $saved['run_id']
        && hash_equals($saved['digest'], hash('sha256', serialize($run->getRawOriginal())))
        && $scheduledQueue->size('LocalBackupPresence') === 0, 'terminal history unchanged and no next occurrence');
    $heartbeat = Cache::store('redis')->get('health:checks:schedule:latestHeartbeatAt');
    if ((is_int($heartbeat) || (is_string($heartbeat) && ctype_digit($heartbeat)))
        && (int) $heartbeat >= $saved['next_due'] && (int) $heartbeat <= time()
        && time() >= $saved['next_due'] + 20) {
        apacheCheck(! $rule->fresh()->enabled && (bool) $task->fresh()->is_paused, 'suspension remained in force');
        $unrelatedEmpty();
        apacheCheck(unlink($statePath), 'owned scheduled inspector state cleanup');
        echo "F02_BROWSER_ACTIVATION_NEXT_TICK_OK (SYNTHETIC_ONLY)\n";

        return;
    }
    sleep(2);
} while (hrtime(true) < $deadline);
apacheCheck(false, 'next due minute and fresh scheduler heartbeat not observed');
