<?php

use App\Models\CustomBackupNotificationTemplate;
use App\Models\User;
use App\Notifications\CustomBackupTestMailNotification;
use Illuminate\Support\Facades\Notification;
use App\Services\Notifications\BackupMessageTemplateRenderer;

beforeEach(function () {
    $this->beginTransaction();
    $this->admin = User::factory()->create(['role' => 'Admin']);
    $this->actingAs($this->admin);
});

afterEach(function () {
    $this->rollBackTransaction();
});

test('F04 lists four defaults and supports save, synthetic preview and reset', function () {
    $this->getJson('/api/settings/notification-templates')->assertOk()
        ->assertJsonPath('delivery_connected', false)
        ->assertJsonCount(4, 'templates')
        ->assertJsonCount(12, 'variables');

    $this->putJson('/api/settings/notification-templates/email/backup.success', [
        'title' => 'Backup {{backup.status}} per {{device.name}}',
        'body' => 'File: {{backup.filename}}',
    ])->assertOk()->assertJsonPath('template.channel', 'email');

    $this->assertDatabaseHas('custom_backup_notification_templates', [
        'channel' => 'email',
        'event_type' => 'backup.success',
        'updated_by_user_id' => $this->admin->id,
    ]);

    $this->postJson('/api/settings/notification-templates/preview', [
        'channel' => 'email',
        'title' => 'Backup {{backup.status}} per {{device.name}}',
        'body' => 'File: {{backup.filename}}',
    ])->assertOk()->assertJsonPath('sent', false)
        ->assertJsonPath('preview.title', 'Backup SUCCESS per SW-DEMO-01');

    $this->deleteJson('/api/settings/notification-templates/email/backup.success')
        ->assertOk()->assertJsonPath('reset', true);

    expect(CustomBackupNotificationTemplate::count())->toBe(0);
});

test('F04 rejects unrecognized placeholders, incomplete braces and header injection', function () {
    $this->putJson('/api/settings/notification-templates/telegram/backup.failed', [
        'title' => '{{device.secret}}',
        'body' => 'Errore {{backup.error}}',
    ])->assertUnprocessable();

    $this->putJson('/api/settings/notification-templates/email/backup.failed', [
        'title' => "Backup\nBcc: attacker@example.invalid",
        'body' => 'Errore',
    ])->assertUnprocessable();

    $this->putJson('/api/settings/notification-templates/email/backup.failed', [
        'title' => '{{device.name',
        'body' => 'Errore',
    ])->assertUnprocessable();

    expect(CustomBackupNotificationTemplate::count())->toBe(0);
});

test('F04 Telegram renderer escapes HTML and retains unicode', function () {
    $rendered = (new BackupMessageTemplateRenderer)->render(
        'telegram', 'Router <one> — {{device.name}}',
        'Status <critical> & {{backup.status}}',
        ['device.name' => 'R&D <1>', 'backup.status' => 'FAILED']
    );
    expect($rendered['formatted_text'])->toContain('&lt;one&gt;');
    expect($rendered['formatted_text'])->toContain('R&amp;D &lt;1&gt;');
    expect($rendered['formatted_text'])->toContain('&lt;critical&gt;');
    expect($rendered['formatted_text'])->not->toContain('<critical>');
});

test('F04 refuses non-administrators for all API actions', function () {
    $this->actingAs(User::factory()->create(['role' => 'User']));

    $this->getJson('/api/settings/notification-templates')->assertForbidden();
    $this->putJson('/api/settings/notification-templates/email/backup.success', [
        'title' => 'test', 'body' => 'test',
    ])->assertForbidden();
    $this->postJson('/api/settings/notification-templates/preview', [
        'channel' => 'email', 'title' => 'test', 'body' => 'test',
    ])->assertForbidden();
    $this->deleteJson('/api/settings/notification-templates/email/backup.success')->assertForbidden();
});

test('F04 email test submits only a synthetic message to the authenticated admin', function () {
    Notification::fake();

    $this->putJson('/api/settings/notification-templates/email/backup.failed', [
        'title' => 'F02 {{device.name}} {{backup.status}}',
        'body' => 'Reason: {{backup.error}} | {{backup.filename}}',
    ])->assertOk();

    $this->postJson('/api/settings/notification-templates/email/test', [
        'event_type' => 'backup.failed',
        'recipient' => 'outside@example.invalid',
    ])->assertOk()
        ->assertJsonPath('submitted_to_mailer', true)
        ->assertJsonPath('delivered', false)
        ->assertJsonPath('synthetic_only', true);

    Notification::assertSentTo($this->admin, CustomBackupTestMailNotification::class, function ($notification) {
        $mail = $notification->toMail($this->admin);

        return $mail->subject === 'F02 SW-DEMO-01 FAILED'
            && ! str_contains(implode(' ', $mail->introLines), 'outside@example.invalid')
            && str_contains(implode(' ', $mail->introLines), 'synthetic_20261123.cfg');
    });
    Notification::assertCount(1);
});

test('F04 email test refuses non-admin and rejects invalid event without sending', function () {
    Notification::fake();

    $this->postJson('/api/settings/notification-templates/email/test', [
        'event_type' => 'invalid.event',
    ])->assertUnprocessable();
    Notification::assertNothingSent();

    $this->actingAs(User::factory()->create(['role' => 'User']));
    $this->postJson('/api/settings/notification-templates/email/test', [
        'event_type' => 'backup.failed',
    ])->assertForbidden();
    Notification::assertNothingSent();
});
