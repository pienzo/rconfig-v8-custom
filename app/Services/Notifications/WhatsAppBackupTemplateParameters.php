<?php

namespace App\Services\Notifications;

use InvalidArgumentException;
use RuntimeException;

/**
 * Ordered body-field mappings for Meta-approved positional templates.
 * No arbitrary text, header, recipient, API URL or template substitution.
 */
final class WhatsAppBackupTemplateParameters
{
    public const FIELDS = [
        'device.name', 'backup.status', 'backup.source',
        'backup.timestamp', 'event.id',
    ];

    /**
     * @param  array<string, scalar|null>  $values
     * @return list<string>
     */
    public function render(string $event, array $values): array
    {
        if (! in_array($event, BackupMessageTemplateRenderer::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported WhatsApp backup event.');
        }

        $mapping = config($event === 'backup.success'
            ? 'rconfig-whatsapp.body_fields_success'
            : 'rconfig-whatsapp.body_fields_failed');

        if (! is_string($mapping)) {
            throw new RuntimeException('Invalid WhatsApp approved-template mapping.');
        }
        $fields = trim($mapping) === '' ? [] : array_map('trim', explode(',', $mapping));
        if (count($fields) > 8) {
            throw new RuntimeException('Invalid WhatsApp approved-template mapping.');
        }

        $parameters = [];
        foreach ($fields as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new RuntimeException('Invalid WhatsApp approved-template field.');
            }

            $value = $values[$field] ?? null;
            if (! is_scalar($value) || trim($text = (string) $value) === ''
                || strlen($text) > 512 || preg_match('//u', $text) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $text) !== 0) {
                throw new RuntimeException('Missing or invalid WhatsApp approved-template value.');
            }

            $parameters[] = $text;
        }

        return $parameters;
    }
}
