<?php

return [
    // Dormant until the complete scheduling and deployment boundary is reviewed.
    // Deliberately no environment variable or request-controlled binding.
    'runtime_enabled' => false,
    'queue_enabled' => false,
    'scheduler_enabled' => false,
    'dispatch_enabled' => false,
    'max_rules_per_tick' => 100,
    'roots' => [],
];
