<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reserve current-minute occurrences in the database before any queue handoff.
 * No filesystem IO, dispatch, historical catch-up or user-provided timestamps.
 */
final class LocalBackupPresenceSchedulePlanner
{
    public function __construct(private readonly LocalBackupPresenceOccurrenceClaim $claimer) {}

    public static function enabled(): bool
    {
        return config('local-backup-presence.runtime_enabled', false) === true
            && config('local-backup-presence.queue_enabled', false) === true
            && config('local-backup-presence.scheduler_enabled', false) === true
            && ! config('rConfig.is_demo', false);
    }

    public function reserveDue(): void
    {
        if (! self::enabled()) {
            return;
        }

        $limit = config('local-backup-presence.max_rules_per_tick', 100);
        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid local backup scheduler capacity.');
        }

        $minute = CarbonImmutable::now('UTC')->startOfMinute();
        $timezone = (string) config('app.timezone', 'UTC');
        $rules = LocalBackupPresenceRule::query()
            ->where('enabled', true)
            ->where('activated_at_utc', '<=', $minute->format('Y-m-d H:i:s'))
            ->whereHas('task', static fn ($query) => $query
                ->where('task_command', LocalBackupPresenceOccurrenceClaim::TASK_COMMAND)
                ->where('is_paused', false)
                ->where('is_system', false))
            ->with('task')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        if ($rules->count() > $limit) {
            Log::warning('Local backup presence scheduler capacity exceeded.', ['limit' => $limit]);

            return;
        }

        foreach ($rules as $rule) {
            try {
                $task = $rule->task;
                if ($task === null) {
                    continue;
                }

                $cron = implode(' ', $task->task_cron);
                if (count($task->task_cron) !== 5 || ! CronExpression::isValidExpression($cron)) {
                    throw new DomainException('Invalid local backup task schedule.');
                }

                if ((new CronExpression($cron))->isDue($minute, $timezone)) {
                    $this->claimer->claim($rule->id, $minute);
                }
            } catch (DomainException|InvalidArgumentException|ModelNotFoundException) {
                Log::warning('Local backup presence scheduler refused a rule.', ['rule_id' => $rule->id]);
            }
        }
    }
}
