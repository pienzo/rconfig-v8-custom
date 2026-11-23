<?php

use App\Services\Notifications\BackupNotificationDeliveryAttempt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('custom-backup-notifications.enabled', true);
    // Committed synthetic ledger row: no actual backup, account or destination.
    $this->deliveryId = DB::table('custom_backup_notification_deliveries')->insertGetId([
        'source_type' => 'test-fixture',
        'source_id' => 1,
        'event_type' => 'backup.success',
        'channel' => 'telegram',
        'destination_key' => bin2hex(random_bytes(16)),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->attempt = app(BackupNotificationDeliveryAttempt::class);
});

afterEach(function () {
    config()->set('custom-backup-notifications.enabled', false);
    if (isset($this->deliveryId)) {
        DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->delete();
    }
});

test('a committed claim prevents reentrant and subsequent provider attempts', function () {
    $calls = 0;
    $send = function () use (&$calls): void {
        $calls++;
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect(DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->value('status'))->toBe('sending');
        expect($this->attempt->attempt($this->deliveryId, function () use (&$calls): void {
            $calls++;
        }))->toBe('skipped');
    };

    expect($this->attempt->attempt($this->deliveryId, $send))->toBe('accepted');
    expect($this->attempt->attempt($this->deliveryId, $send))->toBe('skipped');
    expect($calls)->toBe(1);
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->value('status'))->toBe('accepted');
});

test('a lost provider response remains uncertain and is not automatically retried', function () {
    $calls = 0;
    $send = function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('Synthetic lost response');
    };

    expect($this->attempt->attempt($this->deliveryId, $send))->toBe('uncertain');
    expect($this->attempt->attempt($this->deliveryId, $send))->toBe('skipped');
    expect($calls)->toBe(1);
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->value('status'))->toBe('uncertain');
});

test('a stranded sending claim is never automatically reopened', function () {
    DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->update(['status' => 'sending']);
    $called = false;
    expect($this->attempt->attempt($this->deliveryId, function () use (&$called): void {
        $called = true;
    }))->toBe('skipped');
    expect($called)->toBeFalse();
});

test('disabled delivery cannot claim or call a provider', function () {
    config()->set('custom-backup-notifications.enabled', false);
    $called = false;
    expect($this->attempt->attempt($this->deliveryId, function () use (&$called): void {
        $called = true;
    }))->toBe('skipped');
    expect($called)->toBeFalse();
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->value('status'))->toBe('pending');
});

test('delivery inside an open transaction is refused before any provider attempt', function () {
    $called = false;
    DB::beginTransaction();
    try {
        expect(fn () => $this->attempt->attempt($this->deliveryId, function () use (&$called): void {
            $called = true;
        }))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }

    expect($called)->toBeFalse();
    expect(DB::table('custom_backup_notification_deliveries')->where('id', $this->deliveryId)->value('status'))->toBe('pending');
});
