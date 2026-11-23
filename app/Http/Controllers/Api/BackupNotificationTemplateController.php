<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomBackupNotificationTemplate;
use App\Services\Notifications\BackupMessageTemplateRenderer;
use App\Services\Notifications\TelegramBotTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class BackupNotificationTemplateController extends Controller
{
    public function index(Request $request, BackupMessageTemplateRenderer $renderer): JsonResponse
    {
        $this->requireAdmin($request);
        $saved = CustomBackupNotificationTemplate::query()->get()->keyBy(
            static fn (CustomBackupNotificationTemplate $row): string => $row->channel . ':' . $row->event_type
        );

        $items = [];
        foreach (BackupMessageTemplateRenderer::CHANNELS as $channel) {
            foreach (BackupMessageTemplateRenderer::EVENTS as $event) {
                $row = $saved->get($channel . ':' . $event);
                $defaults = $renderer->defaults($channel, $event);
                $items[] = [
                    'channel' => $channel,
                    'event_type' => $event,
                    'title' => $row?->title ?? $defaults['title'],
                    'body' => $row?->body ?? $defaults['body'],
                    'customized' => $row !== null,
                    'updated_at' => $row?->updated_at,
                ];
            }
        }

        return response()->json([
            'templates' => $items,
            'variables' => BackupMessageTemplateRenderer::TOKENS,
            'delivery_connected' => false,
        ]);
    }

    public function save(Request $request, string $channel, string $event, BackupMessageTemplateRenderer $renderer): JsonResponse
    {
        $this->requireAdmin($request);
        $this->validateChoice($channel, $event);

        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $renderer->validate($input['title'], $input['body']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $template = CustomBackupNotificationTemplate::query()->updateOrCreate([
            'channel' => $channel,
            'event_type' => $event,
        ], [
            'title' => $input['title'],
            'body' => $input['body'],
            'updated_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['template' => $template->only(['channel', 'event_type', 'title', 'body', 'updated_at'])]);
    }

    public function reset(Request $request, string $channel, string $event): JsonResponse
    {
        $this->requireAdmin($request);
        $this->validateChoice($channel, $event);
        CustomBackupNotificationTemplate::query()
            ->where('channel', $channel)
            ->where('event_type', $event)->delete();

        return response()->json(['reset' => true]);
    }

    public function preview(Request $request, BackupMessageTemplateRenderer $renderer): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'channel' => ['required', 'in:email,telegram'],
            'event_type' => ['sometimes', 'in:backup.success,backup.failed'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $result = $renderer->render(
                $data['channel'], $data['title'], $data['body'],
                $this->previewValues($renderer, $data['event_type'] ?? 'backup.success')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['preview' => $result, 'synthetic_only' => true, 'sent' => false]);
    }

    public function testTelegram(
        Request $request,
        BackupMessageTemplateRenderer $renderer,
        TelegramBotTransport $transport
    ): JsonResponse {
        $this->requireAdmin($request);

        $data = $request->validate([
            'event_type' => ['required', 'in:backup.success,backup.failed'],
        ]);

        if (config('rconfig-telegram.enabled') !== true) {
            return response()->json(['message' => 'Telegram non è abilitato sul server.'], 503);
        }

        $event = $data['event_type'];
        $template = CustomBackupNotificationTemplate::query()
            ->where('channel', 'telegram')
            ->where('event_type', $event)
            ->first();
        $defaults = $renderer->defaults('telegram', $event);

        try {
            $rendered = $renderer->render(
                'telegram',
                $template?->title ?? $defaults['title'],
                $template?->body ?? $defaults['body'],
                $this->previewValues($renderer, $event)
            );
            $transport->send($rendered['formatted_text']);
        } catch (InvalidArgumentException|RuntimeException) {
            // No token-bearing URL or provider body is ever returned to HTTP clients.
            return response()->json(['message' => 'Invio Telegram non riuscito. Verificare la configurazione del server.'], 502);
        }

        return response()->json(['sent' => true, 'synthetic_only' => true]);
    }

    /** @return array<string, string> */
    private function previewValues(BackupMessageTemplateRenderer $renderer, string $event): array
    {
        $values = $renderer->syntheticPreviewValues();
        $values['backup.status'] = $event === 'backup.failed' ? 'FAILED' : 'SUCCESS';

        return $values;
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }

    private function validateChoice(string $channel, string $event): void
    {
        abort_unless(in_array($channel, BackupMessageTemplateRenderer::CHANNELS, true)
            && in_array($event, BackupMessageTemplateRenderer::EVENTS, true), 404);
    }
}
