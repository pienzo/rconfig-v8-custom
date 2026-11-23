<?php

use App\Models\CustomBackupNotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->beginTransaction();
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    config()->set('rconfig-telegram.enabled', true);
    config()->set('rconfig-telegram.bot_token', '123456:' . str_repeat('A', 40));
    config()->set('rconfig-telegram.chat_id', '-1001234567890');
});

afterEach(function () {
    config()->set('rconfig-telegram.enabled', false);
    config()->set('rconfig-telegram.bot_token', '');
    config()->set('rconfig-telegram.chat_id', '');
    $this->rollBackTransaction();
});

test('F03 sends synthetic customized failure text via fake Telegram Bot API only', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    CustomBackupNotificationTemplate::create([
        'channel' => 'telegram',
        'event_type' => 'backup.failed',
        'title' => 'FAIL <{{device.name}}> {{backup.status}}',
        'body' => 'Backup <test> {{backup.error}}',
    ]);

    $this->postJson('/api/settings/notification-templates/telegram/test', [
        'event_type' => 'backup.failed',
    ])->assertOk()
        ->assertJsonPath('sent', true)
        ->assertJsonPath('synthetic_only', true)
        ->assertDontSee('123456:');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.telegram.org/bot123456:' . str_repeat('A', 40) . '/sendMessage'
            && $request['parse_mode'] === 'HTML'
            && $request['chat_id'] === '-1001234567890'
            && str_contains($request['text'], 'FAIL &lt;SW-DEMO-01&gt; FAILED')
            && str_contains($request['text'], '&lt;test&gt;');
    });
});

test('F03 cannot send when Telegram is disabled', function () {
    Http::fake();
    config()->set('rconfig-telegram.enabled', false);

    $this->postJson('/api/settings/notification-templates/telegram/test', [
        'event_type' => 'backup.success',
    ])->assertStatus(503);

    Http::assertNothingSent();
});

test('F03 test action is admin only and validates the notification event', function () {
    Http::fake();

    $this->postJson('/api/settings/notification-templates/telegram/test', [
        'event_type' => 'arbitrary',
    ])->assertUnprocessable();

    $this->actingAs(User::factory()->create(['role' => 'User']));

    $this->postJson('/api/settings/notification-templates/telegram/test', [
        'event_type' => 'backup.failed',
    ])->assertForbidden();

    Http::assertNothingSent();
});

test('F04 synthetic preview picks failed status for failed event', function () {
    $this->postJson('/api/settings/notification-templates/preview', [
        'channel' => 'telegram',
        'event_type' => 'backup.failed',
        'title' => 'Backup {{backup.status}}',
        'body' => '{{backup.error}}',
    ])->assertOk()
        ->assertJsonPath('preview.title', 'Backup FAILED')
        ->assertJsonPath('sent', false);
});
