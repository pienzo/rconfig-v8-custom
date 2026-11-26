<?php

namespace App\Jobs;

use App\Services\ExternalBackups\LocalBackupPresenceManualWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Only identifiers cross the queue; private evidence stays in the database. */
final class LocalBackupPresenceManualObserveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 2;

    public function __construct(
        public readonly int $ruleId,
        public readonly string $sessionId
    ) {
        $this->onConnection('redis_local_backup_presence');
        $this->onQueue('LocalBackupPresence');
    }

    public function handle(LocalBackupPresenceManualWorker $worker): void
    {
        $worker->process($this->ruleId, $this->sessionId);
    }
}
