<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal synchronous observation of an already claimed occurrence.
 * The mapping must be supplied by trusted operator configuration, never an
 * HTTP request. No queue, API, provider or activation wiring is present yet.
 */
final class LocalBackupPresenceObserver
{
    public function __construct(
        private readonly ExternalBackupDirectoryScanner $scanner,
        private readonly LocalBackupPresenceStabilityPolicy $policy,
        private readonly LocalBackupPresenceRunState $state
    ) {}

    public function observe(int $ruleId, int $runId, LocalBackupDirectoryMap $mapping): LocalBackupPresenceRun
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true || config('rConfig.is_demo', false)) {
            throw new DomainException('Local backup presence runtime is not approved.');
        }

        if ($ruleId < 1 || $runId < 1) {
            throw new InvalidArgumentException('Invalid local backup observation identifiers.');
        }

        // Serialize the bounded scan and state update together. No automatic
        // transaction retry around filesystem IO; a worker can retry later.
        return DB::transaction(function () use ($ruleId, $runId, $mapping): LocalBackupPresenceRun {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();
            $run = $rule->runs()->whereKey($runId)->lockForUpdate()->firstOrFail();

            if ($run->status !== LocalBackupPresenceRun::PENDING) {
                return $run;
            }

            $criteria = $this->criteria($run, $rule);
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $scheduled = CarbonImmutable::parse($run->getRawOriginal('scheduled_at_utc'), 'UTC');
            $deadline = CarbonImmutable::parse($run->getRawOriginal('deadline_at_utc'), 'UTC');
            $lastRaw = $run->getRawOriginal('last_observed_at_utc');
            $last = $lastRaw === null ? null : CarbonImmutable::parse($lastRaw, 'UTC');

            if ($now->lessThan($scheduled) || ($last !== null && $now->lessThan($last))) {
                throw new DomainException('Local backup observation clock is outside its ordered window.');
            }

            if ($now->greaterThanOrEqualTo($deadline)) {
                return $this->state->expire($ruleId, $runId, $now);
            }

            if (! $rule->enabled || $rule->getRawOriginal('activated_at_utc') !== $criteria['activated_at_utc']
                || $task->task_command !== LocalBackupPresenceOccurrenceClaim::TASK_COMMAND || (bool) $task->is_paused
                || (bool) $task->is_system || ! (bool) $task->task_devices
                || (bool) $task->task_categories || (bool) $task->task_tags
                || $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() !== [(int) $rule->device_id]
                || $task->category()->exists() || $task->tag()->exists()) {
                throw new DomainException('Local backup task is not eligible for observation.');
            }

            if ($last !== null && $now->equalTo($last)) {
                return $run;
            }

            $baseline = $run->stability_baseline;
            $identity = $run->directory_identity;
            if ($baseline !== null && (! is_array($baseline) || ! is_int($baseline['observed_at'] ?? null)
                || ! is_array($baseline['scan'] ?? null) || $identity === null
                || $baseline['observed_at'] < $scheduled->getTimestamp()
                || $last === null || $baseline['observed_at'] > $last->getTimestamp())) {
                throw new DomainException('Stored local backup stability baseline is invalid.');
            }

            $observation = null;
            $observedAt = $now;
            try {
                $observation = $this->scanner->scanMappedObservation(
                    $mapping,
                    $criteria['directory_alias'],
                    $criteria['relative_directory'],
                    $criteria['filename_pattern'],
                    $criteria['pattern_mode'],
                    $criteria['max_entries'],
                    $criteria['max_matches'],
                    $criteria['max_scan_millis'],
                    $identity
                );
                $observedAt = CarbonImmutable::now('UTC')->startOfSecond();
                $result = $this->policy->evaluate(
                    $observation['scan'],
                    $observedAt->getTimestamp(),
                    $criteria['maximum_age_seconds'],
                    $criteria['minimum_bytes'],
                    $criteria['quiet_seconds'],
                    $baseline['scan'] ?? null,
                    $baseline['observed_at'] ?? null
                );
            } catch (LocalBackupDirectoryChanged) {
                $result = ['status' => 'error', 'reason' => 'directory_identity_changed', 'filename' => null];
            } catch (InvalidArgumentException) {
                $result = ['status' => 'error', 'reason' => 'invalid_configuration', 'filename' => null];
            } catch (RuntimeException) {
                $result = ['status' => 'error', 'reason' => 'scan_failed', 'filename' => null];
            }

            $finished = CarbonImmutable::now('UTC')->startOfSecond();
            if ($finished->lessThan($now)) {
                throw new DomainException('Local backup observation clock moved backwards.');
            }

            if ($finished->greaterThanOrEqualTo($deadline)) {
                return $this->state->expire($ruleId, $runId, $finished, true);
            }

            // State is written under the same transaction and lock order.
            // Use scan completion time, not the dispatch or claim timestamp.
            $at = $result['status'] === 'error' ? $finished : $observedAt;
            $updated = $this->state->record($ruleId, $runId, $at, $result);

            if ($updated->status === LocalBackupPresenceRun::PENDING && $observation !== null) {
                $updated->directory_identity = $observation['directory_identity'];
                if ($result['status'] === 'pending') {
                    // Preserve an identical earlier baseline on rapid retries.
                    // A changed match set starts a new conservative quiet window.
                    if ($baseline === null || $baseline['scan']['files'] !== $observation['scan']['files']) {
                        $updated->stability_baseline = [
                            'observed_at' => $at->getTimestamp(),
                            'scan' => $observation['scan'],
                        ];
                    }
                } else {
                    $updated->stability_baseline = null;
                }
                $updated->save();
            }

            return $updated->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function criteria(LocalBackupPresenceRun $run, LocalBackupPresenceRule $rule): array
    {
        $criteria = $run->criteria_snapshot;
        if (! is_array($criteria) || ($criteria['version'] ?? null) !== 1
            || ($criteria['task_id'] ?? null) !== (int) $rule->task_id
            || (int) $run->device_id !== (int) $rule->device_id) {
            throw new DomainException('Stored local backup observation criteria are invalid.');
        }

        foreach (['directory_alias', 'relative_directory', 'filename_pattern', 'pattern_mode', 'activated_at_utc'] as $field) {
            if (! is_string($criteria[$field] ?? null)) {
                throw new DomainException('Stored local backup observation criteria are invalid.');
            }
        }

        foreach (['minimum_bytes', 'max_entries', 'max_matches', 'max_scan_millis'] as $field) {
            if (! is_int($criteria[$field] ?? null)) {
                throw new DomainException('Stored local backup observation criteria are invalid.');
            }
        }

        foreach (['maximum_age_seconds', 'quiet_seconds'] as $field) {
            if (! array_key_exists($field, $criteria) || ($criteria[$field] !== null && ! is_int($criteria[$field]))) {
                throw new DomainException('Stored local backup observation criteria are invalid.');
            }
        }

        return $criteria;
    }
}
