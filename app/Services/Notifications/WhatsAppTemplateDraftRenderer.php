<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

final class WhatsAppTemplateDraftRenderer
{
    /**
     * Compile a local draft for manual provider approval, never for sending.
     *
     * @return array{title: string, provider_body: string, fields: list<string>, example_values: list<string>, preview: string}
     */
    public function compile(string $title, string $body, string $event): array
    {
        if (! in_array($event, BackupMessageTemplateRenderer::EVENTS, true)
            || trim($title) === '' || mb_strlen($title) > 60
            || trim($body) === '' || mb_strlen($body) > 1024
            || preg_match('//u', $title) !== 1 || preg_match('//u', $body) !== 1
            || preg_match('/[\x00-\x1F\x7F{}]/u', $title) !== 0
            || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/u', $body) !== 0) {
            throw new InvalidArgumentException('Invalid WhatsApp draft title or body.');
        }

        $pattern = '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/u';
        $fields = [];
        $samples = [];
        $renderer = new BackupMessageTemplateRenderer;
        $synthetic = $renderer->syntheticPreviewValues();
        $synthetic['backup.status'] = $event === 'backup.success' ? 'SUCCESS' : 'FAILED';
        $provider = preg_replace_callback($pattern, static function (array $match) use (&$fields, &$samples, $synthetic): string {
            if (! in_array($match[1], WhatsAppBackupTemplateParameters::FIELDS, true) || count($fields) >= 8) {
                throw new InvalidArgumentException('Unsupported WhatsApp draft variable or too many parameters.');
            }

            $fields[] = $match[1];
            $samples[] = $synthetic[$match[1]];

            return '{{' . count($fields) . '}}';
        }, $body);
        $remaining = preg_replace($pattern, '', $body);
        if ($provider === null || $remaining === null || str_contains($remaining, '{') || str_contains($remaining, '}')) {
            throw new InvalidArgumentException('Invalid WhatsApp draft placeholders.');
        }

        $preview = preg_replace_callback($pattern, static fn (array $match): string => $synthetic[$match[1]], $body);
        if ($preview === null) {
            throw new InvalidArgumentException('Invalid WhatsApp draft preview.');
        }

        return [
            'title' => $title,
            'provider_body' => $provider,
            'fields' => $fields,
            'example_values' => $samples,
            'preview' => $preview,
        ];
    }
}
