<?php

namespace App\Services\Notifications;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared persisted outcome boundary for reconciliation, rendering and intent
 * creation. A terminal label alone is not evidence of a valid outcome.
 */
final class ExternalBackupTerminalOutcome
{
    public static function query(): Builder
    {
        return DB::table('external_backup_check_runs as runs')
            ->where('runs.scheduled_at_utc', '>=', '1970-01-01 00:00:00')
            ->whereColumn('runs.deadline_at_utc', '>', 'runs.scheduled_at_utc')
            ->whereColumn('runs.first_observed_at_utc', '>=', 'runs.scheduled_at_utc')
            ->whereColumn('runs.last_observed_at_utc', '>=', 'runs.first_observed_at_utc')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $success): void {
                    $success->where('runs.status', 'success')
                        ->where('runs.reason', 'verified_file_receipt')
                        ->whereNotNull('runs.matched_filename')
                        ->whereNotIn('runs.matched_filename', ['', '.', '..'])
                        ->whereColumn('runs.last_observed_at_utc', '>', 'runs.first_observed_at_utc')
                        ->whereColumn('runs.last_observed_at_utc', '<', 'runs.deadline_at_utc')
                        ->whereExists(function (Builder $receipt): void {
                            $receipt->selectRaw('1')->from('external_backup_file_receipts as receipts')
                                ->whereColumn('receipts.check_run_id', 'runs.id')
                                ->whereColumn('receipts.device_id', 'runs.device_id')
                                ->whereColumn('receipts.accepted_at_utc', 'runs.last_observed_at_utc');
                        });
                })->orWhere(function (Builder $failed): void {
                    $failed->where('runs.status', 'failed')
                        ->whereIn('runs.reason', [
                            'missing', 'stale', 'undersized', 'unstable', 'invalid_timestamp',
                            'requires_safe_open', 'access', 'unsafe_file', 'invalid_pattern', 'deadline_elapsed',
                        ])
                        ->whereColumn('runs.last_observed_at_utc', '>=', 'runs.deadline_at_utc')
                        ->whereNotExists(function (Builder $receipt): void {
                            $receipt->selectRaw('1')->from('external_backup_file_receipts as receipts')
                                ->whereColumn('receipts.check_run_id', 'runs.id');
                        });
                });
            });
    }
}
