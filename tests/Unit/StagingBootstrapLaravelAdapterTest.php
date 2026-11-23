<?php

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapContainerGate;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Support\Facades\Artisan;

test('concrete synthetic container gate refuses normal CI processes', function () {
    // A normal hosted test runner is not uid1000:gid33 in the dedicated role.
    expect((new StagingBootstrapContainerGate)->permitted())->toBeFalse();
});

test('Redis selection rejects any mapping other than authenticated default DB0 and cache DB1', function () {
    config()->set([
        'database.redis.client' => 'predis',
        'database.redis.default.host' => 'redis',
        'database.redis.default.port' => 6379,
        'database.redis.default.database' => 0,
        'database.redis.cache.host' => 'redis',
        'database.redis.cache.port' => 6379,
        'database.redis.cache.database' => 1,
        'queue.connections.redis.connection' => 'default',
        'cache.stores.redis.connection' => 'cache',
    ]);

    $gate = new StagingBootstrapContainerGate;

    expect($gate->redisIndicesValid())->toBeTrue();

    foreach ([
        ['database.redis.default.database', 1],
        ['database.redis.cache.database', 0],
        ['database.redis.default.host', 'localhost'],
        ['database.redis.cache.host', 'localhost'],
        ['database.redis.default.port', 6380],
        ['queue.connections.redis.connection', 'cache'],
        ['cache.stores.redis.connection', 'default'],
    ] as [$key, $wrong]) {
        $before = config($key);
        config()->set($key, $wrong);

        expect($gate->redisIndicesValid())->toBeFalse('Unexpected Redis mapping accepted: ' . $key);

        config()->set($key, $before);
    }

    expect($gate->redisIndicesValid())->toBeTrue();
});

test('blocked synthetic gate never invokes the Laravel migration command', function () {
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->once()->andReturn(false);
    $gate->shouldReceive('replacementPassword')->never();

    Artisan::shouldReceive('call')->never();

    $adapter = new LaravelStagingBootstrapStages(new StagingBootstrapStateService, new SealDefaultAdminService, $gate);

    expect($adapter->migrate())->toBeFalse();
});

test('migration executes only the reviewed noninteractive force command', function () {
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->twice()->andReturn(true);
    $gate->shouldReceive('replacementPassword')->never();

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    $adapter = new LaravelStagingBootstrapStages(new StagingBootstrapStateService, new SealDefaultAdminService, $gate);

    expect($adapter->migrate())->toBeTrue();
});

test('a failed migration command prevents the follow-up stage', function () {
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->once()->andReturn(true);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(1);

    $adapter = new LaravelStagingBootstrapStages(new StagingBootstrapStateService, new SealDefaultAdminService, $gate);

    expect($adapter->migrate())->toBeFalse();
});

test('the migration stage rejects a revoked runtime gate after a zero exit', function () {
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->twice()->andReturn(true, false);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    $adapter = new LaravelStagingBootstrapStages(new StagingBootstrapStateService, new SealDefaultAdminService, $gate);

    expect($adapter->migrate())->toBeFalse();
});

test('a migration exception propagates without creating a success state', function () {
    $gate = Mockery::mock(StagingBootstrapRuntimeGate::class);
    $gate->shouldReceive('permitted')->once()->andReturn(true);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andThrow(new RuntimeException('Synthetic migration failure'));

    $adapter = new LaravelStagingBootstrapStages(new StagingBootstrapStateService, new SealDefaultAdminService, $gate);

    expect(fn () => $adapter->migrate())->toThrow(RuntimeException::class);
});
