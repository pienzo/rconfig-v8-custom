<?php

namespace App\Services\Notifications;

use App\Models\ExternalBackupCheckRun;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ExternalBackupWhatsAppDispatcher
{
    public function __construct(
        private readonly ExternalBackupNotificationReconciler $reconciler,
        private readonly ExternalBackupNotificationMessage $messages,
        private readonly ExternalBackupNotificationLedger $ledger,
        private readonly BackupNotificationDeliveryAttempt $attempts,
        private readonly WhatsAppBackupTemplateParameters $parameters,
        private readonly WhatsAppCloudTemplateTransport $transport,
    ) {}

    public function dispatch(int $runId): string
    {
        if ($this->reconciler->cutoff('whatsapp') === null) {
            return 'skipped';
        }

        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Dispatch requires a committed terminal backup outcome.');
        }

        if (! $this->reconciler->eligible($runId, 'whatsapp')) {
            return 'skipped';
        }

        $run = ExternalBackupCheckRun::query()->findOrFail($runId);
        ['event' => $event, 'values' => $values] = $this->messages->metadata($run);
        $prepared = $this->transport->prepareAutomatic($event, $this->parameters->render($event, $values));
        $id = $this->ledger->reconcile($runId, 'whatsapp', $prepared['destination'], singleDestination: true);

        if ($id === null) {
            return 'skipped';
        }

        return $this->attempts->attempt($id, $prepared['send']);
    }
}
