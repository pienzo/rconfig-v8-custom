<?php

namespace App\Services\ExternalBackups;

use App\Models\Device;
use App\Models\LocalBackupPresenceRule;
use App\Models\Task;
use Cron\CronExpression;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal dormant-rule lifecycle. Authorization belongs to the future API.
 * Activation, mapping approval and execution are deliberately separate.
 */
final class LocalBackupPresenceRuleLifecycle
{
    public function __construct(private readonly LocalBackupPresenceCriteriaValidator $validator) {}

    /** @param array<string, mixed> $criteria */
    public function create(int $deviceId, string $name, string $cron, array $criteria): LocalBackupPresenceRule
    {
        $this->assertWritable();
        $cron = $this->validateTask($name, $cron);
        $fields = $this->validatedFields($criteria);

        return DB::transaction(function () use ($deviceId, $name, $cron, $fields): LocalBackupPresenceRule {
            // Coordinate with the Core device deletion preflight.
            Device::query()->whereKey($deviceId)->lockForUpdate()->firstOrFail();
            $task = Task::query()->create([
                'task_name' => $name,
                'task_desc' => 'Local backup file presence check (inactive)',
                'task_command' => LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
                'task_cron' => $cron,
                'task_devices' => 1,
                'task_categories' => 0,
                'task_tags' => 0,
                'task_email_notify' => 0,
                'download_report_notify' => 0,
                'verbose_download_report_notify' => 0,
                'is_system' => 0,
                'is_paused' => 1,
            ]);
            $task->device()->attach($deviceId);

            return LocalBackupPresenceRule::query()->create([
                'task_id' => $task->id,
                'device_id' => $deviceId,
                ...$fields,
            ])->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $criteria */
    public function update(int $ruleId, string $name, string $cron, array $criteria): LocalBackupPresenceRule
    {
        $this->assertWritable();
        $cron = $this->validateTask($name, $cron);
        $fields = $this->validatedFields($criteria);

        return DB::transaction(function () use ($ruleId, $name, $cron, $fields): LocalBackupPresenceRule {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $this->lockedDormantTask($rule);
            $task->update(['task_name' => $name, 'task_cron' => $cron]);
            $rule->fill($fields);
            $rule->scheduler_checked_minute_utc = null;
            $rule->scheduler_revision = null;
            $rule->save();

            return $rule->fresh();
        }, 3);
    }

    public function delete(int $ruleId): void
    {
        $this->assertWritable();

        DB::transaction(function () use ($ruleId): void {
            $rule = LocalBackupPresenceRule::query()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $task = $this->lockedDormantTask($rule);
            $rule->delete();
            $task->device()->detach();
            $task->delete();
        }, 3);
    }

    private function assertWritable(): void
    {
        if (config('rConfig.is_demo', false)) {
            throw new DomainException('Local backup rules cannot be changed in demo mode.');
        }
    }

    private function lockedDormantTask(LocalBackupPresenceRule $rule): Task
    {
        $task = $rule->task()->lockForUpdate()->firstOrFail();
        if ($rule->enabled || $rule->activated_at_utc !== null || $rule->runs()->exists()
            || $task->task_command !== LocalBackupPresenceOccurrenceClaim::TASK_COMMAND
            || ! (bool) $task->is_paused || (bool) $task->is_system
            || ! (bool) $task->task_devices || (bool) $task->task_categories || (bool) $task->task_tags
            || $task->device()->pluck('devices.id')->map(static fn ($id): int => (int) $id)->all() !== [(int) $rule->device_id]
            || $task->category()->exists() || $task->tag()->exists()
            || $task->task_email_notify || $task->download_report_notify || $task->verbose_download_report_notify) {
            throw new DomainException('Only consistent, never-activated local backup rules without history can be changed.');
        }

        return $task;
    }

    private function validateTask(string $name, string $cron): string
    {
        if (trim($name) !== $name || mb_strlen($name) < 3 || mb_strlen($name) > 255
            || preg_match('/[[:cntrl:]]/u', $name) !== 0) {
            throw new InvalidArgumentException('Invalid local backup task name.');
        }
        $cron = trim($cron);
        if (strlen($cron) > 255 || preg_match('/[[:cntrl:]]/', $cron) !== 0
            || count(explode(' ', $cron)) !== 5 || ! CronExpression::isValidExpression($cron)) {
            throw new InvalidArgumentException('Invalid local backup task schedule.');
        }

        return $cron;
    }

    /**
     * Full replacement, strict types: do not allow Eloquent casts or mass
     * assignment to silently reinterpret caller-supplied fields.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function validatedFields(array $criteria): array
    {
        $types = [
            'directory_alias' => 'string',
            'relative_directory' => 'string',
            'pattern_mode' => 'string',
            'filename_pattern' => 'string',
            'minimum_bytes' => 'int',
            'maximum_age_seconds' => 'nullable-int',
            'quiet_seconds' => 'nullable-int',
            'deadline_seconds' => 'int',
            'max_entries' => 'int',
            'max_matches' => 'int',
            'max_scan_millis' => 'int',
        ];
        if (count($criteria) !== count($types)) {
            throw new InvalidArgumentException('Unexpected local backup criteria fields.');
        }
        foreach ($types as $field => $type) {
            if (! array_key_exists($field, $criteria) || ! match ($type) {
                'string' => is_string($criteria[$field]),
                'int' => is_int($criteria[$field]),
                'nullable-int' => $criteria[$field] === null || is_int($criteria[$field]),
            }) {
                throw new InvalidArgumentException('Invalid local backup criteria field.');
            }
        }

        $this->validator->validate(new LocalBackupPresenceRule($criteria));

        return $criteria;
    }
}
