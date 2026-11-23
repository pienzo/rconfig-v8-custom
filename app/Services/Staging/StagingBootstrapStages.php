<?php

namespace App\Services\Staging;

/**
 * Actions for a future, separately reviewed synthetic first-install adapter.
 *
 * No implementation or Laravel Artisan wiring is provided here. In
 * particular, migrate() and seal() must fail loudly rather than suppress
 * migration or admin-rotation failures.
 */
interface StagingBootstrapStages
{
    /** Verify a dedicated empty schema and both configured Redis databases. */
    public function fresh(): bool;

    /** Execute approved Core migrations; never run the integrated installer. */
    public function migrate(): bool;

    /** Verify all expected migrations and exact initial Core admin/SMTP seed. */
    public function migrated(): bool;

    /** Replace the seeded admin and neutralize SMTP before HTTP can run. */
    public function seal(): bool;

    /** Independently verify the new admin and disabled outbound SMTP. */
    public function sealed(): bool;

    /** Verify application, files, stable key, services and isolation. */
    public function postflight(): bool;
}
