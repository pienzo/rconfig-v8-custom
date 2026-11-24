<?php

namespace App\Services\ExternalBackups;

use App\Models\ExternalBackupRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ExternalBackupRuleActivation
{
    public function available(): bool
    {
        if (config('rConfig.is_demo', false)
            || config('external-backups.finalization_approved', false) !== true
            || config('external-backups.runtime_enabled', false) !== true
            || config('external-backups.queue_enabled', false) !== true
            || config('external-backups.receipt_acceptance_enabled', false) !== true) {
            return false;
        }

        // Configuration presence only. The deployer must separately attest
        // worker mount, native helper, OS permissions and trusted finalization.
        $root = config('external-backups.intake_root');
        $binary = config('external-backups.safe_digest_binary');
        $encoded = config('external-backups.receipt_hmac_key');
        $limit = config('external-backups.max_digest_bytes');
        $key = is_string($encoded) && str_starts_with($encoded, 'base64:')
            ? base64_decode(substr($encoded, 7), true) : false;

        return is_string($root) && str_starts_with($root, '/') && $root !== '/'
            && is_string($binary) && str_starts_with($binary, '/')
            && $key !== false && strlen($key) === 32
            && is_int($limit) && $limit >= 1 && $limit <= 1073741824;
    }

    public function change(int $ruleId, bool $enabled): ExternalBackupRule
    {
        if (config('rConfig.is_demo', false) || ($enabled && ! $this->available())) {
            throw new DomainException('External backup activation is not approved or configured.');
        }

        return DB::transaction(function () use ($ruleId, $enabled): ExternalBackupRule {
            $rule = ExternalBackupRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $rule->task()->lockForUpdate()->firstOrFail();

            if ($task->task_command !== 'rconfig:check-external-backup'
                || $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() !== [(int) $rule->device_id]
                || (bool) $task->is_paused === $rule->enabled) {
                throw new DomainException('Linked external backup task is inconsistent.');
            }

            if ($enabled === $rule->enabled) {
                return $rule;
            }

            if ($rule->runs()->where('status', 'pending')->exists()) {
                throw new DomainException('Wait for pending backup checks to finish before changing activation.');
            }

            if ($enabled && ((int) $rule->minimum_bytes < 1
                || (int) $rule->minimum_bytes > (int) config('external-backups.max_digest_bytes'))) {
                throw new DomainException('Rule minimum size exceeds the configured verification limit.');
            }

            $rule->enabled = $enabled;
            if ($enabled) {
                // The first eligible scheduler tick is strictly in the future.
                $rule->activated_at_utc = CarbonImmutable::now('UTC')->startOfMinute()->addMinute()->format('Y-m-d H:i:s');
            }
            $rule->save();
            $task->is_paused = $enabled ? 0 : 1;
            $task->save();

            return $rule;
        }, 3);
    }
}
