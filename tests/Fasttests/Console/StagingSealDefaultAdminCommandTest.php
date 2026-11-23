<?php

use App\Services\Staging\SealDefaultAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Fasttests inherit Tests\TestCase: shared MySQL schema prepared once; each change in
    // this file is rolled back immediately and cannot alter other tests.
    $this->beginTransaction();
});

test('known initial Core admin and SMTP settings are detectable without writes', function () {
    $service = new SealDefaultAdminService;

    expect($service->ready())->toBeTrue();
    expect(DB::table('users')->where('id', 1)->value('email'))->toBe('admin@domain.com');
    expect(DB::table('settings')->where('id', 1)->value('mail_host'))->toBe('devmailer.rconfig.com');
});

test('one-time synthetic admin sealing rotates the hash and neutralizes the SMTP settings', function () {
    $service = new SealDefaultAdminService;
    $password = str_repeat('d', 64);

    expect($service->apply($password))->toBeTrue();

    $admin = DB::table('users')->where('id', 1)->first();
    $settings = DB::table('settings')->where('id', 1)->first();

    expect($admin->email)->toBe('staging-admin@example.invalid')
        ->and($admin->name)->toBe('Staging administrator')
        ->and($admin->role)->toBe('Admin')
        ->and(Hash::check($password, (string) $admin->password))->toBeTrue()
        ->and($settings->mail_host)->toBe('127.0.0.1')
        ->and($settings->mail_driver)->toBe('log')
        ->and($settings->mail_from_email)->toBe('staging-admin@example.invalid')
        ->and($settings->mail_to_email)->toBe('staging-admin@example.invalid')
        ->and((int) $settings->phpDebugging)->toBe(0)
        ->and($service->ready())->toBeFalse()
        ->and($service->apply($password))->toBeFalse();
});

test('does not change the Core seeded admin if secret format is invalid', function () {
    $service = new SealDefaultAdminService;

    expect($service->apply('short'))->toBeFalse();
    expect($service->apply(str_repeat('?', 64)))->toBeFalse();
    expect($service->ready())->toBeTrue();
    expect(DB::table('users')->where('id', 1)->value('email'))->toBe('admin@domain.com');
});

test('unexpected Core mail defaults fail closed without rotating credentials', function () {
    $service = new SealDefaultAdminService;

    DB::table('settings')->where('id', 1)->update(['mail_host' => 'unexpected.example.invalid']);

    expect($service->ready())->toBeFalse();
    expect($service->apply(str_repeat('e', 64)))->toBeFalse();
    expect(DB::table('users')->where('id', 1)->value('email'))->toBe('admin@domain.com');
});

test('additional users block one-time sealing and do not get modified', function () {
    $service = new SealDefaultAdminService;

    DB::table('users')->insert([
        'name' => 'Synthetic second user',
        'email' => 'synthetic-second@example.invalid',
        'password' => Hash::make(str_repeat('e', 64)),
        'role' => 'User',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($service->ready())->toBeFalse();
    expect($service->apply(str_repeat('e', 64)))->toBeFalse();
    expect(DB::table('users')->where('id', 1)->value('email'))->toBe('admin@domain.com');
});

test('staging CLI refuses actions without the dedicated bootstrap container gate', function () {
    // CI never sets RCONFIG_STAGING_BOOTSTRAP or Docker's IS_DOCKER.
    $this->artisan('staging:seal-default-admin', ['--check' => true])
        ->expectsOutputToContain('synthetic bootstrap-only environment gate failed')
        ->assertExitCode(1);

    $this->artisan('staging:seal-default-admin', ['--apply' => true])
        ->expectsOutputToContain('synthetic bootstrap-only environment gate failed')
        ->assertExitCode(1);
});

test('staging CLI requires exactly one requested operation', function () {
    $this->artisan('staging:seal-default-admin')
        ->expectsOutputToContain('specify exactly one of --check or --apply')
        ->assertExitCode(1);

    $this->artisan('staging:seal-default-admin', ['--check' => true, '--apply' => true])
        ->expectsOutputToContain('specify exactly one of --check or --apply')
        ->assertExitCode(1);
});
