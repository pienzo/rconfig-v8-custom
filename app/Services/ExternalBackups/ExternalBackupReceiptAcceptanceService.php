<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Transactional receipt + SUCCESS state machine, deliberately not yet wired
 * to jobs, HTTP or the scheduler. The acceptance flag defaults to OFF.
 */
final class ExternalBackupReceiptAcceptanceService
{
    public function __construct(private readonly ExternalBackupReceiptTokenFactory $tokens) {}

    /**
     * @param  array<string, mixed>  $descriptorEvidence  Private native-helper output, never HTTP data
     */
    public function accept(int $runId, CarbonImmutable $acceptedAt, string $filename, array $descriptorEvidence): ExternalBackupCheckRun
    {
        if (config('external-backups.receipt_acceptance_enabled', false) !== true) {
            throw new DomainException('F02 verified receipt acceptance is disabled.');
        }

        $instant = $acceptedAt->setTimezone('UTC');
        if ($runId < 1 || $instant->microsecond !== 0 || $instant->getTimestamp() < 0) {
            throw new InvalidArgumentException('Invalid F02 receipt time or run ID.');
        }

        return DB::transaction(function () use ($runId, $instant, $filename, $descriptorEvidence): ExternalBackupCheckRun {
            $run = ExternalBackupCheckRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $token = $this->tokens->fromDescriptorEvidence((int) $run->device_id, $filename, $descriptorEvidence);
            $receipt = $run->receipt()->first();

            if ($run->status === 'success') {
                if ($receipt === null || ! hash_equals((string) $receipt->verified_file_token, $token)
                    || $run->matched_filename !== $filename) {
                    throw new DomainException('Conflicting replay of a completed F02 run.');
                }

                return $run;
            }

            if ($run->status !== 'pending' || $receipt !== null) {
                throw new DomainException('Only pending unreceipted runs can be accepted.');
            }

            $rule = $run->rule()->with('task')->firstOrFail();
            if ($rule->enabled !== true || $rule->task === null || $rule->task->is_paused
                || $rule->task->task_command !== 'rconfig:check-external-backup') {
                throw new DomainException('F02 rule or task is not enabled for acceptance.');
            }

            if ($run->reason !== 'requires_safe_open' || $run->matched_filename !== $filename) {
                throw new DomainException('F02 candidate has not passed two-observation validation.');
            }

            $scheduled = $this->storedUtc($run->getRawOriginal('scheduled_at_utc'));
            $deadline = $this->storedUtc($run->getRawOriginal('deadline_at_utc'));
            $first = $this->storedUtc($run->getRawOriginal('first_observed_at_utc'));
            $last = $this->storedUtc($run->getRawOriginal('last_observed_at_utc'));

            if ($instant->lessThan($scheduled) || $instant->greaterThanOrEqualTo($deadline)
                || $instant->lessThan($last) || $last->lessThanOrEqualTo($first)
                || $last->getTimestamp() - $first->getTimestamp() < (int) $rule->quiet_seconds) {
                throw new DomainException('F02 receipt is outside its stable observation window.');
            }

            // Database unique(device_id, verified_file_token) remains decisive
            // when different workers race to claim the same physical file.
            if (ExternalBackupFileReceipt::query()
                ->where('device_id', $run->device_id)
                ->where('verified_file_token', $token)
                ->exists()) {
                throw new DomainException('F02 file was already consumed in a previous run.');
            }

            ExternalBackupFileReceipt::query()->create([
                'check_run_id' => $run->id,
                'device_id' => $run->device_id,
                'verified_file_token' => $token,
                'accepted_at_utc' => $instant->format('Y-m-d H:i:s'),
            ]);

            $run->status = 'success';
            $run->reason = 'verified_file_receipt';
            $run->last_observed_at_utc = $instant->format('Y-m-d H:i:s');
            $run->save();

            return $run->refresh();
        }, 3);
    }

    private function storedUtc(mixed $raw): CarbonImmutable
    {
        if (! is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $raw) !== 1) {
            throw new RuntimeException('Missing stored F02 UTC timestamp.');
        }

        $value = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC');
        if ($value === false || $value->format('Y-m-d H:i:s') !== $raw) {
            throw new RuntimeException('Invalid stored F02 UTC timestamp.');
        }

        return $value;
    }
}
