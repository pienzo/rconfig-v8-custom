<?php

namespace App\Jobs;

use App\Services\Notifications\ExternalBackupEmailDispatcher;
use App\Services\Notifications\ExternalBackupNotificationReconciler;
use App\Services\Notifications\ExternalBackupTelegramDispatcher;
use App\Services\Notifications\ExternalBackupWhatsAppDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExternalBackupNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;
    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $runId, public readonly string $channel)
    {
        $this->onConnection('redis_backup_notifications');
        $this->onQueue('BackupNotifications');
    }

    public function uniqueId(): string
    {
        return $this->runId . ':' . $this->channel;
    }

    public function handle(
        ExternalBackupNotificationReconciler $reconciler,
        ExternalBackupEmailDispatcher $email,
        ExternalBackupTelegramDispatcher $telegram
    ): void {
        if (! $reconciler->eligible($this->runId, $this->channel)) {
            return;
        }

        try {
            match ($this->channel) {
                'email' => $email->dispatch($this->runId),
                'telegram' => $telegram->dispatch($this->runId),
                'whatsapp' => app(ExternalBackupWhatsAppDispatcher::class)->dispatch($this->runId),
                default => null,
            };
        } catch (Throwable) {
            // A later scheduler tick may retry preflight, but never a claimed
            // delivery. Do not store provider responses or recipient details.
            Log::warning('Backup notification dispatch could not complete.', [
                'run_id' => $this->runId,
                'channel' => $this->channel,
            ]);
        }
    }
}
