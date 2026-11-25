<?php

namespace App\Jobs;

use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\LocalBackupDirectoryMap;
use App\Services\ExternalBackups\LocalBackupPresenceObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * One observation of an existing occurrence. The queue never transports
 * filesystem paths or observations; the worker reads trusted configuration.
 */
final class LocalBackupPresenceObserveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 2;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $runId
    ) {
        $this->onConnection('redis_local_backup_presence');
        $this->onQueue('LocalBackupPresence');
    }

    public function handle(LocalBackupPresenceObserver $observer): void
    {
        if (config('local-backup-presence.runtime_enabled', false) !== true
            || config('local-backup-presence.queue_enabled', false) !== true
            || config('rConfig.is_demo', false)) {
            return;
        }

        if ($this->ruleId < 1 || $this->runId < 1) {
            throw new InvalidArgumentException('Invalid local backup observation identifiers.');
        }

        $run = LocalBackupPresenceRun::query()
            ->where('rule_id', $this->ruleId)
            ->whereKey($this->runId)
            ->first();

        if ($run === null || $run->status !== LocalBackupPresenceRun::PENDING) {
            return;
        }

        $roots = config('local-backup-presence.roots', []);
        if (! is_array($roots)) {
            throw new InvalidArgumentException('Invalid local backup worker directory configuration.');
        }

        foreach ($roots as $alias => $root) {
            if (! is_string($alias) || ! is_string($root)) {
                throw new InvalidArgumentException('Invalid local backup worker directory configuration.');
            }
        }

        $observer->observe($this->ruleId, $this->runId, new LocalBackupDirectoryMap($roots));
    }
}
