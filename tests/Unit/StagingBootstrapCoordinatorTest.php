<?php

use App\Services\Staging\StagingBootstrapAttemptGuard;
use App\Services\Staging\StagingBootstrapCompletionMarker;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapStages;

final class SyntheticStagingBootstrapStages implements StagingBootstrapStages
{
    /** @var list<string> */
    public array $calls = [];
    /** @var list<bool> */
    public array $freshResults = [true, true];
    public ?string $failAt = null;
    public ?string $throwAt = null;
    public ?string $storage = null;

    public function fresh(): bool
    {
        $allowed = $this->record('fresh');

        return $allowed && (array_shift($this->freshResults) ?? true);
    }

    public function migrate(): bool
    {
        return $this->record('migrate');
    }

    public function migrated(): bool
    {
        return $this->record('migrated');
    }

    public function seal(): bool
    {
        return $this->record('seal');
    }

    public function sealed(): bool
    {
        return $this->record('sealed');
    }

    public function postflight(): bool
    {
        if ($this->storage !== null && file_exists($this->storage . '/.staging-bootstrap-complete')) {
            throw new RuntimeException('Completion was published before postflight.');
        }

        return $this->record('postflight');
    }

    private function record(string $step): bool
    {
        $this->calls[] = $step;

        if ($this->throwAt === $step) {
            throw new RuntimeException('Synthetic injected error');
        }

        return $this->failAt !== $step;
    }
}

function withSyntheticStagingCoordinatorStorage(Closure $test): void
{
    $temp = realpath(sys_get_temp_dir());

    if ($temp === false) {
        throw new RuntimeException('No synthetic temp directory.');
    }

    $root = $temp . '/rconfig-bootstrap-coordinator-' . bin2hex(random_bytes(12));

    if (! mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create private synthetic fixture.');
    }

    try {
        $test($root);
    } finally {
        // Fixed list of fixture-created paths only; never recursively delete,
        // follow a symlink or clean up an external volume.
        foreach ([
            $root . '/.staging-bootstrap-complete',
            $root . '/.staging-bootstrap-attempt/completion-marker.ready',
            $root . '/.staging-bootstrap-attempt',
        ] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        rmdir($root);
    }
}

test('successful synthetic stages run in strict order and publish one private marker', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        $stages = new SyntheticStagingBootstrapStages;
        $stages->storage = $root;

        expect((new StagingBootstrapCoordinator)->run($root, $stages))->toBeTrue()
            ->and($stages->calls)->toBe([
                'fresh', 'fresh', 'migrate', 'migrated', 'seal', 'sealed', 'postflight',
            ])
            ->and(file_get_contents($root . '/.staging-bootstrap-complete'))->toBe('staging-bootstrap-v1')
            ->and(fileperms($root . '/.staging-bootstrap-complete') & 0777)->toBe(0640)
            ->and(fileperms($root . '/.staging-bootstrap-attempt') & 0777)->toBe(0700)
            ->and(is_file($root . '/.staging-bootstrap-attempt/completion-marker.ready'))->toBeTrue();

        $repeated = new SyntheticStagingBootstrapStages;
        expect((new StagingBootstrapCoordinator)->run($root, $repeated))->toBeFalse()
            ->and($repeated->calls)->toBe(['fresh'])
            ->and(file_get_contents($root . '/.staging-bootstrap-complete'))->toBe('staging-bootstrap-v1');
    });
});

test('a false initial freshness result leaves no persistent attempt', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        $stages = new SyntheticStagingBootstrapStages;
        $stages->freshResults = [false];

        expect((new StagingBootstrapCoordinator)->run($root, $stages))->toBeFalse()
            ->and($stages->calls)->toBe(['fresh'])
            ->and(file_exists($root . '/.staging-bootstrap-attempt'))->toBeFalse()
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();
    });
});

test('freshness changing after atomic claim refuses migrations and preserves the attempt', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        $stages = new SyntheticStagingBootstrapStages;
        $stages->freshResults = [true, false];

        expect((new StagingBootstrapCoordinator)->run($root, $stages))->toBeFalse()
            ->and($stages->calls)->toBe(['fresh', 'fresh'])
            ->and(is_dir($root . '/.staging-bootstrap-attempt'))->toBeTrue()
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();
    });
});

test('every failed post-claim stage leaves durable refusal evidence and no completion', function () {
    foreach (['migrate', 'migrated', 'seal', 'sealed', 'postflight'] as $stage) {
        withSyntheticStagingCoordinatorStorage(function (string $root) use ($stage): void {
            $stages = new SyntheticStagingBootstrapStages;
            $stages->failAt = $stage;

            expect((new StagingBootstrapCoordinator)->run($root, $stages))->toBeFalse()
                ->and(is_dir($root . '/.staging-bootstrap-attempt'))->toBeTrue()
                ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();

            $retry = new SyntheticStagingBootstrapStages;
            expect((new StagingBootstrapCoordinator)->run($root, $retry))->toBeFalse()
                ->and($retry->calls)->toBe(['fresh']);
        });
    }
});

test('an exception during a stage leaves its attempt intact and never creates success', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        $stages = new SyntheticStagingBootstrapStages;
        $stages->throwAt = 'migrate';

        expect(fn () => (new StagingBootstrapCoordinator)->run($root, $stages))
            ->toThrow(RuntimeException::class);

        expect(is_dir($root . '/.staging-bootstrap-attempt'))->toBeTrue()
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();
    });
});

test('the marker writer refuses a missing or invalid attempt record', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        $marker = new StagingBootstrapCompletionMarker;

        expect($marker->publish($root))->toBeFalse();
        mkdir($root . '/.staging-bootstrap-attempt', 0755);

        expect($marker->publish($root))->toBeFalse()
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse();
    });
});

test('the marker writer never overwrites an existing marker', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeTrue();

        $marker = $root . '/.staging-bootstrap-complete';
        file_put_contents($marker, 'synthetic-existing-marker');

        expect((new StagingBootstrapCompletionMarker)->publish($root))->toBeFalse()
            ->and(file_get_contents($marker))->toBe('synthetic-existing-marker');
    });
});

test('a dangling completion marker symlink is refused without following it', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeTrue();

        $marker = $root . '/.staging-bootstrap-complete';
        symlink($root . '/missing-target', $marker);

        expect((new StagingBootstrapCompletionMarker)->publish($root))->toBeFalse()
            ->and(is_link($marker))->toBeTrue();
    });
});

test('a failed marker preparation is not retried and the attempt stays incomplete', function () {
    withSyntheticStagingCoordinatorStorage(function (string $root): void {
        expect((new StagingBootstrapAttemptGuard)->claim($root))->toBeTrue();

        $prepared = $root . '/.staging-bootstrap-attempt/completion-marker.ready';
        file_put_contents($prepared, 'synthetic-partial-marker');

        expect((new StagingBootstrapCompletionMarker)->publish($root))->toBeFalse()
            ->and(file_get_contents($prepared))->toBe('synthetic-partial-marker')
            ->and(file_exists($root . '/.staging-bootstrap-complete'))->toBeFalse()
            ->and(is_dir($root . '/.staging-bootstrap-attempt'))->toBeTrue();
    });
});
