<?php

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Existing disposable hosted MySQL service; every mutation rolls back.
    $this->beginTransaction();
});

function syntheticBootstrapGate(string $password, bool $storageReady = true): StagingBootstrapRuntimeGate
{
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->andReturn(true);
    $gate->shouldReceive('attemptClaimed')->andReturn(true);
    $gate->shouldReceive('replacementPassword')->andReturn($password);
    $gate->shouldReceive('postflightReady')->andReturn($storageReady);

    return $gate;
}

test('real seeded Core rows can be sealed and independently verified through the adapter', function () {
    $password = str_repeat('a', 64);
    $adapter = new LaravelStagingBootstrapStages(
        new StagingBootstrapStateService,
        new SealDefaultAdminService,
        syntheticBootstrapGate($password)
    );

    expect($adapter->migrated())->toBeTrue()
        ->and($adapter->sealed())->toBeFalse()
        ->and($adapter->seal())->toBeTrue()
        ->and($adapter->migrated())->toBeFalse()
        ->and($adapter->sealed())->toBeTrue()
        ->and($adapter->postflight())->toBeTrue();
});

test('the postflight rejects residual SMTP transport after an otherwise successful seal', function () {
    $password = str_repeat('b', 64);
    $adapter = new LaravelStagingBootstrapStages(
        new StagingBootstrapStateService,
        new SealDefaultAdminService,
        syntheticBootstrapGate($password)
    );

    expect($adapter->seal())->toBeTrue();

    DB::table('settings')->where('id', 1)->update(['mail_driver' => 'smtp']);

    expect($adapter->sealed())->toBeFalse()
        ->and($adapter->postflight())->toBeFalse();
});

test('the postflight refuses unverified persistent storage even after admin sealing', function () {
    $password = str_repeat('c', 64);
    $adapter = new LaravelStagingBootstrapStages(
        new StagingBootstrapStateService,
        new SealDefaultAdminService,
        syntheticBootstrapGate($password, false)
    );

    expect($adapter->seal())->toBeTrue()
        ->and($adapter->sealed())->toBeTrue()
        ->and($adapter->postflight())->toBeFalse();
});

test('the adapter refuses sealing when the initial administrator does not match Core', function () {
    $password = str_repeat('d', 64);
    $adapter = new LaravelStagingBootstrapStages(
        new StagingBootstrapStateService,
        new SealDefaultAdminService,
        syntheticBootstrapGate($password)
    );

    DB::table('users')->where('id', 1)->update(['name' => 'wrong-seed']);

    expect($adapter->migrated())->toBeFalse()
        ->and($adapter->seal())->toBeFalse()
        ->and(DB::table('users')->where('id', 1)->value('name'))->toBe('wrong-seed');
});
