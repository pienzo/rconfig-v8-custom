<?php

return [
    // Private read-only mount configured by the deployer, never through an API.
    // Empty root means on-disk previews are disabled.
    'intake_root' => env('RCONFIG_EXTERNAL_BACKUP_INTAKE_ROOT', ''),
];
