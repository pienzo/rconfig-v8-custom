<?php

use App\Services\Staging\StagingBootstrapAttemptGuard;

function withSyntheticStagingAttemptStorage(Closure $exercise): void
{
    $temp = realpath(sys_get_temp_dir());

    if ($temp === false) {
        throw new RuntimeException('Synthetic test temp directory unavailable.');
    }

    $root = $temp . '/rconfig-staging-attempt-' . bin2hex(random_bytes(12));

    if (! mkdir($root, 0700)) {
        throw new RuntimeException('Could not create private synthetic fixture.');
    }

    try {
        $exercise($root);
    } finally {
        // Only individually named paths beneath this newly created fixture.
        // Never recursively delete a non-test directory or follow symlinks.
        foreach (['.staging-bootstrap-attempt', '.staging-bootstrap-complete', 'alias', 'collision'] as $name) {
            $path = $root . '/' . $name;

            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        rmdir($root);
    }
}

test('the first claim creates a private persistent attempt record exactly once', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $guard = new StagingBootstrapAttemptGuard;
        $attempt = $root . '/.staging-bootstrap-attempt';

        expect($guard->claim($root))->toBeTrue()
            ->and(is_dir($attempt))->toBeTrue()
            ->and(is_link($attempt))->toBeFalse()
            ->and(fileperms($attempt) & 0777)->toBe(0700)
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();

        // A retry after a crash or a second concurrent claimant must refuse.
        expect($guard->claim($root))->toBeFalse()
            ->and(is_dir($attempt))->toBeTrue();
    });
});

test('an existing incomplete attempt directory is never reused or removed', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $attempt = $root . '/.staging-bootstrap-attempt';
        mkdir($attempt, 0700);

        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeFalse()
            ->and(is_dir($attempt))->toBeTrue();
    });
});

test('an attempt path collision as a file is rejected without overwriting it', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $attempt = $root . '/.staging-bootstrap-attempt';
        file_put_contents($attempt, 'synthetic sentinel');

        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeFalse()
            ->and(file_get_contents($attempt))->toBe('synthetic sentinel');
    });
});

test('a completion marker blocks claiming a new attempt', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $marker = $root . '/.staging-bootstrap-complete';
        file_put_contents($marker, "staging-bootstrap-v1\n");

        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeFalse()
            ->and(file_get_contents($marker))->toBe("staging-bootstrap-v1\n")
            ->and(file_exists($root . '/.staging-bootstrap-attempt'))->toBeFalse();
    });
});

test('a dangling completion symlink blocks claiming rather than being followed', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $marker = $root . '/.staging-bootstrap-complete';
        symlink($root . '/missing-target', $marker);

        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeFalse()
            ->and(is_link($marker))->toBeTrue()
            ->and(file_exists($root . '/.staging-bootstrap-attempt'))->toBeFalse();
    });
});

test('noncanonical paths and symbolic storage directories cannot be claimed', function () {
    withSyntheticStagingAttemptStorage(function (string $root): void {
        $guard = new StagingBootstrapAttemptGuard;
        $alias = $root . '/alias';
        symlink($root, $alias);

        expect($guard->claim($alias))->toBeFalse()
            ->and($guard->claim($root . '/.'))->toBeFalse()
            ->and($guard->claim('relative/storage'))->toBeFalse()
            ->and($guard->claim($root . '/missing'))->toBeFalse()
            ->and(file_exists($root . '/.staging-bootstrap-attempt'))->toBeFalse();
    });
});
