<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalBackupPresenceRule;
use App\Models\LocalBackupPresenceRun;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Read-only metadata presence history, not verified backup receipts. */
final class LocalBackupPresenceRunHistoryController extends Controller
{
    private const FIELDS = [
        'id',
        'rule_id',
        'device_id',
        'scheduled_at_utc',
        'deadline_at_utc',
        'status',
        'reason',
        'first_observed_at_utc',
        'last_observed_at_utc',
        'matched_filename',
    ];

    private const REASONS = [
        'directory_missing',
        'no_matching_file',
        'no_eligible_file',
        'stability_wait',
        'stability_deadline_elapsed',
        'matching_file_observed',
        'stable_matching_file_observed',
        'scan_failed',
        'invalid_configuration',
        'directory_identity_changed',
        'scan_finished_after_deadline',
        'no_observation_before_deadline',
        'observation_gap_before_deadline',
    ];

    public function forRule(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        $filters = $this->filters($request, false);

        return $this->history(
            LocalBackupPresenceRun::query()->where('rule_id', $localBackupPresenceRule->id)
                ->where('device_id', $localBackupPresenceRule->device_id),
            $filters,
            $matcher
        );
    }

    public function forDevice(Request $request, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        $filters = $this->filters($request, true);

        return $this->history(
            LocalBackupPresenceRun::query()->where('device_id', $filters['device_id']),
            $filters,
            $matcher
        );
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, bool $deviceScoped): array
    {
        $rules = [
            'status' => ['sometimes', 'required', Rule::in([
                LocalBackupPresenceRun::PENDING,
                LocalBackupPresenceRun::PRESENT,
                LocalBackupPresenceRun::ABSENT,
                LocalBackupPresenceRun::ERROR,
            ])],
            'from' => ['sometimes', 'required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'to' => ['sometimes', 'required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
        if ($deviceScoped) {
            $rules['device_id'] = ['required', 'integer', 'min:1', 'exists:devices,id'];
        }

        $query = $request->query();
        if (array_diff(array_keys($query), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['filters' => 'Unexpected local presence history filters.']);
        }

        $filters = Validator::make($query, $rules)->validate();
        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The UTC end must be later than the start.']);
        }

        return $filters;
    }

    /**
     * @param  Builder<LocalBackupPresenceRun>  $query
     * @param  array<string, mixed>  $filters
     */
    private function history(Builder $query, array $filters, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['from'])) {
            $query->where('scheduled_at_utc', '>=', $this->databaseDate($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('scheduled_at_utc', '<', $this->databaseDate($filters['to']));
        }

        $runs = $query->select(self::FIELDS)
            ->orderByDesc('scheduled_at_utc')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends(array_diff_key($filters, ['page' => true]))
            ->through(fn (LocalBackupPresenceRun $run): array => $this->representation($run, $matcher));

        return response()->json($runs->toArray() + ['evidence_type' => 'metadata_presence']);
    }

    private function databaseDate(string $utc): string
    {
        return substr($utc, 0, 10) . ' ' . substr($utc, 11, 8);
    }

    /** @return array<string, mixed> */
    private function representation(LocalBackupPresenceRun $run, ExternalBackupFilenameMatcher $matcher): array
    {
        // UTC SQL values must not be reinterpreted in the application timezone.
        // Only selected fields are emitted, including for legacy/corrupt rows.
        $fields = $run->only(self::FIELDS);
        foreach (['scheduled_at_utc', 'deadline_at_utc', 'first_observed_at_utc', 'last_observed_at_utc'] as $field) {
            $value = $run->getRawOriginal($field);
            $fields[$field] = $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
        }
        $filename = $run->matched_filename;
        $fields['matched_filename'] = is_string($filename) && $matcher->matches($filename, '*', ExternalBackupFilenameMatcher::MODE_GLOB) ? $filename : null;
        $fields['reason'] = in_array($run->reason, self::REASONS, true) ? $run->reason : null;

        return $fields;
    }
}
