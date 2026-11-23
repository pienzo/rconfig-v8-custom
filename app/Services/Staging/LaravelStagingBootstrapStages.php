<?php

namespace App\Services\Staging;

use Illuminate\Support\Facades\Artisan;

/**
 * Concrete Core/Laravel adapter, intentionally NOT bound to a CLI/Compose role.
 *
 * The separate operator entrypoint, source-image attestation, isolated egress,
 * consistent backup/restore and crash tests remain future reviewed gates.
 */
final class LaravelStagingBootstrapStages implements StagingBootstrapStages
{
    public function __construct(
        private readonly StagingBootstrapStateService $state,
        private readonly SealDefaultAdminService $sealer,
        private readonly StagingBootstrapRuntimeGate $gate,
    ) {}

    public function fresh(): bool
    {
        return $this->gate->permitted() && $this->state->isFresh();
    }

    public function migrate(): bool
    {
        if (! $this->gate->permitted()
            || ! $this->gate->attemptClaimed()
            || ! $this->state->isFresh()) {
            return false;
        }

        // Never use migrate:fresh, v8core:install, key:generate or a broad
        // installer. A nonzero exit/exception must withhold the final marker.
        $status = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $status === 0
            && $this->gate->permitted()
            && $this->gate->attemptClaimed();
    }

    public function migrated(): bool
    {
        return $this->gate->permitted() && $this->state->isMigrated();
    }

    public function seal(): bool
    {
        if (! $this->gate->permitted()
            || ! $this->gate->attemptClaimed()
            || ! $this->state->isMigrated()) {
            return false;
        }

        $password = $this->gate->replacementPassword();

        if ($password === null) {
            return false;
        }

        try {
            return $this->sealer->apply($password)
                && $this->gate->permitted()
                && $this->gate->attemptClaimed();
        } finally {
            unset($password);
        }
    }

    public function sealed(): bool
    {
        if (! $this->gate->permitted()) {
            return false;
        }

        $password = $this->gate->replacementPassword();

        if ($password === null) {
            return false;
        }

        try {
            return $this->state->isSealed($password);
        } finally {
            unset($password);
        }
    }

    public function postflight(): bool
    {
        // Recheck the security boundary and the actual DB state independently.
        // This does NOT certify Docker host egress or recovery readiness.
        return $this->gate->permitted()
            && $this->gate->attemptClaimed()
            && $this->gate->postflightReady()
            && $this->sealed()
            && ! $this->sealer->ready()
            && $this->gate->permitted();
    }
}
