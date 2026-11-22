<?php

test('F02 Redis connection is isolated without changing native Core queues', function () {
    $queue = require config_path('queue.php');

    expect($queue['connections']['redis']['retry_after'])->toBe(65)
        ->and($queue['connections']['redis']['after_commit'])->toBeFalse()
        ->and($queue['connections']['redis_external_backups'])->toMatchArray([
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'ExternalBackupChecks',
            'retry_after' => 180,
            'block_for' => null,
            'after_commit' => true,
        ]);

    expect($queue['connections']['redis_external_backups']['retry_after'])->toBeGreaterThan(90);
});

test('F02 Horizon supervisor is disabled by default and does not change Core supervisors', function () {
    $horizon = require config_path('horizon.php');

    expect($horizon['use'])->toBe('default');

    foreach (['production', 'local'] as $environment) {
        $supervisors = $horizon['environments'][$environment];

        expect(array_keys($supervisors))->toBe(['HorizonOne', 'HorizonTwo'])
            ->and($supervisors['HorizonOne']['connection'])->toBe('redis')
            ->and($supervisors['HorizonOne']['tries'])->toBe(1)
            ->and($supervisors['HorizonTwo']['connection'])->toBe('redis')
            ->and($supervisors['HorizonTwo']['queue'])->toBe([
                'TaskDownloadQueue',
                'TaskSnippetQueue',
                'ManualDownloadQueue',
            ])
            ->and($supervisors['HorizonTwo']['tries'])->toBe(1)
            ->and($supervisors['HorizonTwo']['timeout'])->toBe(86400);
    }

    expect(file_get_contents(base_path('.env.example')))->toContain('RCONFIG_F02_QUEUE_ENABLED=false');
});

test('explicit F02 opt-in adds only its dedicated supervisor in both supported environments', function () {
    $key = 'RCONFIG_F02_QUEUE_ENABLED';
    $hadEnv = array_key_exists($key, $_ENV);
    $hadServer = array_key_exists($key, $_SERVER);
    $previousEnv = $_ENV[$key] ?? null;
    $previousServer = $_SERVER[$key] ?? null;

    try {
        $_ENV[$key] = 'true';
        $_SERVER[$key] = 'true';

        $horizon = require config_path('horizon.php');

        foreach (['production', 'local'] as $environment) {
            $supervisors = $horizon['environments'][$environment];

            expect(array_keys($supervisors))->toBe([
                'HorizonOne',
                'HorizonTwo',
                'HorizonExternalBackups',
            ]);

            $f02 = $supervisors['HorizonExternalBackups'];

            expect($f02['connection'])->toBe('redis_external_backups')
                ->and($f02['queue'])->toBe(['ExternalBackupChecks'])
                ->and($f02['tries'])->toBe(3)
                ->and($f02['timeout'])->toBe(90)
                ->and($f02['backoff'])->toBe([10, 30, 60]);
        }
    } finally {
        if ($hadEnv) {
            $_ENV[$key] = $previousEnv;
        } else {
            unset($_ENV[$key]);
        }

        if ($hadServer) {
            $_SERVER[$key] = $previousServer;
        } else {
            unset($_SERVER[$key]);
        }
    }
});
