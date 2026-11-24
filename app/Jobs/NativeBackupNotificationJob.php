<?php

namespace App\Jobs;

use App\Services\Notifications\NativeBackupNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NativeBackupNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;
    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $outcomeId, public readonly string $channel)
    {
        $this->onConnection('redis_backup_notifications');
        $this->onQueue('BackupNotifications');
    }

    public function uniqueId(): string
    {
        return $this->outcomeId . ':' . $this->channel;
    }

    public function handle(NativeBackupNotificationDispatcher $dispatcher): void
    {
        try {
            $dispatcher->dispatch($this->outcomeId, $this->channel);
        } catch (Throwable) {
            Log::warning('Native backup notification dispatch could not complete.', [
                'outcome_id' => $this->outcomeId,
                'channel' => $this->channel,
            ]);
        }
    }
}
