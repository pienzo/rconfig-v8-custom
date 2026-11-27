<?php

/** Included by the authenticated Apache probe, disposable hosted roles only. */

use App\Jobs\LocalBackupPresenceManualObserveJob;
use App\Models\Device;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

$manualPhase = getenv('RCONFIG_MANUAL_HTTP_PHASE');
apacheCheck(getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
    && in_array($manualPhase, ['admit', 'queued', 'present', 'absent', 'browser-seed', 'browser-admitted', 'browser-present', 'browser-absent', 'browser-cleanup'], true)
    && $persistence === 'none' && $phase === 'http', 'manual HTTP disposable phase');
foreach (['runtime_enabled', 'queue_enabled', 'scheduler_enabled', 'dispatch_enabled',
    'manual_probe_enabled', 'manual_dispatch_enabled', 'manual_api_enabled'] as $flag) {
    apacheCheck(config('local-backup-presence.' . $flag) === true, 'synthetic manual gate');
}
apacheCheck(config('local-backup-presence.roots') === ['synthetic' => '/mnt/f02-synthetic']
    && config('local-backup-presence.catchup_enabled') === false
    && config('local-backup-presence.activation_api_enabled') === false
    && ! file_exists('/mnt/f02-synthetic'), 'HTTP client has no fixture mount');
apacheCheck(config('queue.connections.redis_local_backup_presence.retry_after') === 120, 'unchanged Redis retry lease');
$manualQueue = Queue::connection('redis_local_backup_presence');
$redis = Redis::connection('default');
$ledgerEmpty = static function (): void {
    foreach (['local_backup_presence_runs', 'external_backup_check_runs', 'external_backup_file_receipts',
        'custom_backup_notification_deliveries', 'failed_jobs'] as $table) {
        apacheCheck(DB::table($table)->count() === 0, 'empty isolated ledger ' . $table);
    }
    foreach (['TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'] as $queueName) {
        apacheCheck(Queue::connection('redis')->size($queueName) === 0, 'no native download work');
    }
};
$ledgerEmpty();
apacheCheck(preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string) $dashboard->getBody(), $csrf) === 1,
    'authenticated manual CSRF');
