<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal transition boundary for trusted scanner policy results.
 * This service does not authenticate scanner evidence, scan files, schedule
 * work, or dispatch notifications. Never expose it as a public write API.
 */
final class LocalBackupPresenceRunState
{
    private const ABSENCE_REASONS = ['directory_missing', 'no_matching_file', 'no_eligible_file'];

    private const ERROR_REASONS = ['scan_failed', 'invalid_configuration', 'directory_identity_changed'];

    public function __construct(private readonly ExternalBackupFilenameMatcher $matcher) {}

    /** @param array{status: string, reason: string, filename: string|null} $result */
    public function record(int $ruleId, int $runId, CarbonImmutable $observedAt, array $result): LocalBackupPresenceRun
    {
        return $this->transition($ruleId, $runId, $observedAt, $result, false);
    }

    public function expire(int $ruleId, int $runId, CarbonImmutable $now): LocalBackupPresenceRun
    {
        return $this->transition($ruleId, $runId, $now, null, true);
    }

    /** @param array{status: string, reason: string, filename: string|null}|null $result */
    private function transition(int $ruleId, int $runId, CarbonImmutable $at, ?array $result, bool $expire): LocalBackupPresenceRun
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true || config('rConfig.is_demo', false)) {
            throw new DomainException('Local backup presence runtime is not approved.');
        }

        $utc = $at->setTimezone('UTC');
        if ($ruleId < 1 || $runId < 1 || $utc->getTimestamp() < 0 || $utc->year > 9998 || $utc->microsecond !== 0) {
            throw new InvalidArgumentException('Invalid local backup observation instant or identifier.');
        }

        return DB::transaction(function () use ($ruleId, $runId, $utc, $result, $expire): LocalBackupPresenceRun {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $run = $rule->runs()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $snapshot = $run->criteria_snapshot;
            if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
                || ($snapshot['task_id'] ?? null) !== (int) $rule->task_id
                || (int) $run->device_id !== (int) $rule->device_id
                || ! array_key_exists('quiet_seconds', $snapshot)
                || ! is_string($snapshot['filename_pattern'] ?? null)
                || ! is_string($snapshot['pattern_mode'] ?? null)) {
                throw new DomainException('Local backup occurrence criteria are invalid.');
            }

            $instant = $utc->format('Y-m-d H:i:s');
            $scheduled = $run->getRawOriginal('scheduled_at_utc');
            $deadline = $run->getRawOriginal('deadline_at_utc');
            $last = $run->getRawOriginal('last_observed_at_utc');
            if (! is_string($scheduled) || ! is_string($deadline) || $deadline <= $scheduled
                || $instant < $scheduled || ($last !== null && $instant < $last)) {
                throw new DomainException('Local backup observation is outside its ordered window.');
            }

            // Final rows are immutable through this service, including retries.
            if (in_array($run->status, [LocalBackupPresenceRun::PRESENT, LocalBackupPresenceRun::ABSENT, LocalBackupPresenceRun::ERROR], true)) {
                return $run;
            }
            if ($run->status !== LocalBackupPresenceRun::PENDING) {
                throw new DomainException('Invalid stored local backup status.');
            }

            if ($expire) {
                if ($instant < $deadline) {
                    throw new DomainException('Local backup deadline has not elapsed.');
                }

                // An unobserved occurrence is a monitoring error, not proof
                // that the backup was absent. Do not fabricate an observation.
                if ($last === null) {
                    $run->status = LocalBackupPresenceRun::ERROR;
                    $run->reason = 'no_observation_before_deadline';
                } elseif (in_array($run->reason, [...self::ABSENCE_REASONS, 'stability_wait'], true)) {
                    $run->status = LocalBackupPresenceRun::ABSENT;
                    $run->reason = $run->reason === 'stability_wait' ? 'stability_deadline_elapsed' : $run->reason;
                } else {
                    throw new DomainException('Invalid pending observation reason.');
                }
                $run->save();

                return $run->refresh();
            }

            if ($instant >= $deadline) {
                throw new DomainException('Late local backup evidence is not accepted; expire the occurrence instead.');
            }

            if (! $rule->enabled || $rule->getRawOriginal('activated_at_utc') !== ($snapshot['activated_at_utc'] ?? null)) {
                throw new DomainException('Local backup rule activation changed.');
            }

            if ($result === null) {
                throw new InvalidArgumentException('Missing local backup policy result.');
            }

            $this->validateResult($result, $snapshot);

            $status = in_array($result['status'], ['absent', 'pending'], true) ? LocalBackupPresenceRun::PENDING : $result['status'];
            if ($last === $instant) {
                if ($run->status !== $status || $run->reason !== $result['reason'] || $run->matched_filename !== $result['filename']) {
                    throw new DomainException('Conflicting local backup observation at the same instant.');
                }

                return $run;
            }

            $run->status = $status;
            $run->reason = $result['reason'];
            $run->matched_filename = $result['filename'];
            $run->last_observed_at_utc = $instant;
            if ($run->getRawOriginal('first_observed_at_utc') === null) {
                $run->first_observed_at_utc = $instant;
            }
            $run->save();

            return $run->refresh();
        }, 3);
    }

    /**
     * @param array{status: string, reason: string, filename: string|null} $result
     * @param array<string, mixed> $snapshot
     */
    private function validateResult(array $result, array $snapshot): void
    {
        if (count($result) !== 3 || ! isset($result['status'], $result['reason']) || ! array_key_exists('filename', $result)) {
            throw new InvalidArgumentException('Invalid local backup policy result.');
        }

        $filename = $result['filename'];
        $quiet = $snapshot['quiet_seconds'];
        if ($quiet !== null && (! is_int($quiet) || $quiet < 1 || $quiet > 3600)) {
            throw new DomainException('Invalid stored quiet interval.');
        }

        $valid = match ($result['status']) {
            'present' => $result['reason'] === ($quiet === null ? 'matching_file_observed' : 'stable_matching_file_observed') && is_string($filename),
            'pending' => $quiet !== null && $result['reason'] === 'stability_wait' && is_string($filename),
            'absent' => in_array($result['reason'], self::ABSENCE_REASONS, true) && $filename === null,
            'error' => in_array($result['reason'], self::ERROR_REASONS, true) && $filename === null,
            default => false,
        };

        if (! $valid || ($filename !== null && ! $this->matcher->matches($filename, $snapshot['filename_pattern'], $snapshot['pattern_mode']))) {
            throw new InvalidArgumentException('Local backup policy evidence is not allowed.');
        }
    }
}
