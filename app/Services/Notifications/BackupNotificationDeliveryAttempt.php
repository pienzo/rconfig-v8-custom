<?php

namespace App\Services\Notifications;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Internal delivery boundary; only a trusted, prevalidated dispatcher may call it.
 * A committed claim is never automatically retried, even after worker loss.
 */
final class BackupNotificationDeliveryAttempt
{
    /**
     * @param  Closure(): void  $send  Synchronous, single provider attempt; no automatic retries.
     */
    public function attempt(int $deliveryId, Closure $send): string
    {
        if (config('custom-backup-notifications.enabled', false) !== true) {
            return 'skipped';
        }

        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Notification delivery requires a committed database claim.');
        }

        $claimed = $connection->table('custom_backup_notification_deliveries')
            ->where('id', $deliveryId)
            ->where('status', 'pending')
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed !== 1) {
            return 'skipped';
        }

        // Never hold a database transaction open across a network call.
        // If the process dies here, the sending row requires manual review.
        try {
            $send();
            $status = 'accepted';
        } catch (Throwable) {
            // The remote provider may have accepted the message before a
            // timeout or lost response. Do not retry or persist raw errors.
            $status = 'uncertain';
        }

        $updated = $connection->table('custom_backup_notification_deliveries')
            ->where('id', $deliveryId)
            ->where('status', 'sending')
            ->update(['status' => $status, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new LogicException('Notification delivery claim changed during the attempt.');
        }

        return $status;
    }
}
