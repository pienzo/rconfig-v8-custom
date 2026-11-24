<?php

namespace App\Services\Notifications;

use App\Models\Config;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Capture a native per-config result at the actual Core persistence boundary.
 * Only SaveConfigsToDiskAndDb calls this service, not imports, model observers,
 * history updates or job completion hooks. No network or queue operations.
 */
final class NativeBackupOutcomeRecorder
{
    public function save(Config $config): bool
    {
        if ($config->exists || config('rConfig.is_demo', false)
            || config('custom-backup-notifications.enabled', false) !== true
            || config('rconfig-native-backup-notifications.enabled', false) !== true) {
            return $config->save();
        }

        return DB::transaction(function () use ($config): bool {
            if (! $config->save()) {
                return false;
            }

            $event = match ($config->download_status) {
                1 => 'backup.success',
                0 => 'backup.failed',
                default => null,
            };
            $filename = $this->safeFilename($config->config_filename);
            if ($event === null || (int) $config->device_id < 1
                || ($event === 'backup.success' && ($filename === null || (int) $config->config_filesize < 1))) {
                // An ambiguous result cannot become an authoritative event.
                return true;
            }

            DB::table('custom_native_backup_outcomes')->insert([
                'source_config_id' => $config->id,
                'device_id' => (int) $config->device_id,
                'device_name' => $this->safeName($config->device_name),
                'event_type' => $event,
                'filename' => $event === 'backup.success' ? $filename : null,
                'duration_seconds' => is_numeric($config->duration) && (int) $config->duration >= 0
                    && (int) $config->duration <= 4294967295 ? (int) $config->duration : null,
                'occurred_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    private function safeFilename(mixed $value): ?string
    {
        if (! is_string($value) || in_array($value, ['', '.', '..'], true)
            || strlen($value) > 255 || str_contains($value, '/') || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) !== 0 || preg_match('//u', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function safeName(mixed $value): string
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return 'Unknown device';
        }

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '', 0, 255);
    }
}
