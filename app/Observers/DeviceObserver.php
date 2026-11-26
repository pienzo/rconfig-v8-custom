<?php

namespace App\Observers;

use App\Models\Config;
use App\Models\Device;
use App\Services\ExternalBackups\F02DeviceDeletionGuard;
use DomainException;

class DeviceObserver
{
    public function deleting(Device $device): void
    {
        // Foreign keys reject deletion only after this observer has run.
        // Protect config files from premature cleanup on ordinary model deletes.
        if (F02DeviceDeletionGuard::blocks([(int) $device->id])) {
            throw new DomainException(F02DeviceDeletionGuard::MESSAGE);
        }

        // Clean up configs and their change records tied to the device
        $configIds = Config::query()
            ->where('device_id', $device->id)
            ->pluck('id')
            ->all();

        if (empty($configIds)) {
            return;
        }

        // Delete configs in chunks to avoid memory issues
        foreach (array_chunk($configIds, 200) as $chunk) {
            Config::query()
                ->whereIn('id', $chunk)
                ->get()
                ->each->delete(); // per-model delete to trigger Config model events (file cleanup, summary updates)
        }
    }
}
