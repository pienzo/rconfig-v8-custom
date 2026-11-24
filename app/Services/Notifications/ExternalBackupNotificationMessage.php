<?php

namespace App\Services\Notifications;

use App\Models\CustomBackupNotificationTemplate;
use App\Models\ExternalBackupCheckRun;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ExternalBackupNotificationMessage
{
    public function __construct(private readonly BackupMessageTemplateRenderer $renderer) {}

    /**
     * @return array{title: string, body: string, formatted_text: string}
     */
    public function render(ExternalBackupCheckRun $run, string $channel): array
    {
        ['event' => $event, 'values' => $values] = $this->metadata($run);
        $stored = CustomBackupNotificationTemplate::query()
            ->where('channel', $channel)->where('event_type', $event)->first();
        $template = $stored === null
            ? $this->renderer->defaults($channel, $event)
            : ['title' => (string) $stored->title, 'body' => (string) $stored->body];

        return $this->renderer->render($channel, $template['title'], $template['body'], $values);
    }

    /**
     * @return array{event: string, values: array<string, scalar|null>}
     */
    public function metadata(ExternalBackupCheckRun $run): array
    {
        $outcome = ExternalBackupTerminalOutcome::query()->where('runs.id', $run->id)
            ->first(['runs.status', 'runs.last_observed_at_utc']);
        if ($outcome === null) {
            throw new DomainException('No consistent terminal F02 outcome to render.');
        }

        $event = $outcome->status === 'success' ? 'backup.success' : 'backup.failed';
        $instant = $outcome->last_observed_at_utc;

        if (! is_string($instant) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $instant) !== 1) {
            throw new DomainException('Missing terminal F02 timestamp.');
        }

        $device = $run->device()->first(['id', 'device_name']);
        $taskId = $run->rule()->value('task_id');
        $taskName = DB::table('tasks')->where('id', $taskId)->value('task_name');
        // Only explicitly selected metadata; no device IP, credentials,
        // configuration contents, filesystem paths, digest or raw exceptions.
        $values = [
            'device.name' => $this->safeText($device?->device_name),
            'backup.status' => strtoupper((string) $outcome->status),
            'backup.source' => 'external-file',
            'backup.filename' => $this->safeFilename($run->matched_filename),
            'backup.timestamp' => $instant . ' UTC',
            'backup.error' => $event === 'backup.failed' ? 'External backup check failed' : null,
            'task.name' => $this->safeText($taskName),
            'task.id' => is_numeric($taskId) ? (string) $taskId : null,
            'event.id' => 'external-file:' . $run->id,
            'event.timestamp' => $instant . ' UTC',
        ];

        return ['event' => $event, 'values' => $values];
    }

    private function safeFilename(mixed $value): ?string
    {
        if (! is_string($value) || in_array($value, ['', '.', '..'], true)
            || str_contains($value, '/') || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) !== 0) {
            return null;
        }

        return $this->safeText($value);
    }

    private function safeText(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '', 0, 255);
    }
}
