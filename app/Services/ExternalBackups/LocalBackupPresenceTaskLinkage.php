<?php

namespace App\Services\ExternalBackups;

use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** Shared eligibility predicate for the already locked rule and Core task. */
final class LocalBackupPresenceTaskLinkage
{
    public static function matches(LocalBackupPresenceRule $rule, Task $task): bool
    {
        if (DB::transactionLevel() < 1
            || (int) $task->id !== (int) $rule->task_id
            || $task->task_command !== LocalBackupPresenceOccurrenceClaim::TASK_COMMAND
            || (bool) $task->is_paused === $rule->enabled
            || (bool) $task->is_system || ! (bool) $task->task_devices
            || (bool) $task->task_categories || (bool) $task->task_tags
            || $task->task_email_notify || $task->download_report_notify || $task->verbose_download_report_notify
            || ($rule->enabled && $rule->activated_at_utc === null)) {
            return false;
        }

        // Do not use the eager-loaded relationship or a consistent-read
        // snapshot that might predate the rule/task locks. Read only the
        // current pivot rows; the rule's FK already protects its device.
        // Bound corrupt relationship reads to the minimum needed to refuse.
        $devices = $task->device();
        $ids = $devices->newPivotQuery()->limit(2)->lockForUpdate()
            ->pluck($devices->getRelatedPivotKeyName())->map(static fn ($id): int => (int) $id)->all();

        return $ids === [(int) $rule->device_id]
            && $task->category()->newPivotQuery()->lockForUpdate()->first() === null
            && $task->tag()->newPivotQuery()->lockForUpdate()->first() === null;
    }
}
