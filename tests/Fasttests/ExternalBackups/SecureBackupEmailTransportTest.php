<?php

use App\Services\Notifications\SecureBackupEmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

beforeEach(function () {
    config()->set('rconfig-backup-email', [
        'enabled' => true,
        'host' => 'smtp.example.test',
        'port' => 465,
        'mode' => 'smtps',
        'username' => 'synthetic-user',
        'password' => 'synthetic-password',
        'from' => 'sender@example.test',
        'recipient' => 'recipient@example.test',
    ]);
    $this->transport = app(SecureBackupEmailTransport::class);
});

test('isolated SMTP transport requires implicit TLS and peer verification', function () {
    $transport = $this->transport->buildTransport();
    $stream = $transport->getStream();

    expect($stream)->toBeInstanceOf(SocketStream::class);
    expect($stream->isTLS())->toBeTrue();
    expect($transport->isTlsRequired())->toBeTrue();
    expect($transport->isAutoTls())->toBeFalse();
    expect($stream->getHost())->toBe('smtp.example.test');
    expect($stream->getPort())->toBe(465);
    expect($stream->getTimeout())->toBe(8.0);
    $ssl = $stream->getStreamOptions()['ssl'];
    expect($ssl['verify_peer'])->toBeTrue();
    expect($ssl['verify_peer_name'])->toBeTrue();
    expect($ssl['allow_self_signed'])->toBeFalse();
    expect($ssl['peer_name'])->toBe('smtp.example.test');
    expect($ssl['crypto_method'])->toBe(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
});

test('preparation creates an opaque stable destination without connecting', function () {
    $first = $this->transport->prepare('Demo', 'Synthetic body');
    $second = $this->transport->prepare('Other subject', 'Other body');
    expect($first['destination'])->toMatch('/\A[a-f0-9]{64}\z/');
    expect($second['destination'])->toBe($first['destination']);
    expect($first['send'])->toBeInstanceOf(Closure::class);
    config()->set('rconfig-backup-email.recipient', 'other@example.test');
    expect($this->transport->prepare('Demo', 'Synthetic body')['destination'])->not->toBe($first['destination']);
});

test('disabled and unencrypted or incomplete configurations are refused during preflight', function () {
    config()->set('rconfig-backup-email.enabled', false);
    expect(fn () => $this->transport->prepare('Demo', 'Body'))->toThrow(RuntimeException::class);
    config()->set('rconfig-backup-email.enabled', true);
    foreach (['smtp', 'starttls', 'log', 'failover'] as $mode) {
        config()->set('rconfig-backup-email.mode', $mode);
        expect(fn () => $this->transport->prepare('Demo', 'Body'))->toThrow(RuntimeException::class);
    }
    config()->set('rconfig-backup-email.mode', 'smtps');
    config()->set('rconfig-backup-email.password', '');
    expect(fn () => $this->transport->prepare('Demo', 'Body'))->toThrow(RuntimeException::class);
});

test('unsafe subject and invalid recipient are rejected before any delivery', function () {
    expect(fn () => $this->transport->prepare("Demo\r\nBcc: other@example.test", 'Body'))->toThrow(InvalidArgumentException::class);
    config()->set('rconfig-backup-email.recipient', 'one@example.test,two@example.test');
    expect(fn () => $this->transport->prepare('Demo', 'Body'))->toThrow(RuntimeException::class);
});
