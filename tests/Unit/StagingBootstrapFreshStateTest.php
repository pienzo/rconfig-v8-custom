<?php

use App\Services\Staging\StagingBootstrapStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

test('a truly empty synthetic schema and both empty Redis databases pass the fresh gate', function () {
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['table_count' => 0]);

    $default = Mockery::mock();
    $default->shouldReceive('dbsize')->once()->andReturn(0);

    $cache = Mockery::mock();
    $cache->shouldReceive('dbsize')->once()->andReturn(0);

    Redis::shouldReceive('connection')->once()->with('default')->andReturn($default);
    Redis::shouldReceive('connection')->once()->with('cache')->andReturn($cache);

    expect((new StagingBootstrapStateService)->isFresh())->toBeTrue();
});

test('a nonempty synthetic schema is refused before Redis is inspected', function () {
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['table_count' => 1]);

    Redis::shouldReceive('connection')->never();

    expect((new StagingBootstrapStateService)->isFresh())->toBeFalse();
});

test('a nonempty Redis database is refused even when the SQL schema is empty', function () {
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['table_count' => 0]);

    $default = Mockery::mock();
    $default->shouldReceive('dbsize')->once()->andReturn(1);

    Redis::shouldReceive('connection')->once()->with('default')->andReturn($default);
    Redis::shouldReceive('connection')->with('cache')->never();

    expect((new StagingBootstrapStateService)->isFresh())->toBeFalse();
});
