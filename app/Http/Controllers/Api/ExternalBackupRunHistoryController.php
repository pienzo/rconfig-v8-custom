<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalBackupCheckRun;
use App\Models\ExternalBackupRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated F02 execution history, limited to the selected rule.
 *
 * Only persisted run metadata is exposed. File receipt identities, backup
 * contents, internal configuration and the verification checkpoint remain private.
 */
final class ExternalBackupRunHistoryController extends Controller
{
    public function index(ExternalBackupRule $externalBackupRule, Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $runs = ExternalBackupCheckRun::query()
            ->where('rule_id', $externalBackupRule->id)
            ->select([
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
            ])
            ->withExists('receipt')
            ->orderByDesc('scheduled_at_utc')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($runs);
    }
}
