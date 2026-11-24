<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomBackupNotificationTemplate;
use App\Services\Notifications\BackupMessageTemplateRenderer;
use App\Services\Notifications\WhatsAppBackupTemplateParameters;
use App\Services\Notifications\WhatsAppTemplateDraftRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WhatsAppTemplateDraftController extends Controller
{
    private const CHANNEL = 'whatsapp-draft';

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $saved = CustomBackupNotificationTemplate::query()
            ->where('channel', self::CHANNEL)->get()->keyBy('event_type');
        $drafts = [];
        foreach (BackupMessageTemplateRenderer::EVENTS as $event) {
            $row = $saved->get($event);
            $drafts[] = [
                'event_type' => $event,
                'title' => $row?->title ?? 'Esito backup',
                'body' => $row?->body ?? 'Backup {{backup.status}} per {{device.name}}. Evento: {{event.id}}.',
                'saved' => $row !== null,
            ];
        }

        return response()->json(['drafts' => $drafts, 'variables' => WhatsAppBackupTemplateParameters::FIELDS]);
    }

    public function preview(Request $request, WhatsAppTemplateDraftRenderer $renderer): JsonResponse
    {
        return $this->process($request, $renderer, false);
    }

    public function save(Request $request, WhatsAppTemplateDraftRenderer $renderer): JsonResponse
    {
        return $this->process($request, $renderer, true);
    }

    private function process(Request $request, WhatsAppTemplateDraftRenderer $renderer, bool $save): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'event_type' => ['required', 'in:backup.success,backup.failed'],
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $compiled = $renderer->compile($data['title'], $data['body'], $data['event_type']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($save) {
            CustomBackupNotificationTemplate::query()->updateOrCreate([
                'channel' => self::CHANNEL,
                'event_type' => $data['event_type'],
            ], [
                'title' => $data['title'],
                'body' => $data['body'],
                'updated_by_user_id' => $request->user()->id,
            ]);
        }

        return response()->json(['draft' => $compiled, 'saved' => $save, 'sent' => false, 'approved' => false]);
    }
}
