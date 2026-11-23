<?php

return [
    // Private read-only mount configured by the deployer, never through an API.
    // Empty root means on-disk previews are disabled.
    'intake_root' => env('RCONFIG_EXTERNAL_BACKUP_INTAKE_ROOT', ''),
    // Dormant until receipt acceptance and a reviewed staging deployment.
    'runtime_enabled' => (bool) env('RCONFIG_F02_RUNTIME_ENABLED', false),
    'queue_enabled' => (bool) env('RCONFIG_F02_QUEUE_ENABLED', false),
    // NEVER enable until trusted upload finalization and receipt token derivation are reviewed.
    'receipt_acceptance_enabled' => false,
    // Built into the custom image. Not configurable by an API user.
    'safe_digest_binary' => env('RCONFIG_F02_NATIVE_DIGEST_BINARY', '/usr/local/libexec/rconfig-f02-safe-digest'),
    // Initial cap; any higher limit requires bounded-I/O staging evidence.
    'max_digest_bytes' => (int) env('RCONFIG_F02_MAX_DIGEST_BYTES', 104857600),
];
