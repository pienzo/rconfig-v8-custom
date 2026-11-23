<?php

namespace App\Services\Staging;

/**
 * Non-networked runtime proofs for a future operator-invoked bootstrap.
 * The real implementation is intentionally not bound to an Artisan command.
 */
interface StagingBootstrapRuntimeGate
{
    public function permitted(): bool;

    /** True only for the claimed persistent one-shot attempt (0700). */
    public function attemptClaimed(): bool;

    /** Return a private synthetic credential, or null; never log its contents. */
    public function replacementPassword(): ?string;

    /** Independent local storage/config postflight; not a host egress audit. */
    public function postflightReady(): bool;
}
