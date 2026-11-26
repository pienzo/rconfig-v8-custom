<?php

if (getenv('F02_SYNTHETIC_MANUAL_ADMISSION') !== '1') {
    exit(2);
}

require __DIR__ . '/presence_admission_boot.php';

if (config('local-backup-presence.manual_probe_enabled') !== false
    || config('local-backup-presence.manual_dispatch_enabled') !== false) {
    exit(2);
}
config(['local-backup-presence.manual_probe_enabled' => true]);
