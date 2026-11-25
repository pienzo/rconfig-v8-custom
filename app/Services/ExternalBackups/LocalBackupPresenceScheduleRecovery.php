<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bounded recovery from an observed scheduler gap. A current fingerprint
 * cannot prove that a rule was never changed or paused while offline.
 */
final class LocalBackupPresenceScheduleRecovery
{
    private const WINDOW_MINUTES = 5;

    public function __construct(private readonly LocalBackupPresenceOccurrenceClaim $claimer) {}

    public function reserve(int $ruleId, CarbonImmutable $minute): void
    {
        if (! LocalBackupPresenceSchedulePlanner::enabled() || config('local-backup-presence.catchup_enabled', false) !== true) {
            return;
        }

        $minute = $minute->setTimezone('UTC');
        if ($minute->second !== 0 || $minute->microsecond !== 0 || $minute->greaterThan(CarbonImmutable::now('UTC')->startOfMinute())) {
            throw new DomainException('Invalid local backup scheduler recovery minute.');
        }

        DB::transaction(function () use ($ruleId, $minute): void {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();
            $instant = $minute->format('Y-m-d H:i:s');
            $activation = $rule->getRawOriginal('activated_at_utc');
            if (! $rule->enabled || ! is_string($activation) || $activation > $instant
                || $task->task_command !== LocalBackupPresenceOccurrenceClaim::TASK_COMMAND
                || (bool) $task->is_paused || (bool) $task->is_system || ! (bool) $task->task_devices
                || (bool) $task->task_categories || (bool) $task->task_tags
                || $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() !== [(int) $rule->device_id]
                || $task->category()->exists() || $task->tag()->exists()) {
                return;
            }

            $cron = implode(' ', $task->task_cron);
            $timezone = (string) config('app.timezone', 'UTC');
            if (count($task->task_cron) !== 5 || ! CronExpression::isValidExpression($cron)) {
                throw new DomainException('Invalid local backup task schedule.');
            }

            $ruleAttributes = $rule->getRawOriginal();
            unset($ruleAttributes['scheduler_checked_minute_utc'], $ruleAttributes['scheduler_revision']);
            $taskAttributes = $task->getRawOriginal();
            ksort($ruleAttributes, SORT_STRING);
            ksort($taskAttributes, SORT_STRING);
            $revision = hash('sha256', serialize([$ruleAttributes, $taskAttributes, $timezone]));
            $previous = $rule->getRawOriginal('scheduler_checked_minute_utc');
            $start = $minute;

            if ($previous !== null) {
                $checked = CarbonImmutable::parse($previous, 'UTC');
                if ($checked->greaterThan($minute)) {
                    Log::warning('Local backup presence scheduler clock moved backwards.', ['rule_id' => $ruleId]);

                    return;
                }

                if ($rule->getRawOriginal('scheduler_revision') === $revision) {
                    if ($checked->equalTo($minute)) {
                        return;
                    }

                    $start = $checked->addMinute();
                    $earliest = $minute->subMinutes(self::WINDOW_MINUTES - 1);
                    if ($start->lessThan($earliest)) {
                        Log::warning('Local backup presence catch-up window exceeded.', [
                            'rule_id' => $ruleId,
                            'omitted_from_utc' => $start->format('Y-m-d H:i:s'),
                            'omitted_through_utc' => $earliest->subMinute()->format('Y-m-d H:i:s'),
                        ]);
                        $start = $earliest;
                    }
                } else {
                    Log::warning('Local backup presence scheduler configuration changed; historical recovery skipped.', ['rule_id' => $ruleId]);
                }
            }

            $expression = new CronExpression($cron);
            for ($candidate = $start; $candidate->lessThanOrEqualTo($minute); $candidate = $candidate->addMinute()) {
                if ($candidate->format('Y-m-d H:i:s') >= $activation && $expression->isDue($candidate, $timezone)) {
                    $this->claimer->claim($ruleId, $candidate);
                }
            }

            // Do not touch updated_at: it belongs to the configuration fingerprint.
            DB::table('local_backup_presence_rules')->where('id', $ruleId)->update([
                'scheduler_checked_minute_utc' => $instant,
                'scheduler_revision' => $revision,
            ]);
        });
    }
}
