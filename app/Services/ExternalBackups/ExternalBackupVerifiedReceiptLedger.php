<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic F02 SUCCESS + receipt persistence, explicitly inert until an
 * independently reviewed trusted-finalization producer exists.
 *
 * The caller MUST supply an opaque, independently verified, non-reusable
 * receipt token from the finalized-upload trust boundary. This service
 * cannot establish upload provenance or safely mint such tokens itself.
 * No API, worker, scheduler or controller may call this without that gate.
 */
final class ExternalBackupVerifiedReceiptLedger
{
    public function accept(
        int $runId,
        CarbonImmutable $acceptedAt,
        string $filename,
        string $trustedVerifiedFileToken
    ): ExternalBackupCheckRun {
        if (config('external-backups.receipt_acceptance_enabled', false) !== true) {
            throw new DomainException('Trusted F02 file receipt acceptance is disabled.');
        }

        if ($runId < 1 || $acceptedAt->microsecond !== 0 || $acceptedAt->getTimestamp() < 0
            || preg_match('/\A[a-f0-9]{64}\z/D', $trustedVerifiedFileToken) !== 1
            || $filename === '' || $filename === '.' || $filename === '..'
            || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) !== 0 || preg_match('//u', $filename) !== 1) {
            throw new InvalidArgumentException('Invalid F02 verified receipt input.');
        }

        $utc = $acceptedAt->setTimezone('UTC');
        $time = $utc->format('Y-m-d H:i:s');

        try {
            return DB::transaction(function () use ($runId, $time, $filename, $trustedVerifiedFileToken): ExternalBackupCheckRun {
                $run = ExternalBackupCheckRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                $receipt = $run->receipt()->first();

                if ($run->status === 'success') {
                    if ($receipt !== null && hash_equals($receipt->verified_file_token, $trustedVerifiedFileToken)
                        && $run->matched_filename === $filename) {
                        return $run;
                    }

                    throw new DomainException('A completed F02 run cannot claim another receipt.');
                }

                if ($receipt !== null || $run->status !== 'pending') {
                    throw new DomainException('F02 run is not eligible for successful completion.');
                }

                // Never accept only a filename or a single observation as proof.
                if ($run->reason !== 'requires_safe_open' || $run->matched_filename !== $filename
                    || $run->first_observed_at_utc === null || $run->last_observed_at_utc === null) {
                    throw new DomainException('F02 run has no verified stable candidate.');
                }

                $start = $run->getRawOriginal('scheduled_at_utc');
                $deadline = $run->getRawOriginal('deadline_at_utc');
                $lastObserved = $run->getRawOriginal('last_observed_at_utc');
                if (! is_string($start) || ! is_string($deadline) || ! is_string($lastObserved)
                    || $time < $start || $time >= $deadline || $time < $lastObserved) {
                    throw new DomainException('F02 receipt is outside the scheduled window.');
                }

                ExternalBackupFileReceipt::query()->create([
                    'check_run_id' => $run->id,
                    'device_id' => $run->device_id,
                    'verified_file_token' => $trustedVerifiedFileToken,
                    'accepted_at_utc' => $time,
                ]);

                $run->status = 'success';
                $run->reason = null;
                $run->save();

                return $run->refresh();
            }, 3);
        } catch (QueryException) {
            // A concurrent receipt uniqueness claim is an expected refusal,
            // not permission to generate another identity or accept a retry.
            throw new RuntimeException('F02 trusted receipt has already been claimed or cannot be saved.');
        }
    }
}
