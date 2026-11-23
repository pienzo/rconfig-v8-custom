<?php

namespace App\Jobs;

use App\Models\ExternalBackupRule;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupOccurrenceClaimService;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use RuntimeException;

/**
 * F02 scheduled occurrence: a metadata observation, never backup success.
 * Disabled by default; Core download command/queues are not used.
 */
final class ExternalBackupStartObservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly string $scheduledAtUtc
    ) {
        $this->onConnection('redis_external_backups');
        $this->onQueue('ExternalBackupChecks');
    }

    public function handle(
        ExternalBackupOccurrenceClaimService $claimer,
        ExternalBackupDirectoryScanner $scanner,
        ExternalBackupRunStateService $runStates
    ): void {
        if (! config('external-backups.runtime_enabled', false) || ! config('external-backups.queue_enabled', false)) {
            return;
        }

        $rule = ExternalBackupRule::with('task')->find($this->ruleId);
        if ($rule === null || ! $rule->enabled || $rule->task === null || $rule->task->is_paused) {
            return;
        }

        $scheduled = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->scheduledAtUtc, 'UTC');
        if ($scheduled === false || $scheduled->format('Y-m-d H:i:s') !== $this->scheduledAtUtc) {
            throw new InvalidArgumentException('Invalid F02 scheduled instant.');
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $run = $claimer->claim($this->ruleId, $scheduled);
        if ($run->status !== 'pending' || $run->last_observed_at_utc !== null) {
            return;
        }

        // MySQL datetime values are UTC by contract; never infer app timezone
        // from an Eloquent datetime cast (Core defaults to Europe/Dublin).
        $deadline = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $run->getRawOriginal('deadline_at_utc'), 'UTC');
        if ($deadline === false) {
            throw new RuntimeException('Invalid stored F02 deadline UTC.');
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            $runStates->expire($run->id, $now, 'deadline_elapsed');

            return;
        }

        $scan = ['directory_present' => false, 'scanned_entries' => 0, 'files' => []];
        $reason = 'access';
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
            $reason = $scan['files'] === [] ? 'missing' : 'unstable';
        } catch (InvalidArgumentException|RuntimeException) {
            // No file contents or private paths go to a log or queue exception.
        }

        $current = $this->latestOnly($scan);
        $filename = $current['files'][0]['filename'] ?? null;
        $runStates->recordPending($run->id, $now, $reason, $filename);

        ExternalBackupFollowUpObservationJob::dispatch($run->id, $current, $now->format('Y-m-d H:i:s'))
            ->delay(now()->addSeconds((int) $rule->quiet_seconds));
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function latestOnly(array $scan): array
    {
        $files = $scan['files'];
        usort($files, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['filename'], $b['filename']));

        return [
            'directory_present' => $scan['directory_present'],
            'scanned_entries' => $scan['scanned_entries'],
            'files' => array_slice($files, 0, 1),
        ];
    }
}
