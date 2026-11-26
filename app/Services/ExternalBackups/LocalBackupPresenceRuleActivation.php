<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use DomainException;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal activation boundary. Only the dedicated service may change
 * activation; API authorization and deployment attestation remain separate.
 */
final class LocalBackupPresenceRuleActivation
{
    public function __construct(private readonly LocalBackupPresenceCriteriaValidator $validator) {}

    public function available(): bool
    {
        return LocalBackupPresenceSchedulePlanner::enabled()
            && config('local-backup-presence.dispatch_enabled', false) === true;
    }

    public function change(int $ruleId, bool $enabled): LocalBackupPresenceRule
    {
        if (config('rConfig.is_demo', false) || ($enabled && ! $this->available())) {
            throw new DomainException('Local backup activation is not approved.');
        }

        return DB::transaction(function () use ($ruleId, $enabled): LocalBackupPresenceRule {
            // Global admission lock first, then the existing rule -> task
            // ordering. The lock is retained even in an outer transaction.
            if (DB::table('local_backup_presence_activation_lock')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new DomainException('Local backup activation lock is unavailable.');
            }
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

            if ($enabled === $rule->enabled) {
                return $rule;
            }

            // Current reads, not a potentially older consistent-read snapshot.
            if ($rule->runs()->where('status', LocalBackupPresenceRun::PENDING)->lockForUpdate()->first(['id']) !== null) {
                throw new DomainException('Wait for pending local backup checks to finish before changing activation.');
            }

            if ($enabled) {
                $this->validator->validate($rule);
                $cron = implode(' ', $task->task_cron);
                $timezone = config('app.timezone', 'UTC');
                if (count($task->task_cron) !== 5 || ! CronExpression::isValidExpression($cron) || ! is_string($timezone)) {
                    throw new DomainException('Invalid local backup task schedule.');
                }
                try {
                    new DateTimeZone($timezone);
                } catch (Exception) {
                    throw new DomainException('Invalid local backup task timezone.');
                }

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

                $limit = config('local-backup-presence.max_rules_per_tick', 100);
                if (! is_int($limit) || $limit < 1 || $limit > 100) {
                    throw new InvalidArgumentException('Invalid local backup scheduler capacity.');
                }

                // The Nth enabled row exists exactly when capacity is full.
                // Keep a current locking read under the admission mutex,
                // including future first ticks, but excluding disabled drafts.
                if (LocalBackupPresenceRule::query()->where('enabled', true)
                    ->orderBy('id')->offset($limit - 1)->lockForUpdate()->first(['id']) !== null) {
                    throw new DomainException('Local backup active rule capacity reached.');
                }

                $next = CarbonImmutable::now('UTC')->startOfMinute()->addMinute()->format('Y-m-d H:i:s');
                $previous = $rule->getRawOriginal('activated_at_utc');
                $latest = $rule->runs()->orderByDesc('scheduled_at_utc')->lockForUpdate()->first(['scheduled_at_utc']);
                if (($previous !== null && $next < $previous)
                    || ($latest !== null && $next <= $latest->getRawOriginal('scheduled_at_utc'))) {
                    throw new DomainException('Local backup activation would overlap previous scheduling history.');
                }

                $rule->activated_at_utc = $next;
            }

            $rule->enabled = $enabled;
            $rule->scheduler_checked_minute_utc = null;
            $rule->scheduler_revision = null;
            $rule->save();
            $task->is_paused = $enabled ? 0 : 1;
            $task->task_desc = $enabled ? 'Local backup file presence check' : 'Local backup file presence check (inactive)';
            $task->save();
            DB::table('local_backup_presence_manual_sessions')->where('rule_id', $rule->id)->delete();

            return $rule;
        }, 3);
    }
}
