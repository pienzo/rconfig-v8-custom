<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupCheckRun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fail-closed metadata-only lifecycle for F02 runs.
 *
 * Intentionally supports ONLY pending observations and terminal failure.
 * It cannot accept a backup, set SUCCESS, create a file receipt or emit events.
 * Every update locks the occurrence row and preserves the original UTC window.
 */
final class ExternalBackupRunStateService
{
    private const PENDING_REASONS = [
        'missing', 'stale', 'undersized', 'unstable', 'invalid_timestamp',
        'requires_safe_open', 'access', 'unsafe_file', 'invalid_pattern',
    ];

    /**
     * Save a nonterminal observation within the scheduled UTC window.
     * Replaying the same observation is idempotent.
     */
    public function recordPending(int $runId, CarbonImmutable $observedAt, string $reason, ?string $filename = null): ExternalBackupCheckRun
    {
        return $this->transition($runId, $observedAt, $reason, $filename, false);
    }

    /**
     * Fail an uncompleted occurrence only when its deadline has elapsed.
     * A repeat expiration returns the original terminal row unchanged.
     */
    public function expire(int $runId, CarbonImmutable $observedAt, string $reason = 'deadline_elapsed', ?string $filename = null): ExternalBackupCheckRun
    {
        return $this->transition($runId, $observedAt, $reason, $filename, true);
    }

    private function transition(int $runId, CarbonImmutable $observedAt, string $reason, ?string $filename, bool $terminal): ExternalBackupCheckRun
    {
        $utc = $observedAt->setTimezone('UTC');

        if ($runId < 1 || $utc->getTimestamp() < 0 || $utc->microsecond !== 0) {
            throw new InvalidArgumentException('Invalid run identifier or UTC observation instant.');
        }

        $allowed = $terminal ? [...self::PENDING_REASONS, 'deadline_elapsed'] : self::PENDING_REASONS;
        if (! in_array($reason, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported external backup observation reason.');
        }

        if ($filename !== null && ! $this->safeBasename($filename)) {
            throw new InvalidArgumentException('Invalid external backup observation basename.');
        }

        return DB::transaction(function () use ($runId, $utc, $reason, $filename, $terminal): ExternalBackupCheckRun {
            $run = ExternalBackupCheckRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

            // Another component must never complete the receipt or success path.
            // A receipt that exists despite this incomplete workflow is a hard stop.
            if ($run->receipt()->exists()) {
                throw new DomainException('Run has a verified-file receipt and is not eligible for metadata transitions.');
            }

            if ($run->status === 'failed') {
                if ($terminal) {
                    return $run; // Never rewrite a previously final failure.
                }

                throw new DomainException('A terminal failure cannot become pending.');
            }

            if ($run->status !== 'pending') {
                throw new DomainException('Unsupported existing external backup run status.');
            }

            $scheduled = $this->utcFromSql($run->getRawOriginal('scheduled_at_utc'));
            $deadline = $this->utcFromSql($run->getRawOriginal('deadline_at_utc'));
            $last = $run->getRawOriginal('last_observed_at_utc');
            $lastAt = $last === null ? null : $this->utcFromSql($last);

            if ($deadline->lessThanOrEqualTo($scheduled) || $utc->lessThan($scheduled)) {
                throw new DomainException('Observation is outside the valid scheduled window.');
            }

            if ($terminal ? $utc->lessThan($deadline) : $utc->greaterThanOrEqualTo($deadline)) {
                throw new DomainException('Observation is on the wrong side of the deadline.');
            }

            if ($lastAt !== null && $utc->lessThan($lastAt)) {
                throw new DomainException('Out-of-order external backup observation.');
            }

            if (! $terminal && $lastAt !== null && $utc->equalTo($lastAt)) {
                if ($run->reason !== $reason || $run->matched_filename !== $filename) {
                    throw new DomainException('Conflicting external backup observation at the same instant.');
                }

                return $run; // No timestamp churn on retry.
            }

            $instant = $utc->format('Y-m-d H:i:s');
            $run->reason = $reason;
            $run->matched_filename = $filename;
            $run->last_observed_at_utc = $instant;

            if ($run->getRawOriginal('first_observed_at_utc') === null) {
                $run->first_observed_at_utc = $instant;
            }

            if ($terminal) {
                $run->status = 'failed';
            }

            $run->save();

            return $run->refresh();
        }, 3);
    }

    private function safeBasename(string $filename): bool
    {
        return $filename !== ''
            && $filename !== '.'
            && $filename !== '..'
            && strlen($filename) <= 255
            && ! str_contains($filename, '/')
            && ! str_contains($filename, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $filename) === 0
            && preg_match('//u', $filename) === 1;
    }

    private function utcFromSql(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1) {
            throw new RuntimeException('Invalid stored UTC occurrence timestamp.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('Invalid stored UTC occurrence timestamp.');
        }

        return $date;
    }
}
