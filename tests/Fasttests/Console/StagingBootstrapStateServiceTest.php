<?php

use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Fasttests are isolated in transactions; never alter the VM schema.
    $this->beginTransaction();
});

test('a migrated test database is never mistaken for a fresh schema', function () {
    $state = new StagingBootstrapStateService;

    expect($state->isFresh())->toBeFalse();
});

test('the complete migration ledger and exact seeded admin pass migrated state', function () {
    $state = new StagingBootstrapStateService;

    expect($state->hasCompleteMigrationLedger())->toBeTrue()
        ->and($state->isMigrated())->toBeTrue();
});

test('a missing migration ledger entry fails closed without deleting a table', function () {
    $state = new StagingBootstrapStateService;
    $migration = DB::table('migrations')->orderBy('migration')->value('migration');

    expect($migration)->not->toBeNull();
    DB::table('migrations')->where('migration', $migration)->delete();

    expect($state->hasCompleteMigrationLedger())->toBeFalse()
        ->and($state->isMigrated())->toBeFalse();
});

test('sealed state requires new admin hash and completely neutralized SMTP', function () {
    $state = new StagingBootstrapStateService;
    $password = str_repeat('e', 64);

    expect($state->isSealed($password))->toBeFalse()
        ->and((new SealDefaultAdminService)->apply($password))->toBeTrue()
        ->and($state->isMigrated())->toBeFalse();

    // Check each invariant independently before trusting the aggregate gate.
    // These assertions report boolean predicates, never passwords or hashes.
    $admin = DB::table('users')->where('id', 1)->first();
    $settings = DB::table('settings')->where('id', 1)->first();

    $checks = [
        'migration ledger complete' => $state->hasCompleteMigrationLedger(),
        'exactly one user' => DB::table('users')->count() === 1,
        'exactly one settings row' => DB::table('settings')->count() === 1,
        'admin name' => $admin !== null && $admin->name === 'Staging administrator',
        'admin email' => $admin !== null && $admin->email === 'staging-admin@example.invalid',
        'admin role' => $admin !== null && strcasecmp((string) $admin->role, 'Admin') === 0,
        'admin credential' => $admin !== null && Hash::check($password, (string) $admin->password),
        'mail host' => $settings !== null && $settings->mail_host === '127.0.0.1',
        'mail port' => $settings !== null && (int) $settings->mail_port === 1025,
        'mail driver' => $settings !== null && $settings->mail_driver === 'log',
        'mail sender' => $settings !== null && $settings->mail_from_email === 'staging-admin@example.invalid',
        'mail recipient' => $settings !== null && $settings->mail_to_email === 'staging-admin@example.invalid',
        'mail authentication disabled' => $settings !== null && (int) $settings->mail_authcheck === 0,
        'mail username cleared' => $settings !== null && $settings->mail_username === null,
        'mail password cleared (null or empty)' => $settings !== null && in_array($settings->mail_password, [null, ''], true),
        'PHP debugging disabled' => $settings !== null && (int) $settings->phpDebugging === 0,
    ];

    foreach ($checks as $label => $passed) {
        expect($passed)->toBeTrue('Sealed state invariant: ' . $label);
    }

    expect($state->isSealed($password))->toBeTrue()
        ->and($state->isSealed(str_repeat('f', 64)))->toBeFalse();

    DB::table('settings')->where('id', 1)->update(['mail_driver' => 'smtp']);

    expect($state->isSealed($password))->toBeFalse();

    // A nonempty residual SMTP credential is never an acceptable sealed state.
    DB::table('settings')->where('id', 1)->update([
        'mail_driver' => 'log',
        'mail_password' => 'synthetic-stale-password',
    ]);

    expect($state->isSealed($password))->toBeFalse();
});

test('additional administrator rows invalidate the sealed state', function () {
    $state = new StagingBootstrapStateService;
    $password = str_repeat('a', 64);

    expect((new SealDefaultAdminService)->apply($password))->toBeTrue();

    DB::table('users')->insert([
        'name' => 'Test user',
        'email' => 'extra@example.invalid',
        'password' => 'unused',
        'role' => 'User',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($state->isSealed($password))->toBeFalse();
});

test('bootstrap state CLI rejects missing and unexpected phases', function () {
    $this->artisan('staging:bootstrap-state')
        ->expectsOutputToContain('specify exactly one supported --phase')
        ->assertExitCode(1);

    $this->artisan('staging:bootstrap-state', ['--phase' => 'apply'])
        ->expectsOutputToContain('specify exactly one supported --phase')
        ->assertExitCode(1);
});

test('bootstrap state CLI rejects any phase outside the dedicated synthetic container', function () {
    foreach (['fresh', 'migrated', 'sealed'] as $phase) {
        $this->artisan('staging:bootstrap-state', ['--phase' => $phase])
            ->expectsOutputToContain('synthetic bootstrap-only environment gate failed')
            ->assertExitCode(1);
    }
});
