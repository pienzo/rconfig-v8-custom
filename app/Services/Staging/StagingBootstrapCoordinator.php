<?php

namespace App\Services\Staging;

/**
 * Dormant ordering/failure policy for a future operator-triggered bootstrap.
 *
 * This class has no command, container entrypoint, Compose profile or concrete
 * migration adapter. The caller MUST independently enforce synthetic identity,
 * the exact storage volume, network egress, a tested recovery checkpoint and
 * protected credentials before invoking it.
 */
final class StagingBootstrapCoordinator
{
    public function run(string $storage, StagingBootstrapStages $stages): bool
    {
        // First preflight is read-only. Do not claim persistent storage when
        // the schema/Redis is already populated or otherwise unexpected.
        if (! $stages->fresh()) {
            return false;
        }

        // This atomic directory is a never-reused attempt record. It is NOT
        // cleaned up after failure, a process crash or even after success.
        if (! (new StagingBootstrapAttemptGuard)->claim($storage)) {
            return false;
        }

        // Repeat freshness AFTER taking the persistent attempt claim to
        // narrow the gap between the host preflight and the first SQL DDL.
        // Any failed phase leaves the attempt record for manual recovery.
        if (! $stages->fresh()
            || ! $stages->migrate()
            || ! $stages->migrated()
            || ! $stages->seal()
            || ! $stages->sealed()
            || ! $stages->postflight()) {
            return false;
        }

        // Only this final stage may publish the success marker. It is
        // no-clobber and atomic on a single filesystem.
        return (new StagingBootstrapCompletionMarker)->publish($storage);
    }
}
