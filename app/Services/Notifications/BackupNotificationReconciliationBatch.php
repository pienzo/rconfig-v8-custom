<?php

namespace App\Services\Notifications;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded round-robin scan of eligible outcomes. The cursor is only a
 * scheduling hint, never evidence of delivery or a permanent exclusion.
 */
final class BackupNotificationReconciliationBatch
{
    private const LIMIT = 100;

    /**
     * @return Collection<int, int>
     */
    public function next(Builder $query, string $source, string $channel, string $idColumn): Collection
    {
        $key = 'custom-backup-notification-scan:v1:' . $source . ':' . $channel;
        $state = Cache::get($key);
        if (! is_array($state) || ! is_int($state['after'] ?? null)
            || ! is_int($state['through'] ?? null)
            || $state['after'] < 0 || $state['through'] <= $state['after']) {
            // Freeze the upper bound so a continuous stream of new outcomes
            // cannot indefinitely postpone another pass over older rows.
            $state = ['after' => 0, 'through' => (int) (clone $query)->max($idColumn)];
        }

        $ids = (clone $query)
            ->where($idColumn, '>', $state['after'])
            ->where($idColumn, '<=', $state['through'])
            ->orderBy($idColumn)
            ->limit(self::LIMIT)
            ->pluck($idColumn)
            ->map(static fn ($id): int => (int) $id);

        if ($ids->count() < self::LIMIT || $ids->last() === $state['through']) {
            Cache::forget($key);
        } else {
            // A lost cache entry or interrupted enqueue restarts a scan or
            // delays candidates until the next cycle. The durable ledger
            // and worker eligibility checks still decide whether to send.
            Cache::put($key, ['after' => $ids->last(), 'through' => $state['through']], now()->addDays(7));
        }

        return $ids;
    }
}
