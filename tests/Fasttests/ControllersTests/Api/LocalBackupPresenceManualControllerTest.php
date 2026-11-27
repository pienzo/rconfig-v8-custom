<?php

use App\Models\Device;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupFileReceipt;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRun;
use App\Models\User;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->beginTransaction();
    $this->instant = CarbonImmutable::parse('2026-11-27 01:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->instant);
    config([
        'rConfig.is_demo' => false,
        'local-backup-presence.runtime_enabled' => true,
        'local-backup-presence.queue_enabled' => true,
        'local-backup-presence.scheduler_enabled' => true,
        'local-backup-presence.dispatch_enabled' => true,
        'local-backup-presence.manual_probe_enabled' => true,
        'local-backup-presence.manual_dispatch_enabled' => true,
        'local-backup-presence.manual_api_enabled' => true,
        'local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only'],
    ]);
    $this->admin = User::factory()->create(['role' => 'Admin']);
    $this->device = Device::factory()->create();
    $this->criteria = [
        'directory_alias' => 'synthetic',
        'relative_directory' => 'device',
        'pattern_mode' => 'glob',
        'filename_pattern' => 'backup_*.cfg',
        'minimum_bytes' => 1,
        'maximum_age_seconds' => null,
        'quiet_seconds' => 30,
        'deadline_seconds' => 180,
        'max_entries' => 100,
        'max_matches' => 10,
        'max_scan_millis' => 2000,
    ];
    $this->lifecycle = app(LocalBackupPresenceRuleLifecycle::class);
    $this->rule = $this->lifecycle->create($this->device->id, 'Synthetic manual API', '* * * * *', $this->criteria);
    $this->url = '/api/tasks/local-backup-presence-rules/' . $this->rule->id . '/verify-now';
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    $this->rollBackTransaction();
});

