<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use DomainException;

/** Shared syntax and bounds checks; no filesystem access or root approval. */
final class LocalBackupPresenceCriteriaValidator
{
    public function __construct(private readonly ExternalBackupFilenameMatcher $matcher) {}

    public function validate(LocalBackupPresenceRule $rule): void
    {
        $minimum = $rule->minimum_bytes;
        $age = $rule->maximum_age_seconds;
        $quiet = $rule->quiet_seconds;
        $deadline = $rule->deadline_seconds;
        $entries = $rule->max_entries;
        $matches = $rule->max_matches;
        $millis = $rule->max_scan_millis;

        if ($minimum < 1 || $minimum > 1000000000000
            || ($age !== null && ($age < 1 || $age > 31536000))
            || $deadline < 60 || $deadline > 2678400
            || ($quiet !== null && ($quiet < 1 || $quiet > 3600 || $quiet >= $deadline))
            || $entries < 1 || $entries > 10000
            || $matches < 1 || $matches > 1000 || $matches > $entries
            || $millis < 1 || $millis > 10000) {
            throw new DomainException('Local backup rule has invalid observation limits.');
        }

        LocalBackupDirectoryMap::validateSelection($rule->directory_alias, $rule->relative_directory);
        $this->matcher->validatePattern($rule->filename_pattern, $rule->pattern_mode);
    }
}
