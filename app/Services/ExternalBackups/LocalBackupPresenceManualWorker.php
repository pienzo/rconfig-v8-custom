<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Execute one due diagnostic observation, never a scheduled occurrence. */
final class LocalBackupPresenceManualWorker
{
    public function __construct(private readonly LocalBackupPresenceManualObservation $observation) {}

    public function process(int $ruleId, string $sessionId): void
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true
            || config('local-backup-presence.queue_enabled', false) !== true
            || config('local-backup-presence.manual_probe_enabled', false) !== true
            || config('rConfig.is_demo', false)) {
            return;
        }
        if ($ruleId < 1 || ! Str::isUuid($sessionId)) {
            throw new InvalidArgumentException('Invalid manual observation identifiers.');
        }

        // Match the lifecycle lock order. No automatic transaction retry
        // around filesystem IO. The caller may retry the same job later.
        DB::transaction(function () use ($ruleId, $sessionId): void {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->first();
            if ($rule === null || $rule->task()->lockForUpdate()->first() === null) {
                return;
            }
            $session = LocalBackupPresenceManualSession::query()
                ->whereKey($sessionId)->where('rule_id', $ruleId)->lockForUpdate()->first();
            if ($session === null || in_array($session->status, ['present', 'absent', 'error'], true)) {
                return;
            }

            $now = CarbonImmutable::now('UTC')->getTimestamp();
            if ($now >= $session->expires_at) {
                $this->fail($session, 'manual_probe_expired');

                return;
            }
            if ($now < $session->requested_at || $session->next_observation_at === null
                || $session->next_observation_at < $session->requested_at
                || $session->next_observation_at > $session->expires_at
                || ! in_array($session->status, ['queued', 'pending'], true)
                || ($session->status === 'queued' && $session->continuation !== null)
                || ($session->status === 'pending' && ! is_array($session->continuation))) {
                $this->fail($session, 'manual_probe_changed_or_invalid');

                return;
            }
            if ($now < $session->next_observation_at) {
                return;
            }

            try {
                $outcome = $this->observation->probe($ruleId, $session->continuation);
            } catch (DomainException|InvalidArgumentException) {
                $this->fail($session, 'invalid_configuration');

                return;
            }

            $finished = CarbonImmutable::now('UTC')->getTimestamp();
            if ($finished < $now) {
                $this->fail($session, 'observation_clock_moved_backwards');

                return;
            }
            // Admission TTL includes queue latency. The inner observation
            // window cannot extend it even if the first worker starts late.
            if ($finished >= $session->expires_at) {
                $this->fail($session, 'manual_probe_expired');

                return;
            }
            $status = $outcome['result']['status'];
            if (! in_array($status, ['pending', 'present', 'absent', 'error'], true)
                || ($status === 'pending' && ($outcome['continuation'] === null || $outcome['retry_after_seconds'] < 1))) {
                $this->fail($session, 'manual_probe_changed_or_invalid');

                return;
            }

            $session->status = $status;
            $session->result = $outcome['result'];
            $session->continuation = $status === 'pending' ? $outcome['continuation'] : null;
            $session->next_observation_at = $status === 'pending'
                ? min($session->expires_at, $finished + $outcome['retry_after_seconds'])
                : null;
            $session->save();
        });
    }

    private function fail(LocalBackupPresenceManualSession $session, string $reason): void
    {
        $session->status = 'error';
        $session->continuation = null;
        $session->next_observation_at = null;
        $session->result = [
            'status' => 'error',
            'reason' => $reason,
            'filename' => null,
            'observed_at_utc' => null,
            'evidence_type' => 'metadata_presence',
            'persistent_check_run' => false,
            'verified_success' => false,
        ];
        $session->save();
    }
}
