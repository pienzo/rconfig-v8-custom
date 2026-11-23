<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

/**
 * Non-executable text substitutions for backup notification templates.
 * Never render user-provided Blade, PHP, HTML or arbitrary object properties.
 */
final class BackupMessageTemplateRenderer
{
    public const TOKENS = [
        'device.name', 'device.ip', 'backup.status', 'backup.source',
        'backup.filename', 'backup.timestamp', 'backup.duration', 'backup.error',
        'task.name', 'task.id', 'event.id', 'event.timestamp',
    ];

    public const CHANNELS = ['email', 'telegram'];

    public const EVENTS = ['backup.success', 'backup.failed'];

    private const TOKEN_PATTERN = '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/u';

    public function validate(string $title, string $body): void
    {
        if ($title === '' || $body === '' || preg_match('/[\r\n]/', $title) === 1) {
            throw new InvalidArgumentException('Message titles must not contain line breaks, and both fields are required.');
        }

        foreach ([$title, $body] as $text) {
            preg_match_all(self::TOKEN_PATTERN, $text, $found);
            foreach ($found[1] as $token) {
                if (! in_array($token, self::TOKENS, true)) {
                    throw new InvalidArgumentException('Unknown notification template variable: ' . $token);
                }
            }

            $remaining = preg_replace(self::TOKEN_PATTERN, '', $text);
            if ($remaining === null || str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
                throw new InvalidArgumentException('Malformed notification template placeholder.');
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array{title: string, body: string, formatted_text: string}
     */
    public function render(string $channel, string $title, string $body, array $values): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unsupported notification template channel.');
        }

        $this->validate($title, $body);

        $replacement = static function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? '—';

            return is_scalar($value) ? (string) $value : '—';
        };

        $filledTitle = preg_replace_callback(self::TOKEN_PATTERN, $replacement, $title);
        $filledBody = preg_replace_callback(self::TOKEN_PATTERN, $replacement, $body);
        if ($filledTitle === null || $filledBody === null) {
            throw new InvalidArgumentException('Cannot render notification template.');
        }

        // Telegram Bot API HTML mode: escape the complete plain-text template,
        // then add only our own bold title markup. Email remains plain text.
        $formatted = $channel === 'telegram'
            ? '<b>' . htmlspecialchars($filledTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>' . "\n"
                . htmlspecialchars($filledBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $filledTitle . "\n" . $filledBody;

        return ['title' => $filledTitle, 'body' => $filledBody, 'formatted_text' => $formatted];
    }

    /** @return array{title: string, body: string} */
    public function defaults(string $channel, string $event): array
    {
        if (! in_array($channel, self::CHANNELS, true) || ! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported notification template.');
        }

        return $event === 'backup.success'
            ? [
                'title' => '✅ {{device.name}} — backup {{backup.status}}',
                'body' => "Dispositivo: {{device.name}}\nOrigine: {{backup.source}}\nFile: {{backup.filename}}\nQuando: {{backup.timestamp}}",
            ]
            : [
                'title' => '❌ {{device.name}} — backup {{backup.status}}',
                'body' => "Dispositivo: {{device.name}}\nOrigine: {{backup.source}}\nErrore: {{backup.error}}\nQuando: {{backup.timestamp}}",
            ];
    }

    /** @return array<string, string> */
    public function syntheticPreviewValues(): array
    {
        return [
            'device.name' => 'SW-DEMO-01',
            'device.ip' => '192.0.2.10',
            'backup.status' => 'SUCCESS',
            'backup.source' => 'external-file',
            'backup.filename' => 'synthetic_20261123.cfg',
            'backup.timestamp' => '2026-11-23 20:00 UTC',
            'backup.duration' => '12s',
            'backup.error' => 'File non ricevuto entro la scadenza',
            'task.name' => 'Backup demo giornaliero',
            'task.id' => '42',
            'event.id' => 'demo-event-0001',
            'event.timestamp' => '2026-11-23 20:00 UTC',
        ];
    }
}
