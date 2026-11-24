<?php

namespace App\Services\Notifications;

use App\Models\CustomBackupNotificationTemplate;
use Illuminate\Support\Facades\DB;
use LogicException;

final class NativeBackupNotificationDispatcher
{
    public function __construct(
        private readonly NativeBackupNotificationReconciler $reconciler,
        private readonly BackupMessageTemplateRenderer $renderer,
        private readonly BackupNotificationDeliveryAttempt $attempts,
        private readonly SecureBackupEmailTransport $email,
        private readonly TelegramBotTransport $telegram,
    ) {}

    public function dispatch(int $outcomeId, string $channel): string
    {
        if ($this->reconciler->cutoff($channel) === null) {
            return 'skipped';
        }

        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Dispatch requires a committed native backup outcome.');
        }

        if (! $this->reconciler->eligible($outcomeId, $channel)) {
            return 'skipped';
        }

        $outcome = DB::table('custom_native_backup_outcomes')->where('id', $outcomeId)->first();
        if ($outcome === null || ! in_array($outcome->event_type, BackupMessageTemplateRenderer::EVENTS, true)) {
            return 'skipped';
        }

        $event = (string) $outcome->event_type;
        $instant = (string) $outcome->occurred_at_utc . ' UTC';
        $values = [
            'device.name' => (string) $outcome->device_name,
            'backup.status' => $event === 'backup.success' ? 'SUCCESS' : 'FAILED',
            'backup.source' => 'native-config',
            'backup.filename' => $outcome->filename,
            'backup.timestamp' => $instant,
            'backup.duration' => $outcome->duration_seconds === null ? null : $outcome->duration_seconds . 's',
            'backup.error' => $event === 'backup.failed' ? 'Native config download failed' : null,
            'event.id' => 'native-config:' . $outcomeId,
            'event.timestamp' => $instant,
        ];

        if ($channel === 'whatsapp') {
            $parameters = app(WhatsAppBackupTemplateParameters::class)->render($event, $values);
            $prepared = app(WhatsAppCloudTemplateTransport::class)->prepareAutomatic($event, $parameters);
            $destination = $prepared['destination'];
            $send = $prepared['send'];
        } else {
            $stored = CustomBackupNotificationTemplate::query()
                ->where('channel', $channel)->where('event_type', $event)->first();
            $template = $stored === null
                ? $this->renderer->defaults($channel, $event)
                : ['title' => (string) $stored->title, 'body' => (string) $stored->body];
            $message = $this->renderer->render($channel, $template['title'], $template['body'], $values);
            if ($channel === 'email') {
                $prepared = $this->email->prepare($message['title'], $message['body']);
                $destination = $prepared['destination'];
                $send = $prepared['send'];
            } else {
                $text = $message['formatted_text'];
                $destination = $this->telegram->automaticDestinationKey($text);
                $send = function () use ($text, $destination): void {
                    if (! hash_equals($destination, $this->telegram->automaticDestinationKey($text))) {
                        throw new LogicException('Telegram destination changed.');
                    }

                    $this->telegram->send($text);
                };
            }
        }

        $deliveryId = $this->intent($outcomeId, $event, $channel, $destination);
        if ($deliveryId === null) {
            return 'skipped';
        }

        return $this->attempts->attempt($deliveryId, $send);
    }

    private function intent(int $id, string $event, string $channel, string $destination): ?int
    {
        return DB::transaction(function () use ($id, $event, $channel, $destination): ?int {
            $outcome = DB::table('custom_native_backup_outcomes')->where('id', $id)->lockForUpdate()->first();
            if ($outcome === null || $outcome->event_type !== $event
                || ! $this->reconciler->eligible($id, $channel)) {
                return null;
            }

            $identity = [
                'source_type' => 'native-config',
                'source_id' => $id,
                'event_type' => $event,
                'channel' => $channel,
                'destination_key' => $destination,
            ];
            $existing = DB::table('custom_backup_notification_deliveries')
                ->where('source_type', 'native-config')->where('source_id', $id)->where('channel', $channel)->first();
            if ($existing !== null) {
                return $existing->destination_key === $destination && $existing->event_type === $event
                    ? (int) $existing->id : null;
            }

            return (int) DB::table('custom_backup_notification_deliveries')->insertGetId([
                ...$identity,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
