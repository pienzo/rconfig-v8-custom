<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admission and expiry only. No scanner, queue or HTTP calls.
 * The same-rule lock serializes invalidation with rule edits and activation.
 */
final class LocalBackupPresenceManualSessions
{
    public const CAPACITY = 10;

    public function __construct(private readonly LocalBackupPresenceCriteriaValidator $validator) {}

    public function start(int $ruleId): LocalBackupPresenceManualSession
    {
        return $this->admit($ruleId, null);
    }

    /** Replace only the explicitly selected, unexpired terminal diagnostic. */
    public function restart(int $ruleId, string $expectedSessionId): LocalBackupPresenceManualSession
    {
        if (! Str::isUuid($expectedSessionId)) {
            throw new InvalidArgumentException('Invalid manual session identifier.');
        }

        return $this->admit($ruleId, $expectedSessionId);
    }

    private function admit(int $ruleId, ?string $expectedSessionId): LocalBackupPresenceManualSession
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true
            || config('local-backup-presence.manual_probe_enabled', false) !== true
            || config('rConfig.is_demo', false)) {
            throw new DomainException('Manual local backup observation is not approved.');
        }
        if ($ruleId < 1) {
            throw new InvalidArgumentException('Invalid local backup rule identifier.');
        }

        return DB::transaction(function () use ($ruleId, $expectedSessionId): LocalBackupPresenceManualSession {
            $this->lockAdmission();
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();
            if (! LocalBackupPresenceTaskLinkage::matches($rule, $task)) {
                throw new DomainException('Linked local backup task is inconsistent.');
            }
            $this->validator->validate($rule);
            $roots = config('local-backup-presence.roots', []);
            if (! is_array($roots)) {
                throw new InvalidArgumentException('Invalid local backup directory configuration.');
            }
            foreach ($roots as $alias => $root) {
                if (! is_string($alias) || ! is_string($root)) {
                    throw new InvalidArgumentException('Invalid local backup directory configuration.');
                }
            }
            (new LocalBackupDirectoryMap($roots))->validateMappedSelection($rule->directory_alias, $rule->relative_directory);

            $now = CarbonImmutable::now('UTC')->getTimestamp();
            if ($now < 0) {
                throw new DomainException('Invalid manual observation clock.');
            }
            // Expired rows are disposable diagnostics, not backup history.
            LocalBackupPresenceManualSession::query()->where('expires_at', '<=', $now)->delete();
            $existing = LocalBackupPresenceManualSession::query()->where('rule_id', $ruleId)->lockForUpdate()->first();
            if ($expectedSessionId !== null) {
                if ($existing === null || $existing->id !== $expectedSessionId
                    || ! in_array($existing->status, ['present', 'absent', 'error'], true)) {
                    throw new DomainException('Manual local backup session is no longer restartable.');
                }
                // The old UUID is never reused. Late jobs cannot observe the replacement.
                $existing->delete();
            } elseif ($existing !== null) {
                return $existing;
            }
            // Current locking read, not a repeatable-read snapshot.
            if (LocalBackupPresenceManualSession::query()->orderBy('id')
                ->offset(self::CAPACITY - 1)->lockForUpdate()->first(['id']) !== null) {
                throw new DomainException('Manual local backup session capacity reached.');
            }

            $session = new LocalBackupPresenceManualSession;
            $session->id = (string) Str::uuid();
            $session->rule_id = $ruleId;
            $session->requested_at = $now;
            $session->expires_at = $now + min($rule->deadline_seconds, 7200);
            $session->next_observation_at = $now;
            $session->status = 'queued';
            $session->save();

            return $session->refresh();
        }, 3);
    }

    /** Explicit bounded cleanup; a future scheduler will call this. */
    public function pruneExpired(): int
    {
        if (config('rConfig.is_demo', false)) {
            throw new DomainException('Manual local backup session cleanup is not approved.');
        }

        return DB::transaction(function (): int {
            $this->lockAdmission();
            $now = CarbonImmutable::now('UTC')->getTimestamp();

            return LocalBackupPresenceManualSession::query()->where('expires_at', '<=', $now)->delete();
        }, 3);
    }

    private function lockAdmission(): void
    {
        if (DB::table('local_backup_presence_activation_lock')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new DomainException('Local backup admission lock is unavailable.');
        }
    }
}
