<?php

use App\Services\Staging\StagingBootstrapProcessEnvironmentGuard;

test('original Linux execve snapshot accepts synthetic bootstrap identity and unrelated environment variables', function () {
    $safe = "PATH=/usr/local/bin:/usr/bin\0"
        . "IS_DOCKER=true\0"
        . "RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1\0"
        . "GITHUB_ACTIONS=true\0"
        . "UNRELATED=value=APP_KEY=not-a-process-key\0";

    expect(StagingBootstrapProcessEnvironmentGuard::safeSnapshot($safe))->toBeTrue();
});

test('all protected process override names are denied even when values resemble the synthetic configuration', function () {
    foreach ([
        'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST',
        'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB',
        'REDIS_CACHE_DB', 'QUEUE_CONNECTION', 'CACHE_DRIVER',
        'SESSION_DRIVER', 'RCONFIG_F02_QUEUE_ENABLED', 'MAIL_MAILER',
    ] as $blocked) {
        $snapshot = "PATH=/usr/bin\0" . $blocked . "=synthetic-fixture\0";

        expect(StagingBootstrapProcessEnvironmentGuard::safeSnapshot($snapshot))
            ->toBeFalse('Unexpected process override accepted: ' . $blocked);
    }
});

test('malformed original process environments fail closed without leaking any values', function () {
    foreach ([
        '',
        'PATH=/usr/bin',
        "\0",
        "PATH=/usr/bin\0invalid-entry\0",
        "=anonymous-value\0",
        "APP_KEY=synthetic-fixture\0",
    ] as $snapshot) {
        expect(StagingBootstrapProcessEnvironmentGuard::safeSnapshot($snapshot))->toBeFalse();
    }
});
