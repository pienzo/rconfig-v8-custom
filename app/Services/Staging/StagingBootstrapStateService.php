<?php

namespace App\Services\Staging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only checks used by a future explicitly triggered synthetic bootstrap.
 * Never installs, migrates, writes a marker or starts a worker.
 */
final class StagingBootstrapStateService
{
    public function isFresh(): bool
    {
        // The host preflight runs first, but the container must recheck just
        // before the one-shot attempt is claimed. Only use a dedicated schema.
        $result = DB::selectOne('SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE()');

        if ($result === null || (int) $result->table_count !== 0) {
            return false;
        }

        return (int) Redis::connection('default')->dbsize() === 0
            && (int) Redis::connection('cache')->dbsize() === 0;
    }

    public function isMigrated(): bool
    {
        return $this->hasCompleteMigrationLedger()
            && (new SealDefaultAdminService)->ready();
    }

    public function isSealed(string $password): bool
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/D', $password) !== 1
            || ! $this->hasCompleteMigrationLedger()
            || DB::table('users')->count() !== 1
            || DB::table('settings')->count() !== 1) {
            return false;
        }

        $admin = DB::table('users')->where('id', 1)->first();
        $settings = DB::table('settings')->where('id', 1)->first();

        return $admin !== null
            && $settings !== null
            && $admin->name === 'Staging administrator'
            && $admin->email === 'staging-admin@example.invalid'
            && strcasecmp((string) $admin->role, 'Admin') === 0
            && Hash::check($password, (string) $admin->password)
            && $settings->mail_host === '127.0.0.1'
            && (int) $settings->mail_port === 1025
            && $settings->mail_driver === 'log'
            && $settings->mail_from_email === 'staging-admin@example.invalid'
            && $settings->mail_to_email === 'staging-admin@example.invalid'
            && (int) $settings->mail_authcheck === 0
            && $settings->mail_username === null
            && $settings->mail_password === null
            && (int) $settings->phpDebugging === 0;
    }

    public function hasCompleteMigrationLedger(): bool
    {
        if (! Schema::hasTable('migrations')
            || ! Schema::hasTable('users')
            || ! Schema::hasTable('settings')) {
            return false;
        }

        $files = glob(database_path('migrations/*.php'));

        if ($files === false || $files === []) {
            return false;
        }

        $expected = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files
        );
        $ran = DB::table('migrations')->pluck('migration')->all();

        // A database that records duplicate/unknown migrations is not the
        // exact first-install schema of the source image we are verifying.
        if (count($expected) !== count(array_unique($expected))
            || count($ran) !== count(array_unique($ran))) {
            return false;
        }

        sort($expected);
        sort($ran);

        return $expected === $ran;
    }
}
