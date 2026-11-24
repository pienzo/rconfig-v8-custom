<?php

use Symfony\Component\Yaml\Yaml;

test('inherited Docker release jobs only run in the official upstream repository', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/docker-release.yml'));

    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect(array_keys($workflow['jobs']))->toBe(['verify-version', 'build', 'merge', 'github-release']);

    foreach ($workflow['jobs'] as $name => $job) {
        expect($job['if'] ?? null)->toBe("github.repository == 'rconfig/rconfig'");
        $expected = match ($name) {
            'build', 'merge' => ['contents' => 'read', 'packages' => 'write'],
            'github-release' => ['contents' => 'write'],
            default => ['contents' => 'read'],
        };
        expect($job['permissions'] ?? $workflow['permissions'])->toBe($expected);
    }

    expect($workflow['jobs']['build']['needs'])->toBe('verify-version');
    expect($workflow['jobs']['merge']['needs'])->toBe('build');
    expect($workflow['jobs']['github-release']['needs'])->toBe('merge');
});
