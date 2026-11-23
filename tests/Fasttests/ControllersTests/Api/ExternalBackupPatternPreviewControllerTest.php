<?php

use App\Models\User;

beforeEach(function () {
    $this->beginTransaction();
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('authenticated F02 preview returns per-filename glob matches without filesystem reads', function () {
    $this->postJson('/api/tasks/external-backup-pattern-preview', [
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'filenames' => ['backup_20261123.cfg', 'backup_20261123.txt', '../backup_20261123.cfg'],
    ])->assertOk()
        ->assertJsonPath('results.0.matches', true)
        ->assertJsonPath('results.1.matches', false)
        ->assertJsonPath('results.2.matches', false);
});

test('authenticated F02 preview supports fully anchored regex and unicode basenames', function () {
    $this->postJson('/api/tasks/external-backup-pattern-preview', [
        'pattern_mode' => 'regex',
        'filename_pattern' => 'sede-.*_\\d{2}\\.cfg',
        'filenames' => ['sede-città_01.cfg', 'prefix_sede-città_01.cfg'],
    ])->assertOk()
        ->assertJsonPath('results.0.matches', true)
        ->assertJsonPath('results.1.matches', false);
});

test('F02 preview rejects an invalid pattern and limits sample count', function () {
    $this->postJson('/api/tasks/external-backup-pattern-preview', [
        'pattern_mode' => 'regex',
        'filename_pattern' => '(',
        'filenames' => ['test.cfg'],
    ])->assertUnprocessable()->assertJsonValidationErrors('filename_pattern');

    $this->postJson('/api/tasks/external-backup-pattern-preview', [
        'pattern_mode' => 'glob',
        'filename_pattern' => '*.cfg',
        'filenames' => array_fill(0, 51, 'test.cfg'),
    ])->assertUnprocessable()->assertJsonValidationErrors('filenames');
});
