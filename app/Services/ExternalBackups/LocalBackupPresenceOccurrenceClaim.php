<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal, dormant occurrence reservation. No scheduler, IO or event dispatch.
 * Runtime gate deliberately has no environment binding until deployment review.
 */
final class LocalBackupPresenceOccurrenceClaim
{
    public const TASK_COMMAND = 'rconfig:check-local-backup-presence';

    public function __construct(private readonly ExternalBackupFilenameMatcher $matcher) {}

    public function claim(int $ruleId, CarbonImmutable $scheduledAt): LocalBackupPresenceRun
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true || config('rConfig.is_demo', false)) {
            throw new DomainException('Local backup presence runtime is not approved.');
        }

        $utc = $scheduledAt->setTimezone('UTC');
        if ($ruleId < 1 || $utc->getTimestamp() < 0 || $utc->year > 9998 || $utc->second !== 0 || $utc->microsecond !== 0) {
            throw new InvalidArgumentException('Invalid local backup rule or scheduled UTC minute.');
        }

        return DB::transaction(function () use ($ruleId, $utc): LocalBackupPresenceRun {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();
            $instant = $utc->format('Y-m-d H:i:s');
            $activation = $rule->getRawOriginal('activated_at_utc');

            if (! $rule->enabled || ! is_string($activation) || $instant < $activation
                || ! LocalBackupPresenceTaskLinkage::matches($rule, $task)) {
                throw new DomainException('Local backup rule or linked task is not eligible.');
            }

            $existing = $rule->runs()->where('scheduled_at_utc', $instant)->first();
            if ($existing !== null) {
                if ((int) $existing->device_id !== (int) $rule->device_id
                    || ! is_array($existing->criteria_snapshot)) {
                    throw new DomainException('Stored local backup occurrence has no valid criteria snapshot.');
                }

                return $existing;
            }

            $cron = implode(' ', $task->task_cron);
            $timezone = (string) config('app.timezone', 'UTC');
            if (count($task->task_cron) !== 5 || ! CronExpression::isValidExpression($cron)
                || ! (new CronExpression($cron))->isDue($utc, (new DateTimeZone($timezone))->getName())) {
                throw new DomainException('Local backup occurrence does not match its task schedule.');
            }

            $criteria = $this->criteria($rule, $cron, $timezone, $activation);
            $run = new LocalBackupPresenceRun([
                'rule_id' => $rule->id,
                'device_id' => $rule->device_id,
                'scheduled_at_utc' => $instant,
                'deadline_at_utc' => $utc->addSeconds($rule->deadline_seconds)->format('Y-m-d H:i:s'),
            ]);
            $run->status = LocalBackupPresenceRun::PENDING;
            $run->criteria_snapshot = $criteria;
            $run->save();

            return $run;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function criteria(LocalBackupPresenceRule $rule, string $cron, string $timezone, string $activation): array
    {
        (new LocalBackupPresenceCriteriaValidator($this->matcher))->validate($rule);

        return [
            'version' => 1,
            'task_id' => (int) $rule->task_id,
            'activated_at_utc' => $activation,
            'cron' => $cron,
            'timezone' => $timezone,
            'directory_alias' => $rule->directory_alias,
            'relative_directory' => $rule->relative_directory,
            'pattern_mode' => $rule->pattern_mode,
            'filename_pattern' => $rule->filename_pattern,
            'minimum_bytes' => $rule->minimum_bytes,
            'maximum_age_seconds' => $rule->maximum_age_seconds,
            'quiet_seconds' => $rule->quiet_seconds,
            'deadline_seconds' => $rule->deadline_seconds,
            'max_entries' => $rule->max_entries,
            'max_matches' => $rule->max_matches,
            'max_scan_millis' => $rule->max_scan_millis,
        ];
    }
}
