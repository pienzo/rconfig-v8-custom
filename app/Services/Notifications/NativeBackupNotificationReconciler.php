<?php

namespace App\Services\Notifications;

use App\Jobs\NativeBackupNotificationJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class NativeBackupNotificationReconciler
{
    private const CHANNEL_FLAGS = [
        'email' => 'rconfig-backup-email.enabled',
        'telegram' => 'rconfig-telegram.enabled',
    ];

    public function cutoff(string $channel): ?string
    {
        if (config('rConfig.is_demo', false)
            || config('custom-backup-notifications.enabled', false) !== true
            || config('rconfig-native-backup-notifications.enabled', false) !== true
            || ! isset(self::CHANNEL_FLAGS[$channel])
            || config(self::CHANNEL_FLAGS[$channel], false) !== true) {
            return null;
        }

        $raw = config('rconfig-native-backup-notifications.from_utc');
        if (! is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $raw) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $date && $date->format('Y-m-d H:i:s') === $raw ? $raw : null;
    }

    private function candidates(string $channel, string $cutoff): Builder
    {
        return DB::table('custom_native_backup_outcomes as outcomes')
            ->where('outcomes.occurred_at_utc', '>=', $cutoff)
            ->where('outcomes.occurred_at_utc', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->whereIn('outcomes.event_type', BackupMessageTemplateRenderer::EVENTS)
            ->whereNotExists(function (Builder $query) use ($channel): void {
                $query->selectRaw('1')->from('custom_backup_notification_deliveries as deliveries')
                    ->where('deliveries.source_type', 'native-config')
                    ->whereColumn('deliveries.source_id', 'outcomes.id')
                    ->where('deliveries.channel', $channel)
                    ->where('deliveries.status', '<>', 'pending');
            });
    }

    public function eligible(int $outcomeId, string $channel): bool
    {
        $cutoff = $this->cutoff($channel);

        return $cutoff !== null && $this->candidates($channel, $cutoff)->where('outcomes.id', $outcomeId)->exists();
    }

    public function enqueue(): void
    {
        foreach (array_keys(self::CHANNEL_FLAGS) as $channel) {
            $cutoff = $this->cutoff($channel);
            if ($cutoff === null) {
                continue;
            }

            $ids = $this->candidates($channel, $cutoff)->orderBy('outcomes.id')->limit(100)->pluck('outcomes.id');
            foreach ($ids as $id) {
                NativeBackupNotificationJob::dispatch((int) $id, $channel);
            }
        }
    }
}
