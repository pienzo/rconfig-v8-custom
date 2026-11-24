<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class WhatsAppNotificationActivation
{
    public function cutoff(string $sourceCutoff): ?string
    {
        if (config('rconfig-whatsapp.enabled', false) !== true
            || config('rconfig-whatsapp.policy_approved', false) !== true) {
            return null;
        }

        $raw = config('rconfig-whatsapp.from_utc');
        if (! is_string($raw) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $raw) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $date && $date->format('Y-m-d H:i:s') === $raw ? max($sourceCutoff, $raw) : null;
    }
}
