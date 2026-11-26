<?php

namespace App\Services\ExternalBackups;

use App\Jobs\LocalBackupPresenceManualObserveJob;
use App\Models\LocalBackupPresenceManualSession;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/** Bounded best-effort manual dispatch after scheduled reconciliation. */
final class LocalBackupPresenceManualReconciler
{
    private const BATCH_LIMIT = 2;

    private const QUEUE_HIGH_WATER = 10;

    private const COOLDOWN_SECONDS = 55;

    public function __construct(private readonly LocalBackupPresenceManualSessions $sessions) {}

    public static function enabled(): bool
    {
        return LocalBackupPresencePendingReconciler::enabled()
            && config('local-backup-presence.manual_probe_enabled', false) === true
            && config('local-backup-presence.manual_dispatch_enabled', false) === true;
    }

    public function enqueue(): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->sessions->pruneExpired();

        try {
            $size = Queue::connection('redis_local_backup_presence')->size('LocalBackupPresence');
        } catch (Exception) {
            Log::warning('Manual local backup observation queue unavailable.');

            return;
        }

        $budget = min(self::BATCH_LIMIT, self::QUEUE_HIGH_WATER - $size);
        if ($budget <= 0) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $eligible = LocalBackupPresenceManualSession::query()
            ->whereIn('status', ['queued', 'pending'])
            ->where('requested_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->where('next_observation_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('dispatch_not_before')->orWhere('dispatch_not_before', '<=', $now));
        $sessions = (clone $eligible)
            ->orderBy('next_observation_at')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit($budget)
            ->get(['id', 'rule_id']);

        foreach ($sessions as $session) {
            // No locks held over Redis IO. UUID and due predicates fence a
            // concurrent invalidation, completion or observation advance.
            $claimed = (clone $eligible)->whereKey($session->id)
                ->update(['dispatch_not_before' => $now + self::COOLDOWN_SECONDS]);
            if ($claimed !== 1) {
                continue;
            }

            try {
                Bus::dispatch(new LocalBackupPresenceManualObserveJob((int) $session->rule_id, $session->id));
            } catch (Exception) {
                Log::warning('Manual local backup observation dispatch unavailable.');

                return;
            }
        }
    }
}
