<?php

namespace App\Services\Notifications;

use App\Models\ExternalBackupCheckRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Explicit reconciliation of persisted F02 outcomes into delivery intents.
 * No transport calls, recipient addresses, secrets or file evidence are stored.
 */
final class ExternalBackupNotificationLedger
{
    public function reconcile(int $runId, string $channel, string $destinationKey, bool $singleDestination = false): ?int
    {
        if ($runId < 1 || ! in_array($channel, BackupMessageTemplateRenderer::CHANNELS, true)
            || preg_match('/\A[a-z0-9_-]{1,64}\z/', $destinationKey) !== 1) {
            throw new InvalidArgumentException('Invalid notification delivery identity.');
        }

        return DB::transaction(function () use ($runId, $channel, $destinationKey, $singleDestination): ?int {
            $run = ExternalBackupCheckRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $receipt = $run->receipt()->first();

            if ($run->status === 'success' && $run->reason === 'verified_file_receipt'
                && $receipt !== null && (int) $receipt->device_id === (int) $run->device_id) {
                $event = 'backup.success';
            } elseif ($run->status === 'failed' && $receipt === null) {
                $event = 'backup.failed';
            } else {
                return null;
            }

            $identity = [
                'source_type' => 'external-file',
                'source_id' => $runId,
                'event_type' => $event,
                'channel' => $channel,
                'destination_key' => $destinationKey,
            ];

            if ($singleDestination && DB::table('custom_backup_notification_deliveries')
                ->where('source_type', 'external-file')
                ->where('source_id', $runId)
                ->where('channel', $channel)
                ->where('destination_key', '<>', $destinationKey)
                ->exists()) {
                // A configuration rotation must not silently reroute an old intent.
                return null;
            }

            $table = DB::table('custom_backup_notification_deliveries');
            $existing = $table->where($identity)->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }

            return (int) DB::table('custom_backup_notification_deliveries')->insertGetId([
                ...$identity,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
