<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->beginTransaction();
    $this->actingAs(User::factory()->create(['role' => 'Admin']));
    Http::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('WhatsApp draft editor compiles positional text and saves only an unapproved local draft', function () {
    $url = '/api/settings/whatsapp-template-drafts';
    $payload = [
        'event_type' => 'backup.failed',
        'title' => 'Backup',
        'body' => 'Esito {{backup.status}} per {{device.name}}.',
    ];

    $this->getJson($url)->assertOk()->assertJsonCount(2, 'drafts');
    $this->postJson($url . '/preview', $payload)->assertOk()
        ->assertJsonPath('draft.provider_body', 'Esito {{1}} per {{2}}.')
        ->assertJsonPath('draft.fields', ['backup.status', 'device.name'])
        ->assertJsonPath('draft.preview', 'Esito FAILED per SW-DEMO-01.')
        ->assertJsonPath('saved', false)
        ->assertJsonPath('sent', false);

    $this->putJson($url, $payload)->assertOk()
        ->assertJsonPath('approved', false)->assertJsonPath('saved', true);
    $this->assertDatabaseHas('custom_backup_notification_templates', [
        'channel' => 'whatsapp-draft',
        'event_type' => 'backup.failed',
        'body' => $payload['body'],
    ]);
    $this->getJson($url)->assertOk()->assertJsonFragment(['body' => $payload['body'], 'saved' => true]);
    Http::assertNothingSent();
});

test('WhatsApp draft rejects secret variables and dynamic headers', function () {
    $url = '/api/settings/whatsapp-template-drafts';
    foreach ([
        ['title' => 'Backup', 'body' => 'IP {{device.ip}}'],
        ['title' => '{{device.name}}', 'body' => 'Backup'],
        ['title' => 'Backup', 'body' => 'Invalid {{1}}'],
    ] as $draft) {
        $this->putJson($url, ['event_type' => 'backup.success', ...$draft])->assertUnprocessable();
    }
    Http::assertNothingSent();
});

test('WhatsApp draft endpoints refuse non administrators', function () {
    $this->actingAs(User::factory()->create(['role' => 'User']));
    $url = '/api/settings/whatsapp-template-drafts';
    $this->getJson($url)->assertForbidden();
    $this->postJson($url . '/preview', [])->assertForbidden();
    $this->putJson($url, [])->assertForbidden();
    Http::assertNothingSent();
});
