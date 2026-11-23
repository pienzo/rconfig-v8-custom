<?php

namespace App\Services\Notifications;

use App\Jobs\ExternalBackupNotificationJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ExternalBackupNotificationReconciler
{
    private const CHANNEL_FLAGS = [
        'email' => 'rconfig-backup-email.enabled',
        'telegram' => 'rconfig-telegram.enabled',
    ];

    public function cutoff(string $channel): ?string
    {
        if (config('rConfig.is_demo', false)
            || config('external-backups.runtime_enabled', false) !== true
            || config('external-backups.queue_enabled', false) !== true
            || config('custom-backup-notifications.enabled', false) !== true
            || ! isset(self::CHANNEL_FLAGS[$channel])
            || config(self::CHANNEL_FLAGS[$channel], false) !== true) {
            return null;
        }

        $raw = config('custom-backup-notifications.f02_from_utc');
        if (! is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $raw) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date && $date->format('Y-m-d H:i:s') === $raw ? $raw : null;
    }

    private function candidates(string $channel, string $cutoff): Builder
    {
        return DB::table('external_backup_check_runs as runs')
            ->where('runs.scheduled_at_utc', '>=', $cutoff)
            ->whereIn('runs.status', ['success', 'failed'])
            ->whereNotNull('runs.last_observed_at_utc')
            ->where('runs.last_observed_at_utc', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->whereNotExists(function (Builder $query) use ($channel): void {
                $query->selectRaw('1')->from('custom_backup_notification_deliveries as deliveries')
                    ->where('deliveries.source_type', 'external-file')
                    ->whereColumn('deliveries.source_id', 'runs.id')
                    ->where('deliveries.channel', $channel)
                    ->where('deliveries.status', '<>', 'pending');
            });
    }

    public function eligible(int $runId, string $channel): bool
    {
        $cutoff = $this->cutoff($channel);

        return $cutoff !== null && $this->candidates($channel, $cutoff)->where('runs.id', $runId)->exists();
    }

    public function enqueue(): void
    {
        foreach (array_keys(self::CHANNEL_FLAGS) as $channel) {
            $cutoff = $this->cutoff($channel);
            if ($cutoff === null) {
                continue;
            }

            // Limit each scheduler tick; the durable terminal rows are the
            // recovery source if Redis is unavailable or a worker disappears.
            $ids = $this->candidates($channel, $cutoff)->orderBy('runs.id')->limit(100)->pluck('runs.id');
            foreach ($ids as $id) {
                ExternalBackupNotificationJob::dispatch((int) $id, $channel);
            }
        }
    }
}
