<?php

namespace App\Jobs;

use App\Models\ExternalBackupCheckRun;
use App\Services\ExternalBackups\ExternalBackupRunStateService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExternalBackupExpirePendingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onConnection('redis_external_backups');
        $this->onQueue('ExternalBackupChecks');
    }

    public function handle(ExternalBackupRunStateService $states): void
    {
        if (! config('external-backups.runtime_enabled', false)) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        ExternalBackupCheckRun::query()
            ->where('status', 'pending')
            ->where('deadline_at_utc', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('deadline_at_utc')
            ->limit(100)
            ->get()
            ->each(static function (ExternalBackupCheckRun $run) use ($states, $now): void {
                try {
                    $states->expire($run->id, $now, 'deadline_elapsed', $run->matched_filename);
                } catch (DomainException) {
                    // A concurrent worker already finalized or rejected the run.
                }
            });
    }
}
