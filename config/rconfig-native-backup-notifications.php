<?php

return [
    // Capture future native outcomes only; never replay existing configs.
    'enabled' => env('RCONFIG_NATIVE_BACKUP_NOTIFICATIONS_ENABLED', false),
];
