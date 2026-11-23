<?php

return [
    'enabled' => env('RCONFIG_BACKUP_EMAIL_ENABLED', false),
    'host' => env('RCONFIG_BACKUP_EMAIL_HOST', ''),
    'port' => env('RCONFIG_BACKUP_EMAIL_PORT', 465),
    'mode' => env('RCONFIG_BACKUP_EMAIL_MODE', 'smtps'),
    'username' => env('RCONFIG_BACKUP_EMAIL_USERNAME', ''),
    'password' => env('RCONFIG_BACKUP_EMAIL_PASSWORD', ''),
    'from' => env('RCONFIG_BACKUP_EMAIL_FROM', ''),
    'recipient' => env('RCONFIG_BACKUP_EMAIL_RECIPIENT', ''),
];