$manualHeaders = [...$headers, 'X-CSRF-TOKEN' => $csrf[1]];
$base = '/api/tasks/local-backup-presence-rules';
$call = static function (string $method, string $url, int $expected, ?array $payload = null) use ($client, $manualHeaders): array {
    $options = ['headers' => $manualHeaders];
    if ($payload !== null) {
        $options['json'] = $payload;
    }
    $response = $client->request($method, $url, $options);
    if ($response->getStatusCode() !== $expected) {
        // Never log a response body or URL containing a session identifier.
        apacheCheck(false, 'manual HTTP ' . $method . ' expected ' . $expected . ' got ' . $response->getStatusCode());
    }
    $body = (string) $response->getBody();
    apacheCheck(! str_contains($body, '/mnt/f02-synthetic')
        && ! str_contains($body, '"continuation"') && ! str_contains($body, '"dispatch_not_before"'),
        'no internal path or continuation in HTTP response');
    if ($expected === 202 || ($expected === 200 && str_contains($url, '/verify-now'))) {
        apacheCheck(str_contains($response->getHeaderLine('Cache-Control'), 'no-store'), 'private diagnostic response');
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
};
$diagnostic = static function (array $body, int $ruleId): array {
    $data = $body['data'] ?? null;
    apacheCheck(is_array($data)
        && array_keys($data) === ['id', 'rule_id', 'status', 'requested_at_utc', 'expires_at_utc',
            'next_observation_at_utc', 'result', 'evidence_type', 'persistent_check_run', 'verified_success']
        && is_string($data['id']) && Str::isUuid($data['id']) && $data['rule_id'] === $ruleId
        && $data['evidence_type'] === 'metadata_presence' && $data['persistent_check_run'] === false
        && $data['verified_success'] === false, 'explicit diagnostic projection');

    return $data;
};

if (in_array($manualPhase, ['admit', 'browser-seed'], true)) {
    apacheCheck(Device::count() === 0 && LocalBackupPresenceRule::count() === 0
        && LocalBackupPresenceManualSession::count() === 0 && $manualQueue->size('LocalBackupPresence') === 0,
        'fresh isolated fixture');
    // No Faker/dev dependencies in the production-built image.
    $device = Device::query()->create([
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
    $list = $call('GET', $base, 200);
    apacheCheck(($list['manual_available'] ?? null) === true && ($list['activation_available'] ?? null) === false,
        'manual approval only');
    if ($manualPhase === 'browser-seed') {
        apacheCheck(Task::query()->where('task_command', 'rconfig:check-local-backup-presence')->count() === 0,
            'no managed task before browser creation');
        echo "F02_BROWSER_REAL_DEVICE_SEEDED_OK (SYNTHETIC_ONLY)\n";

        return;
    }
    $created = $call('POST', $base, 201, [
        'device_id' => $device->id,
        'task_name' => 'Synthetic HTTP manual diagnostic',
        'task_cron' => ['*', '*', '*', '*', '*'],
        'directory_alias' => 'synthetic',
        'relative_directory' => 'device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => 30,
        'deadline_seconds' => 240,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ]);
    $rule = LocalBackupPresenceRule::query()->findOrFail($created['data']['id']);
    $url = $base . '/' . $rule->id . '/verify-now';
    $first = $diagnostic($call('POST', $url, 202, []), $rule->id);
    apacheCheck($first['status'] === 'queued' && $first['result'] === null
        && $diagnostic($call('POST', $url, 202, []), $rule->id) === $first
        && $diagnostic($call('GET', $url . '/' . $first['id'], 200), $rule->id) === $first,
        'idempotent HTTP admission before roles start');
    $session = LocalBackupPresenceManualSession::query()->findOrFail($first['id']);
    apacheCheck($session->status === 'queued' && $session->dispatch_not_before === null
        && $session->result === null && $session->continuation === null
        && $manualQueue->size('LocalBackupPresence') === 0, 'HTTP did not dispatch or observe');
    $ledgerEmpty();
    echo "F02_HTTP_MANUAL_ADMISSION_OK (SYNTHETIC_ONLY)\n";

    return;
}

$browserStatePath = storage_path('.synthetic-manual-browser-state');
if ($manualPhase === 'browser-cleanup') {
    $saved = json_decode((string) file_get_contents($browserStatePath), true, 512, JSON_THROW_ON_ERROR);
    $device = Device::query()->sole();
    apacheCheck($device->device_name === 'F02-HTTP-SYNTHETIC'
        && LocalBackupPresenceRule::count() === 0 && LocalBackupPresenceManualSession::count() === 0
        && Task::query()->find($saved['task_id']) === null
        && Task::query()->where('task_command', 'rconfig:check-local-backup-presence')->count() === 0
        && $manualQueue->size('LocalBackupPresence') === 0, 'real browser deletion removed rule/task/session');
    DB::transaction(static function () use ($device): void {
        $device->delete();
    });
    apacheCheck(Device::count() === 0 && unlink($browserStatePath), 'owned browser fixture cleanup');
    $ledgerEmpty();
    echo "F02_BROWSER_REAL_INTEGRITY_OK (SYNTHETIC_ONLY)\n";

    return;
}

$rule = LocalBackupPresenceRule::query()->sole();
$device = Device::query()->sole();
$task = $rule->task()->firstOrFail();
apacheCheck($device->device_name === 'F02-HTTP-SYNTHETIC' && $rule->device_id === $device->id
    && $task->task_name === 'Synthetic HTTP manual diagnostic' && ! $rule->enabled
    && $rule->activated_at_utc === null && (bool) $task->is_paused, 'owned dormant rule');
$url = $base . '/' . $rule->id . '/verify-now';
$session = LocalBackupPresenceManualSession::query()->where('rule_id', $rule->id)->sole();
$taskBefore = $task->getRawOriginal();
$ruleBefore = $rule->getRawOriginal();

if (in_array($manualPhase, ['browser-admitted', 'browser-present', 'browser-absent'], true)) {
    apacheCheck($rule->directory_alias === 'synthetic' && $rule->relative_directory === 'device'
        && $rule->pattern_mode === 'glob' && $rule->filename_pattern === 'backup_*.cfg'
        && $rule->minimum_bytes === 2 && $rule->maximum_age_seconds === null && $rule->quiet_seconds === 30
        && $rule->deadline_seconds === 420 && $rule->max_entries === 1000 && $rule->max_matches === 100
        && $rule->max_scan_millis === 2000 && $task->task_cron === ['0', '2', '*', '*', '*']
        && $task->task_command === 'rconfig:check-local-backup-presence'
        && ! $task->task_email_notify && ! $task->download_report_notify && ! $task->verbose_download_report_notify
        && $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() === [(int) $device->id],
        'actual browser persisted exact edited criteria and dormant Core linkage');
    $digest = hash('sha256', serialize([$ruleBefore, $taskBefore]));
    if ($manualPhase === 'browser-admitted') {
        apacheCheck($session->status === 'queued' && $session->result === null && $session->continuation === null
            && $session->dispatch_not_before === null && $manualQueue->size('LocalBackupPresence') === 0
            && LocalBackupPresenceManualSession::count() === 1 && ! file_exists($browserStatePath), 'browser admission has no dispatch');
        $handle = fopen($browserStatePath, 'x');
        apacheCheck(is_resource($handle), 'owned exclusive browser snapshot');
        $value = json_encode(['task_id' => $task->id, 'session_id' => $session->id, 'digest' => $digest], JSON_THROW_ON_ERROR);
        apacheCheck(fwrite($handle, $value) === strlen($value) && fclose($handle), 'record exact synthetic state');
        echo "F02_BROWSER_REAL_PERSISTED_ADMISSION_OK (SYNTHETIC_ONLY)\n";

        return;
    }
    $saved = json_decode((string) file_get_contents($browserStatePath), true, 512, JSON_THROW_ON_ERROR);
    apacheCheck($saved['task_id'] === $task->id && hash_equals($saved['digest'], $digest), 'rule/task unchanged by diagnostic');
    apacheCheck($manualQueue->size('LocalBackupPresence') === 0
        && LocalBackupPresenceManualSession::count() === 1 && $session->continuation === null
        && $session->next_observation_at === null, 'terminal observation and drained queue');
    if ($manualPhase === 'browser-present') {
        apacheCheck($session->id === $saved['session_id'] && $session->status === 'present'
            && $session->result['reason'] === 'stable_matching_file_observed'
            && $session->result['filename'] === 'backup_a.cfg', 'original persisted stable result');
        echo "F02_BROWSER_REAL_PERSISTED_PRESENT_OK (SYNTHETIC_ONLY)\n";
    } else {
        apacheCheck($session->id !== $saved['session_id'] && $session->status === 'absent'
            && $session->result['reason'] === 'no_matching_file' && $session->result['filename'] === null
            && LocalBackupPresenceManualSession::query()->find($saved['session_id']) === null, 'replacement persisted absence');
        $call('GET', $url . '/' . $saved['session_id'], 404);
        echo "F02_BROWSER_REAL_PERSISTED_ABSENT_OK (SYNTHETIC_ONLY)\n";
    }
    apacheCheck($session->result['verified_success'] === false && $session->result['persistent_check_run'] === false,
        'metadata only');
    $ledgerEmpty();

    return;
}

if ($manualPhase === 'queued') {
    // No worker launched yet. Wait for actual schedule:work to enqueue.
    $deadline = hrtime(true) + 85_000_000_000;
    do {
        $messages = $redis->lrange('queues:LocalBackupPresence', 0, -1);
        if (count($messages) === 1) {
            $payload = json_decode($messages[0], true, 512, JSON_THROW_ON_ERROR);
            $fresh = $session->fresh();
            apacheCheck(($payload['data']['commandName'] ?? null) === LocalBackupPresenceManualObserveJob::class
                && is_string($payload['uuid'] ?? null) && Str::isUuid($payload['uuid'])
                && is_string($payload['data']['command'] ?? null) && str_contains($payload['data']['command'], $session->id)
                && $fresh->status === 'queued' && $fresh->dispatch_not_before !== null
                && $fresh->continuation === null && $manualQueue->size('LocalBackupPresence') === 1,
                'actual scheduler dispatched original UUID');
            $ledgerEmpty();
            echo "F02_HTTP_MANUAL_SCHEDULER_OK (SYNTHETIC_ONLY)\n";

            return;
        }
        apacheCheck(count($messages) === 0, 'no unexpected duplicate ready job');
        sleep(1);
    } while (hrtime(true) < $deadline);
    apacheCheck(false, 'scheduler did not enqueue within bounded window');
}

$poll = static function (string $id, string $terminal, bool $requirePending) use ($call, $diagnostic, $url, $rule, $manualQueue): array {
    $deadline = hrtime(true) + 150_000_000_000;
    $pending = false;
    do {
        $data = $diagnostic($call('GET', $url . '/' . $id, 200), $rule->id);
        apacheCheck($data['id'] === $id, 'poll exact UUID');
        if ($data['status'] === 'pending') {
            apacheCheck(($data['result']['reason'] ?? null) === 'stability_wait'
                && $data['next_observation_at_utc'] !== null, 'pending stability evidence');
            $pending = true;
        }
        if ($data['status'] === $terminal && $manualQueue->size('LocalBackupPresence') === 0) {
            apacheCheck(! $requirePending || $pending, 'observed pending before terminal');
            apacheCheck($data['next_observation_at_utc'] === null, 'terminal observation schedule cleared');

            return $data;
        }
        apacheCheck(in_array($data['status'], ['queued', 'pending', $terminal], true), 'expected diagnostic progression');
        sleep(3);
    } while (hrtime(true) < $deadline);
    apacheCheck(false, 'worker terminal observation timeout');

    return [];
};

if ($manualPhase === 'present') {
    $data = $poll($session->id, 'present', true);
    apacheCheck($data['result']['reason'] === 'stable_matching_file_observed'
        && $data['result']['filename'] === 'backup_a.cfg' && is_string($data['result']['observed_at_utc'])
        && $session->fresh()->continuation === null
        && $rule->fresh()->getRawOriginal() === $ruleBefore && $task->fresh()->getRawOriginal() === $taskBefore,
        'actual worker stable metadata result');
    $ledgerEmpty();
    echo "F02_HTTP_MANUAL_STABLE_PRESENT_OK (SYNTHETIC_ONLY)\n";

    return;
}

apacheCheck($session->status === 'present', 'restart only prior terminal diagnostic');
$replacement = $diagnostic($call('POST', $url . '/' . $session->id . '/restart', 202, []), $rule->id);
apacheCheck($replacement['id'] !== $session->id && $replacement['status'] === 'queued', 'new UUID via HTTP restart');
$call('GET', $url . '/' . $session->id, 404);
$call('POST', $url . '/' . $session->id . '/restart', 409, []);
$data = $poll($replacement['id'], 'absent', false);
apacheCheck($data['result']['reason'] === 'no_matching_file' && $data['result']['filename'] === null
    && $rule->fresh()->getRawOriginal() === $ruleBefore && $task->fresh()->getRawOriginal() === $taskBefore,
    'actual worker absence after restart');
$ledgerEmpty();
echo "F02_HTTP_MANUAL_RESTART_ABSENT_OK (SYNTHETIC_ONLY)\n";
$call('DELETE', $base . '/' . $rule->id, 200);
apacheCheck($rule->fresh() === null && Task::query()->find($task->id) === null
    && LocalBackupPresenceManualSession::count() === 0, 'owned rule/task/session deleted');
DB::transaction(static function () use ($device): void {
    $device->delete();
});
apacheCheck(Device::count() === 0 && LocalBackupPresenceRule::count() === 0
    && $manualQueue->size('LocalBackupPresence') === 0, 'owned fixture and queue cleanup');
$ledgerEmpty();
echo "F02_HTTP_MANUAL_INTEGRITY_OK (SYNTHETIC_ONLY)\n";
