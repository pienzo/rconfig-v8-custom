<?php

return [
    // Independent from administrator-triggered synthetic test notifications.
    // Enable only after approved recipients, dispatcher and staging validation.
    'enabled' => env('RCONFIG_CUSTOM_BACKUP_NOTIFICATIONS_ENABLED', false),
];
