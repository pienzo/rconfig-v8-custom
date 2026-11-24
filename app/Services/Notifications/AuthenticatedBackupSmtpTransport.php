<?php

namespace App\Services\Notifications;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * The locked Symfony mailer allows EHLO without AUTH and HELO fallback.
 * Automatic backup mail requires a successful SMTP AUTH on each connection.
 */
final class AuthenticatedBackupSmtpTransport extends EsmtpTransport
{
    private bool $authenticated = false;

    public function executeCommand(string $command, array $codes): string
    {
        $greeting = $codes === [250] && str_starts_with($command, 'HELO ');
        if ($greeting) {
            $this->authenticated = false;
        }

        $response = parent::executeCommand($command, $codes);

        // Symfony's authenticators require precisely 235 for successful AUTH.
        // This recursive call occurs inside the parent's EHLO handling.
        if ($codes === [235]) {
            $this->authenticated = true;
        }

        if ($greeting && ! $this->authenticated) {
            throw new TransportException('Backup SMTP requires successful authentication.');
        }

        return $response;
    }
}
