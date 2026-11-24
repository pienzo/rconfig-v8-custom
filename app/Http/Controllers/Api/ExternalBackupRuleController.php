<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalBackupRule;
use App\Models\Task;
use App\Rules\TaskCronIsCorrectFormat;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\ExternalBackupRuleActivation;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Configure dormant F02 rules without dispatching downloads or file checks.
 * A separate reviewed job will eventually activate and schedule these tasks.
 */
final class ExternalBackupRuleController extends Controller
{
    public const TASK_COMMAND = 'rconfig:check-external-backup';

    public function index(Request $request): JsonResponse
    {
        $items = ExternalBackupRule::query()
            ->with(['task:id,task_name,task_cron,is_paused', 'device:id,device_name'])
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json($items->toArray() + [
            'can_manage_activation' => $request->user()?->isAdmin() === true,
            'activation_available' => app(ExternalBackupRuleActivation::class)->available(),
        ]);
    }

    public function store(Request $request, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        $data = $this->validatedRule($request, $matcher);

        $rule = DB::transaction(function () use ($data): ExternalBackupRule {
            $task = Task::query()->create([
                'task_name' => $data['task_name'],
                'task_desc' => 'External filesystem backup receipt check (inactive)',
                'task_command' => self::TASK_COMMAND,
                'task_cron' => implode(' ', $data['task_cron']),
                'task_devices' => 1,
                'task_categories' => 0,
                'task_tags' => 0,
                'task_email_notify' => 0,
                'download_report_notify' => 0,
                'verbose_download_report_notify' => 0,
                'is_system' => 0,
                'is_paused' => 1,
            ]);
            $task->device()->attach($data['device_id']);

            return ExternalBackupRule::query()->create([
                'task_id' => $task->id,
                'device_id' => $data['device_id'],
                ...$this->ruleFields($data),
                'enabled' => false,
            ]);
        });

        return response()->json(['data' => $rule->load(['task:id,task_name,task_cron,is_paused', 'device:id,device_name'])], 201);
    }

    public function update(Request $request, ExternalBackupRule $externalBackupRule, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        // Updating a rule once observations exist may reinterpret historic runs.
        if ($externalBackupRule->runs()->exists()) {
            return response()->json(['message' => 'Rules with check history cannot be edited.'], 409);
        }

        $data = $this->validatedRule($request, $matcher, $externalBackupRule);

        $rule = DB::transaction(function () use ($externalBackupRule, $data): ExternalBackupRule {
            $rule = ExternalBackupRule::query()->whereKey($externalBackupRule->id)->lockForUpdate()->firstOrFail();
            if ($rule->enabled || $rule->runs()->exists()) {
                abort(409, 'Rule is active or has check history.');
            }

            $rule->task()->update([
                'task_name' => $data['task_name'],
                'task_cron' => implode(' ', $data['task_cron']),
            ]);
            $rule->update($this->ruleFields($data));

            return $rule;
        });

        return response()->json(['data' => $rule->load(['task:id,task_name,task_cron,is_paused', 'device:id,device_name'])]);
    }

    public function activation(Request $request, ExternalBackupRule $externalBackupRule, ExternalBackupRuleActivation $activation): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $rule = $activation->change($externalBackupRule->id, (bool) $data['enabled']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $rule->load(['task:id,task_name,task_cron,is_paused', 'device:id,device_name'])]);
    }

    public function destroy(ExternalBackupRule $externalBackupRule): JsonResponse
    {
        DB::transaction(function () use ($externalBackupRule): void {
            $rule = ExternalBackupRule::query()->whereKey($externalBackupRule->id)->lockForUpdate()->firstOrFail();
            if ($rule->enabled || $rule->runs()->exists()) {
                abort(409, 'Active or previously executed rules cannot be deleted.');
            }

            $task = $rule->task()->firstOrFail();
            if ($task->task_command !== self::TASK_COMMAND || ! $task->is_paused) {
                abort(409, 'The linked Core task is not an inactive F02 task.');
            }

            $rule->delete();
            $task->device()->detach();
            $task->delete();
        });

        return response()->json(['message' => 'External backup rule deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRule(Request $request, ExternalBackupFilenameMatcher $matcher, ?ExternalBackupRule $existing = null): array
    {
        $name = Rule::unique('tasks', 'task_name');
        if ($existing !== null) {
            $name->ignore($existing->task_id);
        }

        $rules = [
            'task_name' => ['required', 'string', 'min:3', 'max:255', $name],
            'task_cron' => ['required', 'array', 'size:5', new TaskCronIsCorrectFormat],
            'task_cron.*' => ['required', 'string', 'max:100'],
            'pattern_mode' => ['required', 'in:glob,regex'],
            'filename_pattern' => ['required', 'string', 'max:255'],
            'minimum_bytes' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'quiet_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'deadline_seconds' => ['required', 'integer', 'min:60', 'max:2678400'],
            'enabled' => ['prohibited'],
            'is_paused' => ['prohibited'],
        ];

        if ($existing === null) {
            $rules['device_id'] = ['required', 'integer', 'exists:devices,id'];
        } else {
            $rules['device_id'] = ['prohibited'];
        }

        $data = $request->validate($rules);

        if ($data['quiet_seconds'] >= $data['deadline_seconds']) {
            throw ValidationException::withMessages([
                'quiet_seconds' => 'Quiet interval must be shorter than the deadline.',
            ]);
        }

        try {
            $matcher->validatePattern($data['filename_pattern'], $data['pattern_mode']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'filename_pattern' => 'Invalid external backup filename pattern.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ruleFields(array $data): array
    {
        return [
            'pattern_mode' => $data['pattern_mode'],
            'filename_pattern' => $data['filename_pattern'],
            'minimum_bytes' => $data['minimum_bytes'],
            'quiet_seconds' => $data['quiet_seconds'],
            'deadline_seconds' => $data['deadline_seconds'],
        ];
    }
}
