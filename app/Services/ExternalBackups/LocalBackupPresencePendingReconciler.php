<?php

namespace App\Services\ExternalBackups;

use App\Jobs\LocalBackupPresenceObserveJob;
use App\Models\LocalBackupPresenceRun;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Recover pending database occurrences into the isolated queue. Delivery
 * may repeat; only the trusted observer records actual evidence.
 */
final class LocalBackupPresencePendingReconciler
{
    private const BATCH_LIMIT = 100;

    private const QUEUE_HIGH_WATER = 100;

    private const COOLDOWN_SECONDS = 55;

    public static function enabled(): bool
    {
        return LocalBackupPresenceSchedulePlanner::enabled()
            && config('local-backup-presence.dispatch_enabled', false) === true;
    }

    public function enqueue(): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $size = Queue::connection('redis_local_backup_presence')->size('LocalBackupPresence');
        } catch (Exception) {
            Log::warning('Local backup presence queue unavailable.');

            return;
        }

        $budget = min(self::BATCH_LIMIT, self::QUEUE_HIGH_WATER - $size);
        if ($budget <= 0) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $instant = $now->format('Y-m-d H:i:s');
        $runs = LocalBackupPresenceRun::query()
            ->where('status', LocalBackupPresenceRun::PENDING)
            ->where('scheduled_at_utc', '<=', $instant)
            ->where(fn ($query) => $query->whereNull('dispatch_not_before_utc')->orWhere('dispatch_not_before_utc', '<=', $instant))
            ->where(fn ($query) => $query->where('deadline_at_utc', '<=', $instant)
                ->orWhereHas('rule', fn ($rule) => $rule->where('enabled', true)
                    ->where('activated_at_utc', '<=', $instant)
                    ->whereHas('task', fn ($task) => $task->where('task_command', LocalBackupPresenceOccurrenceClaim::TASK_COMMAND)
                        ->where('is_paused', false)
                        ->where('is_system', false))))
            ->orderByRaw('COALESCE(dispatch_not_before_utc, scheduled_at_utc)')
            ->orderBy('deadline_at_utc')
            ->orderBy('id')
            ->limit($budget)
            ->get(['id', 'rule_id']);

        foreach ($runs as $run) {
            // Atomic reservation, no database lock held during Redis IO.
            // If dispatch fails or the scheduler crashes, the cooldown expires.
            $claimed = DB::table('local_backup_presence_runs')
                ->where('id', $run->id)
                ->where('status', LocalBackupPresenceRun::PENDING)
                ->where('scheduled_at_utc', '<=', $instant)
                ->where(fn ($query) => $query->whereNull('dispatch_not_before_utc')->orWhere('dispatch_not_before_utc', '<=', $instant))
                ->update(['dispatch_not_before_utc' => $now->addSeconds(self::COOLDOWN_SECONDS)->format('Y-m-d H:i:s')]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                Bus::dispatch(new LocalBackupPresenceObserveJob((int) $run->rule_id, (int) $run->id));
            } catch (Exception) {
                Log::warning('Local backup presence dispatch unavailable.', ['run_id' => $run->id]);

                // Avoid flooding an unavailable transport. Other rows remain due.
                return;
            }
        }
    }
}
