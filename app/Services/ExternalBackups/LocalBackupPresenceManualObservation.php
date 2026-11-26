<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal single metadata observation, not a scheduled occurrence or a receipt.
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
        return DB::transaction(function () use ($ruleId): array {
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
                $started = CarbonImmutable::now('UTC')->startOfSecond();
                $scan = $this->scanner->scanMapped(
                    $mapping,
                    $rule->directory_alias,
                    $rule->relative_directory,
                    $rule->filename_pattern,
                    $rule->pattern_mode,
                    $rule->max_entries,
                    $rule->max_matches,
                    $rule->max_scan_millis
                );
                $observed = CarbonImmutable::now('UTC')->startOfSecond();
                if ($observed->lessThan($started)) {
                    return $this->answer('error', 'observation_clock_moved_backwards', null, null);
                }
                $result = $this->policy->evaluate(
                    $scan,
                    $observed->getTimestamp(),
                    $rule->maximum_age_seconds,
                    $rule->minimum_bytes,
                    $rule->quiet_seconds
                );
            } catch (InvalidArgumentException|DomainException) {
                return $this->answer('error', 'invalid_configuration', null, null);
            } catch (RuntimeException) {
                return $this->answer('error', 'scan_failed', null, null);
            }

            return $this->answer($result['status'], $result['reason'], $result['filename'], $observed);
        });
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
