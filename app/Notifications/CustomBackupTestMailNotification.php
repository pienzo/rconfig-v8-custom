<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An administrator-requested email preview with synthetic backup metadata.
 * No backup contents and no arbitrary HTTP-supplied email recipient.
 */
final class CustomBackupTestMailNotification extends Notification
{
    public function __construct(private readonly string $subject, private readonly string $body) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting($this->subject)
            ->line($this->body);
    }
}
