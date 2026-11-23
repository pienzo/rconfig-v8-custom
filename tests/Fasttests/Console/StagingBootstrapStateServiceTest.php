<?php

use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Support\Facades\DB;

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
        ->and($state->isMigrated())->toBeFalse()
        ->and($state->isSealed($password))->toBeTrue()
        ->and($state->isSealed(str_repeat('f', 64)))->toBeFalse();

    DB::table('settings')->where('id', 1)->update(['mail_driver' => 'smtp']);

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
