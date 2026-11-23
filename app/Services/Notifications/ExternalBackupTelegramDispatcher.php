<?php

namespace App\Services\Notifications;

use App\Models\ExternalBackupCheckRun;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Internal entry point for a trusted caller with an explicit occurrence ID.
 * No scheduling, historical backfill or HTTP route is introduced here.
 */
final class ExternalBackupTelegramDispatcher
{
    public function __construct(
        private readonly ExternalBackupNotificationMessage $messages,
        private readonly ExternalBackupNotificationLedger $ledger,
        private readonly BackupNotificationDeliveryAttempt $attempts,
        private readonly TelegramBotTransport $transport,
    ) {}

    public function dispatch(int $runId): string
    {
        if (config('custom-backup-notifications.enabled', false) !== true
            || config('rconfig-telegram.enabled', false) !== true) {
            return 'skipped';
        }

        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Dispatch requires a committed terminal backup outcome.');
        }

        $run = ExternalBackupCheckRun::query()->findOrFail($runId);
        if (! in_array($run->status, ['success', 'failed'], true)) {
            return 'skipped';
        }

        $message = $this->messages->render($run, 'telegram')['formatted_text'];
        $destination = $this->transport->automaticDestinationKey($message);
        $deliveryId = $this->ledger->reconcile($runId, 'telegram', $destination);

        if ($deliveryId === null) {
            return 'skipped';
        }

        return $this->attempts->attempt($deliveryId, function () use ($message, $destination): void {
            // Fail closed if the destination changed since preflight.
            if (! hash_equals($destination, $this->transport->automaticDestinationKey($message))) {
                throw new LogicException('Telegram destination changed.');
            }

            $this->transport->send($message);
        });
    }
}
