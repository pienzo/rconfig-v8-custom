<?php

namespace App\Services\Staging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One-time synthetic staging gate. NEVER use against a production deployment.
 *
 * This only seals the known Core first-install rows after migrations, while
 * Apache/Horizon/scheduler are still stopped. It does not migrate any schema.
 */
final class SealDefaultAdminService
{
    private const SEED_ADMIN_HASH = '$2y$10$ZF3AXzM/N/xduWF0CQiswOaHYh6EwiNcWJ8AUp.7xv0qDcfLvVsGi';

    private const SAFE_ADMIN_EMAIL = 'staging-admin@example.invalid';

    /**
     * Read-only preflight for the exact first-install Core records.
     */
    public function ready(): bool
    {
        return $this->hasExactInitialState(false);
    }

    /**
     * Commit only if both the seeded admin and SMTP defaults are exactly
     * what the selected upstream Core migrations created.
     *
     * @throws RuntimeException If a verification failure must roll back.
     */
    public function apply(string $password): bool
    {
        if (! preg_match('/\A[0-9a-fA-F]{64}\z/D', $password)) {
            return false;
        }

        return DB::transaction(function () use ($password): bool {
            if (! $this->hasExactInitialState(true)) {
                return false;
            }

            $hash = Hash::make($password);

            $userChanges = DB::table('users')->where('id', 1)->update([
                'name' => 'Staging administrator',
                'email' => self::SAFE_ADMIN_EMAIL,
                'password' => $hash,
                'updated_at' => now(),
            ]);

            $settingChanges = DB::table('settings')->where('id', 1)->update([
                'mail_host' => '127.0.0.1',
                'mail_port' => 1025,
                'mail_driver' => 'log',
                'mail_from_email' => self::SAFE_ADMIN_EMAIL,
                'mail_to_email' => self::SAFE_ADMIN_EMAIL,
                'mail_authcheck' => 0,
                'mail_username' => null,
                'mail_password' => null,
                'phpDebugging' => 0,
                'updated_at' => now(),
            ]);

            $admin = DB::table('users')->where('id', 1)->first();
            $settings = DB::table('settings')->where('id', 1)->first();

            if ($userChanges !== 1 || $settingChanges !== 1
                || $admin === null || $settings === null
                || $admin->email !== self::SAFE_ADMIN_EMAIL
                || ! Hash::check($password, (string) $admin->password)
                || $settings->mail_host !== '127.0.0.1'
                || $settings->mail_driver !== 'log'
                || (int) $settings->phpDebugging !== 0) {
                throw new RuntimeException('Staging first-install sealing verification failed.');
            }

            return true;
        });
    }

    private function hasExactInitialState(bool $lock): bool
    {
        if (DB::table('users')->count() !== 1 || DB::table('settings')->count() !== 1) {
            return false;
        }

        $userQuery = DB::table('users')->where('id', 1);
        $settingsQuery = DB::table('settings')->where('id', 1);

        if ($lock) {
            $userQuery->lockForUpdate();
            $settingsQuery->lockForUpdate();
        }

        $admin = $userQuery->first();
        $settings = $settingsQuery->first();

        return $admin !== null
            && $settings !== null
            && $admin->name === 'admin'
            && $admin->email === 'admin@domain.com'
            && strcasecmp((string) $admin->role, 'Admin') === 0
            && hash_equals(self::SEED_ADMIN_HASH, (string) $admin->password)
            && $settings->mail_host === 'devmailer.rconfig.com'
            && $settings->mail_driver === 'smtp'
            && $settings->mail_from_email === 'admin@domain.com';
    }
}
