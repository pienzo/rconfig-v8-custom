<?php

use App\Services\Notifications\WhatsAppCloudTemplateTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('rconfig-whatsapp.enabled', false);
    config()->set('rconfig-whatsapp.graph_version', 'v26.0');
    config()->set('rconfig-whatsapp.access_token', 'EAA' . str_repeat('x', 55));
    config()->set('rconfig-whatsapp.phone_number_id', '123456789012345');
    config()->set('rconfig-whatsapp.recipient', '393331234567');
    config()->set('rconfig-whatsapp.template_success', 'backup_success_alert');
    config()->set('rconfig-whatsapp.template_failed', 'backup_failed_alert');
    config()->set('rconfig-whatsapp.template_language', 'it');
    Http::preventStrayRequests();
});

afterEach(function () {
    config()->set('rconfig-whatsapp.enabled', false);
    config()->set('rconfig-whatsapp.access_token', '');
    config()->set('rconfig-whatsapp.phone_number_id', '');
    config()->set('rconfig-whatsapp.recipient', '');
    config()->set('rconfig-whatsapp.template_success', '');
    config()->set('rconfig-whatsapp.template_failed', '');
});

test('F03 WhatsApp Cloud API adapter is inert unless enabled', function () {
    Http::fake();

    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed'))
        ->toThrow(RuntimeException::class, 'disabled');

    Http::assertNothingSent();
});

test('F03 WhatsApp sends an approved utility template over HTTPS, not arbitrary free text', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    Http::fake(['graph.facebook.com/*' => Http::response([
        'messaging_product' => 'whatsapp',
        'messages' => [['id' => 'wamid.SYNTHETIC-123']],
    ], 200)]);

    $id = app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate(
        'backup.failed', ['SW-SYNTHETIC-01', 'FAILED']
    );
    expect($id)->toBe('wamid.SYNTHETIC-123');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v26.0/123456789012345/messages'
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '393331234567'
        && $request['type'] === 'template'
        && $request['template']['name'] === 'backup_failed_alert'
        && $request['template']['language']['code'] === 'it'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'SW-SYNTHETIC-01'
        && $request['template']['components'][0]['parameters'][1]['text'] === 'FAILED');
});

test('F03 WhatsApp supports a pre-approved template without body placeholders', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NO-PARAM']]], 200)]);

    expect(app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.success'))
        ->toBe('wamid.NO-PARAM');

    Http::assertSent(fn ($request) => $request['template']['name'] === 'backup_success_alert'
        && array_key_exists('components', $request['template']) === false);
});

test('F03 WhatsApp rejects absent configuration, unapproved event and unsafe parameters', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    Http::fake();

    config()->set('rconfig-whatsapp.access_token', '');
    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed'))
        ->toThrow(RuntimeException::class, 'not configured');

    config()->set('rconfig-whatsapp.access_token', 'EAA' . str_repeat('x', 55));
    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('custom.event'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed', ['']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed', ["\xFF"]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed', array_fill(0, 9, 'too-many')))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('F03 WhatsApp provider failures are sanitized without leaking recipient or token', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'token secret and number 393331234567'],
    ], 403)]);

    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed'))
        ->toThrow(RuntimeException::class, 'provider rejected the template submission');
});

test('F03 WhatsApp connection errors are sanitized', function () {
    config()->set('rconfig-whatsapp.enabled', true);
    Http::fake(fn () => throw new ConnectionException('Synthetic meta HTTP error with tokens'));
    expect(fn () => app(WhatsAppCloudTemplateTransport::class)->sendApprovedTemplate('backup.failed'))
        ->toThrow(RuntimeException::class, 'connection failed');
});
