<?php

use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->beginTransaction();
    $this->url = '/api/tasks/local-backup-presence-pattern-preview';
    $this->payload = [
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'filenames' => ['backup_20261125.cfg', 'backup_20261125.txt'],
    ];
    Bus::fake();
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('presence pattern preview is restricted to authenticated administrators', function () {
    $this->postJson($this->url, $this->payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->postJson($this->url, $this->payload)->assertForbidden();
});

test('presence preview reuses glob matching without accessing directories or creating records', function () {
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    config(['local-backup-presence.runtime_enabled' => false, 'local-backup-presence.roots' => []]);
    $before = [Task::count(), LocalBackupPresenceRule::count(), LocalBackupPresenceRun::count()];
    $samples = ['backup_20261125.cfg', 'backup_20261125.txt', '../backup_20261125.cfg',
        '/synthetic/backup_20261125.cfg', 'nested\\backup_20261125.cfg', 'backup_città.cfg'];
    $response = $this->postJson($this->url, array_replace($this->payload, ['filenames' => $samples]))->assertOk();
    expect(array_column($response->json('results'), 'filename'))->toBe($samples)
        ->and(array_column($response->json('results'), 'matches'))->toBe([true, false, false, false, false, true]);
    expect([Task::count(), LocalBackupPresenceRule::count(), LocalBackupPresenceRun::count()])->toBe($before);
    Bus::assertNothingDispatched();
});

test('presence preview supports fully anchored unicode regex and rejects partial matches', function () {
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    $response = $this->postJson($this->url, [
        'pattern_mode' => 'regex',
        'filename_pattern' => 'sede-.*_\d{2}\.cfg',
        'filenames' => ['sede-città_01.cfg', 'prefix_sede-città_01.cfg', 'sede-città_01.cfg.old'],
    ])->assertOk();
    expect(array_column($response->json('results'), 'matches'))->toBe([true, false, false]);
});

test('presence preview rejects invalid patterns and out of bounds inputs', function (array $changes, string $field) {
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    $this->postJson($this->url, array_replace($this->payload, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    Bus::assertNothingDispatched();
})->with([
    'invalid regex' => [['pattern_mode' => 'regex', 'filename_pattern' => '('], 'filename_pattern'],
    'path glob' => [['filename_pattern' => '../*.cfg'], 'filename_pattern'],
    'unknown mode' => [['pattern_mode' => 'shell'], 'pattern_mode'],
    'empty sample list' => [['filenames' => []], 'filenames'],
    'too many samples' => [['filenames' => array_fill(0, 51, 'backup.cfg')], 'filenames'],
    'oversized sample' => [['filenames' => [str_repeat('a', 256)]], 'filenames.0'],
    'oversized pattern' => [['filename_pattern' => str_repeat('a', 256)], 'filename_pattern'],
]);

test('presence preview route has an explicit twelve per minute throttle', function () {
    $route = app('router')->getRoutes()->match(Request::create($this->url, 'POST'));
    expect($route->gatherMiddleware())->toContain('throttle:12,1,f02-presence-preview:');
});

test('presence preview quota is independent of ordinary API reads and remains twelve per minute', function () {
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    $list = '/api/tasks/local-backup-presence-rules';
    for ($i = 0; $i < 15; $i++) {
        $this->getJson($list)->assertOk();
    }
    for ($i = 0; $i < 12; $i++) {
        $this->postJson($this->url, $this->payload)->assertOk()
            ->assertJsonPath('results.0.matches', true)
            ->assertJsonPath('results.1.matches', false);
    }
    $this->postJson($this->url, $this->payload)->assertStatus(429);
    $this->getJson($list)->assertOk();
    Bus::assertNothingDispatched();
});
