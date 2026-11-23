<?php

namespace App\Console\Commands;

use App\Services\Staging\SealDefaultAdminService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Explicit one-shot staging action, never called by web/worker entrypoints.
 */
final class StagingSealDefaultAdminCommand extends Command
{
    protected $signature = 'staging:seal-default-admin
                            {--check : Validate the exact initial seed without writes}
                            {--apply : Replace default admin password and neutralize SMTP settings once}';

    protected $description = 'Synthetic staging only: seal the exact seeded Core admin after explicit migrations';

    public function handle(SealDefaultAdminService $sealer): int
    {
        $check = (bool) $this->option('check');
        $apply = (bool) $this->option('apply');

        if ($check === $apply) {
            return $this->refuse('specify exactly one of --check or --apply');
        }

        // This gate cannot be satisfied by the ordinary integrated Core
        // entrypoint. Only a separately reviewed bootstrap-only container may
        // supply it; production web/worker processes must never receive it.
        if (PHP_SAPI !== 'cli'
            || getenv('IS_DOCKER') !== 'true'
            || getenv('RCONFIG_STAGING_BOOTSTRAP') !== 'core-synthetic-v1'
            || config('app.env') !== 'production'
            || config('app.debug') !== false
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== 'db'
            || config('database.connections.mysql.database') !== 'rconfig_staging'
            || config('database.connections.mysql.username') !== 'rconfig_staging'
            || config('database.redis.default.host') !== 'redis'
            || config('rConfig.f02_queue_enabled', true) !== false) {
            return $this->refuse('synthetic bootstrap-only environment gate failed');
        }

        // Fixed path; no password accepted as a CLI argument or environment
        // value. A future one-shot container mounts this *individual* file,
        // never the whole host secrets directory.
        $file = '/run/secrets/staging_admin_password';
        clearstatcache(true, $file);

        if (! is_file($file) || is_link($file)
            || fileowner($file) !== 0 || filegroup($file) !== 33
            || (fileperms($file) & 0777) !== 0640) {
            return $this->refuse('protected administrator secret unavailable');
        }

        $raw = file_get_contents($file);
        if (! is_string($raw)
            || preg_match('/\A[0-9a-fA-F]{64}\n\z/D', $raw) !== 1) {
            return $this->refuse('administrator secret has invalid format');
        }

        $password = substr($raw, 0, 64);
        unset($raw);

        try {
            if ($check) {
                $ready = $sealer->ready();
                unset($password);

                if (! $ready) {
                    return $this->refuse('initial Core administrator or mail settings are not in the expected state');
                }

                $this->info('staging seeded-admin read-only preflight passed (no changes)');

                return self::SUCCESS;
            }

            $sealed = $sealer->apply($password);
            unset($password);

            if (! $sealed) {
                return $this->refuse('initial Core administrator or mail settings are not in the expected state');
            }

            $this->info('staging seeded admin sealed and initial SMTP defaults neutralized (no secret displayed)');

            return self::SUCCESS;
        } catch (Throwable) {
            // Never print exception messages or traces: these can contain SQL
            // or security-sensitive inputs. The future one-shot driver must
            // stop and withhold the successful bootstrap marker.
            return $this->refuse('verification or transaction failed; inspect sanitized bootstrap logs');
        }
    }

    private function refuse(string $reason): int
    {
        $this->error('staging seeded-admin seal refused: '.$reason);

        return self::FAILURE;
    }
}
