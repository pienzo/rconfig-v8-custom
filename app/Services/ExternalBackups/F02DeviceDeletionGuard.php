<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupRule;
use App\Models\LocalBackupPresenceRule;

/**
 * A device referenced by either F02 ledger must not enter Core file cleanup.
 * The API caller holds device row locks before checking the relationship.
 */
final class F02DeviceDeletionGuard
{
    public const MESSAGE = 'Remove the linked F02 rules before deleting this device.';

    /** @param  list<int>  $deviceIds */
    public static function blocks(array $deviceIds): bool
    {
        if ($deviceIds === []) {
            return false;
        }

        // Locking reads use current committed state, even inside a transaction
        // whose consistent-read snapshot was established earlier.
        return ExternalBackupRule::query()->whereIn('device_id', $deviceIds)->lockForUpdate()->first(['id']) !== null
            || LocalBackupPresenceRule::query()->whereIn('device_id', $deviceIds)->lockForUpdate()->first(['id']) !== null;
    }
}
