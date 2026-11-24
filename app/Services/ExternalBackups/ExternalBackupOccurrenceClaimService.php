<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Idempotently reserve a scheduled F02 check occurrence.
 *
 * Locks the authoritative rule row before reading or inserting its occurrence.
 * This does not process backup files, create receipts, or mark a run successful.
 */
final class ExternalBackupOccurrenceClaimService
{
    public function claim(int $ruleId, CarbonImmutable $scheduledAt): ExternalBackupCheckRun
    {
        $utc = $scheduledAt->setTimezone('UTC');

        if ($ruleId < 1 || $utc->getTimestamp() < 0 || $utc->microsecond !== 0) {
            throw new InvalidArgumentException('Invalid rule ID or UTC occurrence timestamp.');
        }

        return DB::transaction(function () use ($ruleId, $utc): ExternalBackupCheckRun {
            $rule = ExternalBackupRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();

            if (! $rule->enabled) {
                throw new DomainException('External backup rule is disabled.');
            }

            $instant = $utc->format('Y-m-d H:i:s');
            $activation = $rule->getRawOriginal('activated_at_utc');
            if ($activation !== null && $instant < $activation) {
                throw new DomainException('Occurrence predates the current rule activation.');
            }

            $existing = ExternalBackupCheckRun::query()
                ->where('rule_id', $rule->id)
                ->where('scheduled_at_utc', $instant)
                ->first();

            if ($existing !== null) {
                if ((int) $existing->device_id !== (int) $rule->device_id) {
                    throw new RuntimeException('Stored occurrence does not match its device.');
                }

                // Do not reset status, deadline or observations on a retry.
                return $existing;
            }

            $seconds = (int) $rule->deadline_seconds;

            if ($seconds < 1 || $seconds > 31 * 86400) {
                throw new DomainException('External backup deadline is outside the supported window.');
            }

            // Populate the pending state in memory as well as in SQL.
            // Eloquent create() does not hydrate the table's default value.
            return ExternalBackupCheckRun::create([
                'status' => 'pending',
                'rule_id' => $rule->id,
                'device_id' => $rule->device_id,
                'scheduled_at_utc' => $instant,
                'deadline_at_utc' => $utc->addSeconds($seconds)->format('Y-m-d H:i:s'),
            ]);
        }, 3);
    }
}
