<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Internal metadata observation, not a scheduled occurrence or a receipt.
 * Do not call from HTTP: the worker mount and a bounded manual lifecycle
 * must be approved before exposing Verify now.
 */
final class LocalBackupPresenceManualObservation
{
    public function __construct(
        private readonly ExternalBackupDirectoryScanner $scanner,
        private readonly LocalBackupPresenceStabilityPolicy $policy,
        private readonly LocalBackupPresenceCriteriaValidator $validator
    ) {}

    /** @return array{status: string, reason: string, filename: string|null, observed_at_utc: string|null, evidence_type: string, persistent_check_run: bool, verified_success: bool} */
    public function observe(int $ruleId): array
    {
        return $this->probe($ruleId)['result'];
    }

    /**
     * Trusted worker-side continuation only. Never accept or serialize the
     * continuation through HTTP: it contains private scanner metadata.
     *
     * @param  array<string, mixed>|null  $continuation
     * @return array{result: array{status: string, reason: string, filename: string|null, observed_at_utc: string|null, evidence_type: string, persistent_check_run: bool, verified_success: bool}, continuation: array<string, mixed>|null, retry_after_seconds: int}
     */
    public function probe(int $ruleId, ?array $continuation = null): array
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true
            || config('local-backup-presence.manual_probe_enabled', false) !== true
            || config('rConfig.is_demo', false)) {
            throw new DomainException('Manual local backup observation is not approved.');
        }
        if ($ruleId < 1) {
            throw new InvalidArgumentException('Invalid local backup rule identifier.');
        }

        // Same rule -> task lock order as activation and scheduled observation.
        // No automatic retry around filesystem IO.
        return DB::transaction(function () use ($ruleId, $continuation): array {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();
            if ($task->task_command !== LocalBackupPresenceOccurrenceClaim::TASK_COMMAND
                || (bool) $task->is_paused === $rule->enabled
                || (bool) $task->is_system || ! (bool) $task->task_devices
                || (bool) $task->task_categories || (bool) $task->task_tags
                || $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() !== [(int) $rule->device_id]
                || $task->category()->exists() || $task->tag()->exists()
                || $task->task_email_notify || $task->download_report_notify || $task->verbose_download_report_notify
                || ($rule->enabled && $rule->activated_at_utc === null)) {
                throw new DomainException('Linked local backup task is inconsistent.');
            }

            try {
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
                $mapping = new LocalBackupDirectoryMap($roots);
                $mapping->validateMappedSelection($rule->directory_alias, $rule->relative_directory);
                // Ignore scheduler-only updates; bind all other persisted
                // rule fields, task state and the selected root to the probe.
                $raw = array_diff_key($rule->getRawOriginal(), array_flip([
                    'scheduler_checked_minute_utc', 'scheduler_revision', 'updated_at',
                ]));
                ksort($raw);
                $taskRaw = $task->getRawOriginal();
                ksort($taskRaw);
                $fingerprint = hash('sha256', json_encode([$raw, $taskRaw, $roots[$rule->directory_alias]], JSON_THROW_ON_ERROR));
                $started = CarbonImmutable::now('UTC')->startOfSecond()->getTimestamp();
                $window = min($rule->deadline_seconds, 7200);
                $deadline = $started + $window;
                $quiet = $rule->quiet_seconds;

                if ($continuation !== null) {
                    if ($quiet === null || ($continuation['version'] ?? null) !== 1
                        || ($continuation['rule_id'] ?? null) !== (int) $rule->id
                        || ($continuation['fingerprint'] ?? null) !== $fingerprint
                        || ! is_int($continuation['started_at'] ?? null)
                        || ! is_int($continuation['deadline_at'] ?? null)
                        || ! is_int($continuation['observed_at'] ?? null)
                        || ! is_string($continuation['directory_identity'] ?? null)
                        || ! is_array($continuation['scan'] ?? null)
                        || ! is_array($continuation['scan']['files'] ?? null)
                        || count($continuation['scan']['files']) > $rule->max_matches
                        || $continuation['started_at'] < 0
                        || $continuation['observed_at'] < $continuation['started_at']
                        || $continuation['observed_at'] >= $continuation['deadline_at']
                        || $window !== $continuation['deadline_at'] - $continuation['started_at']
                        || $started < $continuation['observed_at']) {
                        return $this->finished('error', 'manual_probe_changed_or_invalid');
                    }

                    $deadline = $continuation['deadline_at'];
                    if ($started >= $deadline) {
                        return $this->finished('error', 'manual_probe_expired');
                    }

                    $wait = $quiet - ($started - $continuation['observed_at']);
                    if ($wait > 0) {
                        return [
                            'result' => $this->answer('pending', 'stability_wait', null, CarbonImmutable::createFromTimestampUTC($continuation['observed_at'])),
                            'continuation' => $continuation,
                            'retry_after_seconds' => min($wait, $deadline - $started),
                        ];
                    }
                }

                $observation = $this->scanner->scanMappedObservation(
                    $mapping,
                    $rule->directory_alias,
                    $rule->relative_directory,
                    $rule->filename_pattern,
                    $rule->pattern_mode,
                    $rule->max_entries,
                    $rule->max_matches,
                    $rule->max_scan_millis,
                    $continuation['directory_identity'] ?? null
                );
                $observed = CarbonImmutable::now('UTC')->startOfSecond();
                $timestamp = $observed->getTimestamp();
                if ($timestamp < $started) {
                    return $this->finished('error', 'observation_clock_moved_backwards');
                }
                if ($timestamp >= $deadline) {
                    return $this->finished('error', 'manual_probe_expired');
                }
                $result = $this->policy->evaluate(
                    $observation['scan'],
                    $timestamp,
                    $rule->maximum_age_seconds,
                    $rule->minimum_bytes,
                    $quiet,
                    $continuation['scan'] ?? null,
                    $continuation['observed_at'] ?? null
                );
            } catch (LocalBackupDirectoryChanged) {
                return $this->finished('error', 'directory_identity_changed');
            } catch (InvalidArgumentException|DomainException|JsonException) {
                return $this->finished('error', 'invalid_configuration');
            } catch (RuntimeException) {
                return $this->finished('error', 'scan_failed');
            }

            $answer = $this->answer($result['status'], $result['reason'], $result['filename'], $observed);
            if ($result['status'] !== 'pending' || $quiet === null) {
                return ['result' => $answer, 'continuation' => null, 'retry_after_seconds' => 0];
            }

            // A changed or still-unstable match starts a new observation,
            // but never extends the absolute original expiry.
            return [
                'result' => $answer,
                'continuation' => [
                    'version' => 1,
                    'rule_id' => (int) $rule->id,
                    'fingerprint' => $fingerprint,
                    'started_at' => $continuation['started_at'] ?? $started,
                    'deadline_at' => $deadline,
                    'observed_at' => $timestamp,
                    'directory_identity' => $observation['directory_identity'],
                    'scan' => $observation['scan'],
                ],
                'retry_after_seconds' => min($quiet, $deadline - $timestamp),
            ];
        });
    }

    /** @return array{result: array{status: string, reason: string, filename: string|null, observed_at_utc: string|null, evidence_type: string, persistent_check_run: bool, verified_success: bool}, continuation: null, retry_after_seconds: int} */
    private function finished(string $status, string $reason): array
    {
        return ['result' => $this->answer($status, $reason, null, null), 'continuation' => null, 'retry_after_seconds' => 0];
    }

    /** @return array{status: string, reason: string, filename: string|null, observed_at_utc: string|null, evidence_type: string, persistent_check_run: bool, verified_success: bool} */
    private function answer(string $status, string $reason, ?string $filename, ?CarbonImmutable $observed): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'filename' => $filename,
            'observed_at_utc' => $observed?->format('Y-m-d\TH:i:s\Z'),
            'evidence_type' => 'metadata_presence',
            'persistent_check_run' => false,
            'verified_success' => false,
        ];
    }
}
