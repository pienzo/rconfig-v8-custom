<?php

namespace App\Services\Notifications;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Separate from the Core mailer and its log/failover transports.
 * Prepare validates all configuration before the durable delivery claim.
 */
final class SecureBackupEmailTransport
{
    /** @return array{destination: string, send: Closure(): void} */
    public function prepare(string $title, string $body): array
    {
        if (config('rconfig-backup-email.enabled', false) !== true) {
            throw new RuntimeException('Automatic backup email is disabled.');
        }

        if ($title === '' || $body === '' || strlen($title) > 998 || strlen($body) > 16384
            || preg_match('/[\x00-\x1F\x7F]/', $title) !== 0
            || preg_match('//u', $title) !== 1 || preg_match('//u', $body) !== 1) {
            throw new InvalidArgumentException('Invalid backup email message.');
        }

        $from = config('rconfig-backup-email.from');
        $recipient = config('rconfig-backup-email.recipient');
        $key = config('app.key');
        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL)
            || ! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || ! is_string($key) || $key === '') {
            throw new RuntimeException('Automatic backup email addresses are not configured.');
        }

        $transport = $this->buildTransport();
        $email = new Email;
        $email->from($from)->to($recipient)->subject($title)->text($body);

        return [
            'destination' => hash_hmac('sha256', 'email:' . $recipient, $key),
            'send' => static function () use ($transport, $email): void {
                try {
                    if ($transport->send($email) === null) {
                        throw new RuntimeException('Backup email was not accepted.');
                    }
                } catch (Throwable) {
                    // Never expose raw SMTP responses or credentials.
                    throw new RuntimeException('Backup email transport outcome is uncertain.');
                }
            },
        ];
    }

    public function buildTransport(): EsmtpTransport
    {
        $host = config('rconfig-backup-email.host');
        $port = filter_var(config('rconfig-backup-email.port'), FILTER_VALIDATE_INT);
        $mode = config('rconfig-backup-email.mode');
        $username = config('rconfig-backup-email.username');
        $password = config('rconfig-backup-email.password');

        if (! is_string($host) || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || $port === false || $port < 1 || $port > 65535
            || $mode !== 'smtps'
            || ! is_string($username) || $username === '' || $username === '0' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Automatic backup SMTP is not configured.');
        }

        $transport = new AuthenticatedBackupSmtpTransport($host, $port, true);
        $transport->setRequireTls(true);
        $transport->setAutoTls(false);
        $transport->setUsername($username);
        $transport->setPassword($password);
        $stream = $transport->getStream();
        if (! $stream instanceof SocketStream) {
            throw new RuntimeException('Unsupported SMTP socket transport.');
        }
        $stream->setTimeout(8);
        $stream->setStreamOptions([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);

        return $transport;
    }
}
