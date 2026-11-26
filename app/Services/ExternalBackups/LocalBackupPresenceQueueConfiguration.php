<?php

namespace App\Services\ExternalBackups;

/**
 * Pure supervisor configuration, also used while compiling Laravel's config.
 * No dispatch or environment-variable activation.
 */
final class LocalBackupPresenceQueueConfiguration
{
    /**
     * @param  array<string, array<string, mixed>>  $environments
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>
     */
    public static function withSupervisors(array $environments, array $settings): array
    {
        if (($settings['runtime_enabled'] ?? false) !== true || ($settings['queue_enabled'] ?? false) !== true) {
            return $environments;
        }

        foreach (array_keys($environments) as $name) {
            $environments[$name]['HorizonLocalBackupPresence'] = [
                'connection' => 'redis_local_backup_presence',
                'queue' => ['LocalBackupPresence'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'tries' => 2,
                'timeout' => 45,
            ];
        }

        return $environments;
    }
}
