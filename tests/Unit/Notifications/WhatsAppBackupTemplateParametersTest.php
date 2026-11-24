<?php

use App\Services\Notifications\WhatsAppBackupTemplateParameters;

test('WhatsApp positional fields preserve approved order without free text', function () {
    config()->set('rconfig-whatsapp.body_fields_success', 'device.name, backup.status, event.id');
    $parameters = app(WhatsAppBackupTemplateParameters::class)->render('backup.success', [
        'device.name' => 'Synthetic device',
        'backup.status' => 'SUCCESS',
        'event.id' => 'native-config:1',
        'device.ip' => '192.0.2.10',
    ]);
    expect($parameters)->toBe(['Synthetic device', 'SUCCESS', 'native-config:1']);
});

test('WhatsApp mapping rejects unapproved or missing fields and unsafe text', function () {
    $mapper = app(WhatsAppBackupTemplateParameters::class);
    foreach (['device.ip', 'backup.filename', 'free text', 'device.name,'] as $mapping) {
        config()->set('rconfig-whatsapp.body_fields_failed', $mapping);
        expect(fn () => $mapper->render('backup.failed', ['device.name' => 'Demo']))->toThrow(RuntimeException::class);
    }
    config()->set('rconfig-whatsapp.body_fields_failed', 'device.name');
    expect(fn () => $mapper->render('backup.failed', []))->toThrow(RuntimeException::class);
    expect(fn () => $mapper->render('backup.failed', ['device.name' => "demo\ntext"]))->toThrow(RuntimeException::class);
    config()->set('rconfig-whatsapp.body_fields_failed', '');
    expect($mapper->render('backup.failed', []))->toBe([]);
});
