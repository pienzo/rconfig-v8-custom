<?php

use App\Services\Notifications\BackupNotificationReconciliationBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->beginTransaction();
    config()->set('cache.default', 'array');
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('bounded reconciliation freezes a cycle and keeps sources and channels independent', function () {
    $insert = static fn (int $number): int => (int) DB::table('custom_native_backup_outcomes')->insertGetId([
        'source_config_id' => -10000 - $number,
        'device_id' => 1,
        'device_name' => 'Synthetic batch',
        'event_type' => 'backup.success',
        'filename' => 'demo.cfg',
        'occurred_at_utc' => '2026-01-01 00:00:00',
    ]);
    $ids = [];
    for ($i = 1; $i <= 105; $i++) {
        $ids[] = $insert($i);
    }
    $query = DB::table('custom_native_backup_outcomes as outcomes')->where('outcomes.source_config_id', '<=', -10001);
    $batch = app(BackupNotificationReconciliationBatch::class);
    $next = static fn (string $source = 'native-config', string $channel = 'telegram'): array => $batch->next(
        $query, $source, $channel, 'outcomes.id'
    )->all();

    expect($next())->toBe(array_slice($ids, 0, 100));
    $newId = $insert(106);
    expect($next())->toBe(array_slice($ids, 100));
    expect($next())->toBe(array_slice($ids, 0, 100));
    expect($next('native-config', 'email'))->toBe(array_slice($ids, 0, 100));
    expect($next('external-file'))->toBe(array_slice($ids, 0, 100));
    expect($next())->toBe([...array_slice($ids, 100), $newId]);

    Cache::put('custom-backup-notification-scan:v1:native-config:telegram', ['after' => 'invalid', 'through' => 999], 60);
    expect($next())->toBe(array_slice($ids, 0, 100));
});
