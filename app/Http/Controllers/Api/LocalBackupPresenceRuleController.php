<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalBackupPresenceRule;
use App\Services\ExternalBackups\LocalBackupPresenceCriteriaValidator;
use App\Services\ExternalBackups\LocalBackupPresenceRuleActivation;
use App\Services\ExternalBackups\LocalBackupPresenceRuleLifecycle;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Administrator rule configuration and guarded activation. No file IO. */
final class LocalBackupPresenceRuleController extends Controller
{
    private const CRITERIA = [
        'directory_alias',
        'relative_directory',
        'pattern_mode',
        'filename_pattern',
        'minimum_bytes',
        'maximum_age_seconds',
        'quiet_seconds',
        'deadline_seconds',
        'max_entries',
        'max_matches',
        'max_scan_millis',
    ];

    private const RELATIONS = ['task:id,task_name,task_cron,is_paused', 'device:id,device_name'];

    public function index(Request $request): JsonResponse
    {
        $items = LocalBackupPresenceRule::query()
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100))
            ->through(fn (LocalBackupPresenceRule $rule): array => $this->representation($rule));

        return response()->json($items->toArray() + [
            'activation_available' => $this->activationAvailable(app(LocalBackupPresenceRuleActivation::class)),
        ]);
    }

    public function show(LocalBackupPresenceRule $localBackupPresenceRule): JsonResponse
    {
        return $this->respond($localBackupPresenceRule);
    }

    public function store(Request $request, LocalBackupPresenceRuleLifecycle $lifecycle, LocalBackupPresenceCriteriaValidator $validator): JsonResponse
    {
        [$data, $criteria] = $this->validatedPayload($request, $validator);

        try {
            $rule = $lifecycle->create($data['device_id'], $data['task_name'], implode(' ', $data['task_cron']), $criteria);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['task_cron' => 'Invalid local backup task name or schedule.']);
        } catch (DomainException) {
            return response()->json(['message' => 'Local backup rule creation is not allowed.'], 409);
        }

        return $this->respond($rule, 201);
    }

    public function update(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, LocalBackupPresenceRuleLifecycle $lifecycle, LocalBackupPresenceCriteriaValidator $validator): JsonResponse
    {
        [$data, $criteria] = $this->validatedPayload($request, $validator, $localBackupPresenceRule);

        try {
            $rule = $lifecycle->update($localBackupPresenceRule->id, $data['task_name'], implode(' ', $data['task_cron']), $criteria);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['task_cron' => 'Invalid local backup task name or schedule.']);
        } catch (DomainException) {
            return response()->json(['message' => 'Local backup rule is not editable.'], 409);
        }

        return $this->respond($rule);
    }

    public function activation(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, LocalBackupPresenceRuleActivation $activation): JsonResponse
    {
        if (array_keys($request->all()) !== ['enabled'] || ! is_bool($request->input('enabled'))) {
            throw ValidationException::withMessages(['enabled' => 'Supply only a JSON boolean enabled field.']);
        }

        $enabled = $request->input('enabled');
        if ($enabled && ! $this->activationAvailable($activation)) {
            return response()->json(['message' => 'Local backup activation is not approved.'], 409);
        }

        try {
            $rule = $activation->change($localBackupPresenceRule->id, $enabled);
        } catch (InvalidArgumentException) {
            // Operator configuration errors must not expose mapped paths.
            return response()->json(['message' => 'Local backup activation configuration is invalid.'], 409);
        } catch (DomainException $exception) {
            // Domain service emits fixed, non-sensitive conflict messages.
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return $this->respond($rule);
    }

    private function activationAvailable(LocalBackupPresenceRuleActivation $activation): bool
    {
        return config('local-backup-presence.activation_api_enabled', false) === true
            && $activation->available();
    }

    public function destroy(LocalBackupPresenceRule $localBackupPresenceRule, LocalBackupPresenceRuleLifecycle $lifecycle): JsonResponse
    {
        try {
            $lifecycle->delete($localBackupPresenceRule->id);
        } catch (DomainException) {
            return response()->json(['message' => 'Local backup rule is not deletable.'], 409);
        }

        return response()->json(['message' => 'Local backup presence rule deleted.']);
    }

    private function respond(LocalBackupPresenceRule $rule, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->representation($rule->load(self::RELATIONS))], $status);
    }

    /** @return array<string, mixed> */
    private function representation(LocalBackupPresenceRule $rule): array
    {
        // Explicit projection: no root mapping, credentials, hidden checkpoints
        // or future model columns are implicitly serialized.
        return $rule->only(['id', 'task_id', 'device_id', 'enabled', ...self::CRITERIA]) + [
            'activated_at_utc' => $rule->getRawOriginal('activated_at_utc'),
            'task' => $rule->task?->only(['id', 'task_name', 'task_cron', 'is_paused']),
            'device' => $rule->device?->only(['id', 'device_name']),
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function validatedPayload(Request $request, LocalBackupPresenceCriteriaValidator $validator, ?LocalBackupPresenceRule $existing = null): array
    {
        $name = Rule::unique('tasks', 'task_name');
        if ($existing !== null) {
            $name->ignore($existing->task_id);
        }

        $rules = [
            'task_name' => ['required', 'string', 'min:3', 'max:255', $name],
            'task_cron' => ['required', 'array', 'size:5'],
            'directory_alias' => ['required', 'string', 'max:64'],
            'relative_directory' => ['present', 'nullable', 'string', 'max:4095'],
            'pattern_mode' => ['required', 'in:glob,regex'],
            'filename_pattern' => ['required', 'string', 'max:255'],
            'minimum_bytes' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'maximum_age_seconds' => ['present', 'nullable', 'integer', 'min:1', 'max:31536000'],
            'quiet_seconds' => ['present', 'nullable', 'integer', 'min:1', 'max:3600'],
            'deadline_seconds' => ['required', 'integer', 'min:60', 'max:2678400'],
            'max_entries' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_matches' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_scan_millis' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
        if ($existing === null) {
            $rules['device_id'] = ['required', 'integer', 'exists:devices,id'];
        }

        if (array_diff(array_keys($request->all()), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unexpected local backup rule fields.']);
        }

        $data = $request->validate($rules + ['task_cron.*' => ['required', 'string', 'max:100']]);
        if (! array_is_list($data['task_cron'])) {
            throw ValidationException::withMessages(['task_cron' => 'The schedule must contain five ordered fields.']);
        }
        foreach (['device_id', 'minimum_bytes', 'maximum_age_seconds', 'quiet_seconds', 'deadline_seconds', 'max_entries', 'max_matches', 'max_scan_millis'] as $field) {
            if (isset($data[$field]) && ! is_int($data[$field])) {
                throw ValidationException::withMessages([$field => 'The value must be a JSON integer.']);
            }
        }

        // Global ConvertEmptyStringsToNull middleware normalizes empty input.
        $data['relative_directory'] ??= '';
        $criteria = array_intersect_key($data, array_flip(self::CRITERIA));
        try {
            $validator->validate(new LocalBackupPresenceRule($criteria));
        } catch (InvalidArgumentException|DomainException) {
            throw ValidationException::withMessages(['criteria' => 'Invalid local backup directory, pattern or observation limits.']);
        }

        return [$data, $criteria];
    }
}
