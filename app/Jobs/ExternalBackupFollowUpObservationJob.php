<?php

namespace App\Jobs;

use App\Models\ExternalBackupCheckRun;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupObservationPolicy;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use App\Services\ExternalBackups\ExternalBackupSafeDigestAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use RuntimeException;

/**
 * F02 second/subsequent observation. Stable candidates remain pending until
 * trusted receipt acceptance exists; no SUCCESS can be written by this job.
 */
final class ExternalBackupFollowUpObservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<string, mixed> $previousScan */
    public function __construct(
        public readonly int $runId,
        public readonly array $previousScan,
        public readonly string $previousObservedAtUtc
    ) {
        $this->onConnection('redis_external_backups');
        $this->onQueue('ExternalBackupChecks');
    }

    public function handle(
        ExternalBackupDirectoryScanner $scanner,
        ExternalBackupObservationPolicy $policy,
        ExternalBackupRunStateService $runStates,
        ExternalBackupSafeDigestAdapter $digest
    ): void {
        if (! config('external-backups.runtime_enabled', false) || ! config('external-backups.queue_enabled', false)) {
            return;
        }

        $run = ExternalBackupCheckRun::with('rule.task')->find($this->runId);
        if ($run === null || $run->status !== 'pending' || ! $run->rule?->enabled
            || $run->rule->task === null || $run->rule->task->is_paused) {
            return;
        }

        $rule = $run->rule;
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $scheduled = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $run->getRawOriginal('scheduled_at_utc'), 'UTC');
        $deadline = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $run->getRawOriginal('deadline_at_utc'), 'UTC');
        if ($scheduled === false || $deadline === false) {
            throw new RuntimeException('Invalid stored F02 UTC occurrence window.');
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            $runStates->expire($run->id, $now, 'deadline_elapsed', $run->matched_filename);

            return;
        }

        $scan = ['directory_present' => false, 'scanned_entries' => 0, 'files' => []];
        $reason = 'access';
        $filename = null;
        $wait = true;
        try {
            $scan = $scanner->scan(
                (string) config('external-backups.intake_root', ''),
                (int) $rule->device_id,
                $rule->filename_pattern,
                $rule->pattern_mode,
                (int) $rule->max_entries,
                (int) $rule->max_matches,
                (int) $rule->max_scan_millis
            );

            $decision = $policy->evaluate(
                $this->previousScan,
                $scan,
                $scheduled->getTimestamp(),
                $deadline->getTimestamp(),
                CarbonImmutable::parse($this->previousObservedAtUtc, 'UTC')->getTimestamp(),
                $now->getTimestamp(),
                (int) $rule->quiet_seconds,
                (int) $rule->minimum_bytes,
                (int) $rule->clock_skew_seconds
            );
            $reason = $decision['reason'];
            $filename = $decision['filename'];

            if ($decision['status'] === ExternalBackupObservationPolicy::STATUS_CANDIDATE) {
                $candidate = collect($scan['files'])->firstWhere('filename', $filename);
                if (! is_array($candidate)) {
                    throw new RuntimeException('F02 scanner candidate disappeared.');
                }

                $digest->inspect($rule, $candidate);
                // Descriptor hash verified, but durable upload provenance and
                // unique receipt claims are still under review: no SUCCESS.
                $reason = 'requires_safe_open';
                $wait = false;
            }
        } catch (InvalidArgumentException|RuntimeException) {
            $reason = 'access';
        }

        $runStates->recordPending($run->id, $now, $reason, $filename);

        if ($wait && $now->addSeconds((int) $rule->quiet_seconds)->lessThan($deadline)) {
            $files = $scan['files'];
            usort($files, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['filename'], $b['filename']));
            $snapshot = [
                'directory_present' => $scan['directory_present'],
                'scanned_entries' => $scan['scanned_entries'],
                'files' => array_slice($files, 0, 1),
            ];

            self::dispatch($run->id, $snapshot, $now->format('Y-m-d H:i:s'))
                ->delay(now()->addSeconds((int) $rule->quiet_seconds));
        }
    }
}