test('manual REST routes require authentication and administrator role', function () {
    $uuid = (string) Str::uuid();
    $this->postJson($this->url, [])->assertUnauthorized();
    $this->getJson($this->url . '/' . $uuid)->assertUnauthorized();
    $this->postJson($this->url . '/' . $uuid . '/restart', [])->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->postJson($this->url, [])->assertForbidden();
    $this->getJson($this->url . '/' . $uuid)->assertForbidden();
    $this->postJson($this->url . '/' . $uuid . '/restart', [])->assertForbidden();
    expect(LocalBackupPresenceManualSession::where('rule_id', $this->rule->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

test('manual API approval requires every execution gate and excludes demo', function (string $key, mixed $value) {
    $this->actingAs($this->admin);
    config([$key => $value]);
    $this->getJson('/api/tasks/local-backup-presence-rules')->assertOk()->assertJsonPath('manual_available', false);
    $uuid = (string) Str::uuid();
    $this->postJson($this->url, [])->assertStatus(409);
    $this->getJson($this->url . '/' . $uuid)->assertStatus(409);
    $this->postJson($this->url . '/' . $uuid . '/restart', [])->assertStatus(409);
    expect(LocalBackupPresenceManualSession::where('rule_id', $this->rule->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
})->with([
    ['local-backup-presence.manual_api_enabled', false],
    ['local-backup-presence.manual_api_enabled', 'true'],
    ['local-backup-presence.runtime_enabled', false],
    ['local-backup-presence.queue_enabled', false],
    ['local-backup-presence.scheduler_enabled', false],
    ['local-backup-presence.dispatch_enabled', false],
    ['local-backup-presence.manual_probe_enabled', false],
    ['local-backup-presence.manual_dispatch_enabled', false],
    ['rConfig.is_demo', true],
]);

test('start is idempotent and status is scoped, temporary and read only', function () {
    $this->actingAs($this->admin);
    $this->getJson('/api/tasks/local-backup-presence-rules')->assertOk()->assertJsonPath('manual_available', true);
    $before = [$this->rule->fresh()->getRawOriginal(), $this->rule->task()->firstOrFail()->getRawOriginal(),
        LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count()];
    $response = $this->postJson($this->url, [])->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.result', null)
        ->assertJsonPath('data.evidence_type', 'metadata_presence')
        ->assertJsonPath('data.verified_success', false)
        ->assertJsonPath('data.persistent_check_run', false)
        ->assertJsonPath('data.requested_at_utc', '2026-11-27T01:00:00Z')
        ->assertJsonPath('data.expires_at_utc', '2026-11-27T01:03:00Z');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $id = $response->json('data.id');
    expect(Str::isUuid($id))->toBeTrue();
    $session = LocalBackupPresenceManualSession::findOrFail($id);
    $snapshot = $session->getRawOriginal();
    $this->postJson($this->url, [])->assertStatus(202)->assertJsonPath('data.id', $id);
    $this->getJson($this->url . '/' . $id)->assertOk()->assertExactJson($response->json());
    $other = $this->lifecycle->create($this->device->id, 'Other manual API rule', '* * * * *', $this->criteria);
    $this->getJson('/api/tasks/local-backup-presence-rules/' . $other->id . '/verify-now/' . $id)->assertNotFound();
    $this->getJson($this->url . '/not-a-uuid')->assertNotFound();
    CarbonImmutable::setTestNow($this->instant->addSeconds(180));
    $this->getJson($this->url . '/' . $id)->assertNotFound();
    expect($session->fresh()->getRawOriginal())->toBe($snapshot);
    expect([$this->rule->fresh()->getRawOriginal(), $this->rule->task()->firstOrFail()->getRawOriginal(),
        LocalBackupPresenceRun::count(), ExternalBackupCheckRun::count(), ExternalBackupFileReceipt::count(),
        DB::table('custom_backup_notification_deliveries')->count()])->toBe($before);
    Bus::assertNothingDispatched();
});

test('terminal restart uses explicit old UUID and refuses stale or queued restart', function () {
    $this->actingAs($this->admin);
    $id = $this->postJson($this->url, [])->assertStatus(202)->json('data.id');
    $this->postJson($this->url . '/' . $id . '/restart', [])->assertStatus(409);
    $old = LocalBackupPresenceManualSession::findOrFail($id);
    $old->status = 'present';
    $old->next_observation_at = null;
    $old->result = ['status' => 'present', 'reason' => 'matching_file_observed', 'filename' => 'backup_a.cfg',
        'observed_at_utc' => '2026-11-27T01:00:00Z', 'verified_success' => true];
    $old->save();
    $this->getJson($this->url . '/' . $id)->assertOk()->assertJsonPath('data.result.filename', 'backup_a.cfg')
        ->assertJsonPath('data.verified_success', false);
    $new = $this->postJson($this->url . '/' . $id . '/restart', [])->assertStatus(202)->json('data.id');
    expect($new)->not->toBe($id);
    $this->getJson($this->url . '/' . $id)->assertNotFound();
    $this->postJson($this->url . '/' . $id . '/restart', [])->assertStatus(409);
    $this->postJson($this->url, [])->assertStatus(202)->assertJsonPath('data.id', $new);
    expect($old->fresh())->toBeNull();
    Bus::assertNothingDispatched();
});

test('manual status never serializes continuation or unsafe stored result fields', function () {
    $this->actingAs($this->admin);
    $session = app(LocalBackupPresenceManualSessions::class)->start($this->rule->id);
    $session->status = 'pending';
    $session->continuation = ['root' => '/synthetic/private-root', 'inode' => 123];
    $session->result = ['status' => 'pending', 'reason' => '/synthetic/private-reason',
        'filename' => '../private.cfg', 'observed_at_utc' => '/synthetic/private-time',
        'secret' => 'SYNTHETIC_PRIVATE_RESULT', 'verified_success' => true];
    $session->save();
    $response = $this->getJson($this->url . '/' . $session->id)->assertOk()
        ->assertJsonPath('data.result.reason', null)
        ->assertJsonPath('data.result.filename', null)
        ->assertJsonPath('data.result.observed_at_utc', null)
        ->assertJsonPath('data.verified_success', false);
    expect($response->json('data'))->not->toHaveKeys(['continuation', 'dispatch_not_before']);
    expect($response->getContent())->not->toContain('synthetic/private', 'private.cfg', 'SYNTHETIC_PRIVATE_RESULT', 'inode');
    Bus::assertNothingDispatched();
});

test('manual routes reject client-controlled parameters', function (array $payload) {
    $this->actingAs($this->admin);
    $this->postJson($this->url, $payload)->assertUnprocessable()->assertJsonValidationErrors('payload');
    $uuid = (string) Str::uuid();
    $this->postJson($this->url . '/' . $uuid . '/restart', $payload)->assertUnprocessable()->assertJsonValidationErrors('payload');
    $this->getJson($this->url . '/' . $uuid . '?root=synthetic')->assertUnprocessable();
    expect(LocalBackupPresenceManualSession::where('rule_id', $this->rule->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
})->with([
    [['root' => '/synthetic/unauthorized']],
    [['session_id' => null]],
    [['result' => ['verified_success' => true]]],
    [['deadline_seconds' => 3600]],
]);

test('capacity and invalid mapping conflicts are sanitized', function () {
    $this->actingAs($this->admin);
    config(['local-backup-presence.roots' => ['synthetic' => '/synthetic/private-root/../unsafe']]);
    $response = $this->postJson($this->url, [])->assertStatus(409);
    expect($response->getContent())->not->toContain('/synthetic/private-root');
    config(['local-backup-presence.roots' => ['synthetic' => '/synthetic/worker-only']]);
    $service = app(LocalBackupPresenceManualSessions::class);
    for ($i = 0; $i < LocalBackupPresenceManualSessions::CAPACITY; $i++) {
        $other = $this->lifecycle->create($this->device->id, 'Capacity manual API ' . $i, '* * * * *', $this->criteria);
        $service->start($other->id);
    }
    $this->postJson($this->url, [])->assertStatus(409);
    expect(LocalBackupPresenceManualSession::count())->toBe(10);
    Bus::assertNothingDispatched();
});

test('manual write and polling routes have separate shared throttle buckets', function () {
    $routes = app('router')->getRoutes();
    $uuid = (string) Str::uuid();
    expect($routes->match(Request::create($this->url, 'POST'))->gatherMiddleware())->toContain('throttle:6,1,f02-manual-write:');
    expect($routes->match(Request::create($this->url . '/' . $uuid . '/restart', 'POST'))->gatherMiddleware())->toContain('throttle:6,1,f02-manual-write:');
    expect($routes->match(Request::create($this->url . '/' . $uuid, 'GET'))->gatherMiddleware())->toContain('throttle:60,1,f02-manual-read:');
});
