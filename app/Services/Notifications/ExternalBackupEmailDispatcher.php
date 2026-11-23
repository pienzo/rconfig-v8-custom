<?php

namespace App\Services\Notifications;

use App\Models\ExternalBackupCheckRun;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ExternalBackupEmailDispatcher
{
    public function __construct(
        private readonly ExternalBackupNotificationMessage $messages,
        private readonly ExternalBackupNotificationLedger $ledger,
        private readonly BackupNotificationDeliveryAttempt $attempts,
        private readonly SecureBackupEmailTransport $transport,
    ) {}

    public function dispatch(int $runId): string
    {
        if (config('custom-backup-notifications.enabled', false) !== true
            || config('rconfig-backup-email.enabled', false) !== true) {
            return 'skipped';
        }

        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Dispatch requires a committed terminal backup outcome.');
        }

        $run = ExternalBackupCheckRun::query()->findOrFail($runId);
        if (! in_array($run->status, ['success', 'failed'], true)) {
            return 'skipped';
        }

        $message = $this->messages->render($run, 'email');
        $prepared = $this->transport->prepare($message['title'], $message['body']);
        $deliveryId = $this->ledger->reconcile($runId, 'email', $prepared['destination']);

        if ($deliveryId === null) {
            return 'skipped';
        }

        return $this->attempts->attempt($deliveryId, $prepared['send']);
    }
}
