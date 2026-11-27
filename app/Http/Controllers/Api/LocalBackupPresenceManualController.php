<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalBackupPresenceManualSession;
use App\Models\LocalBackupPresenceRule;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use App\Services\ExternalBackups\LocalBackupPresenceManualReconciler;
use App\Services\ExternalBackups\LocalBackupPresenceManualSessions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** HTTP admission and polling only. Never scan or dispatch from this role. */
final class LocalBackupPresenceManualController extends Controller
{
    private const REASONS = [
        'pending' => ['stability_wait'],
        'present' => ['matching_file_observed', 'stable_matching_file_observed'],
        'absent' => ['directory_missing', 'no_matching_file', 'no_eligible_file'],
        'error' => ['invalid_configuration', 'scan_failed', 'directory_identity_changed',
            'manual_probe_changed_or_invalid', 'manual_probe_expired', 'observation_clock_moved_backwards'],
    ];

    public static function available(): bool
    {
        return config('local-backup-presence.manual_api_enabled', false) === true
            && LocalBackupPresenceManualReconciler::enabled();
    }

    public function start(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, LocalBackupPresenceManualSessions $sessions): JsonResponse
    {
        $this->emptyPayload($request);
        if (! self::available()) {
            return $this->unavailable();
        }

        try {
            $session = $sessions->start($localBackupPresenceRule->id);
        } catch (DomainException|InvalidArgumentException) {
            return $this->reply(['message' => 'Manual local backup admission refused.'], 409);
        }

        return $this->sessionResponse($session, 202);
    }

    public function show(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, string $sessionId): JsonResponse
    {
        $this->emptyPayload($request);
        if (! self::available()) {
            return $this->unavailable();
        }

        if (! Str::isUuid($sessionId)) {
            return $this->missing();
        }

        $session = LocalBackupPresenceManualSession::query()->whereKey($sessionId)
            ->where('rule_id', $localBackupPresenceRule->id)
            ->where('expires_at', '>', CarbonImmutable::now('UTC')->getTimestamp())->first();

        return $session === null ? $this->missing() : $this->sessionResponse($session);
    }

    public function restart(Request $request, LocalBackupPresenceRule $localBackupPresenceRule, string $sessionId, LocalBackupPresenceManualSessions $sessions): JsonResponse
    {
        $this->emptyPayload($request);
        if (! self::available()) {
            return $this->unavailable();
        }
        if (! Str::isUuid($sessionId)) {
            return $this->missing();
        }

        try {
            $session = $sessions->restart($localBackupPresenceRule->id, $sessionId);
        } catch (DomainException|InvalidArgumentException) {
            return $this->reply(['message' => 'Manual local backup restart refused.'], 409);
        }

        return $this->sessionResponse($session, 202);
    }

    private function emptyPayload(Request $request): void
    {
        $raw = trim($request->getContent());
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if ($request->all() !== [] || ! is_array($decoded) || $decoded !== []) {
            throw ValidationException::withMessages(['payload' => 'No manual local backup parameters are accepted.']);
        }
    }

    private function unavailable(): JsonResponse
    {
        return $this->reply(['message' => 'Manual local backup API is not approved.'], 409);
    }

    private function missing(): JsonResponse
    {
        return $this->reply(['message' => 'Manual local backup session not found.'], 404);
    }

    /** @param array<string, mixed> $body */
    private function reply(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store, private');
    }

    private function sessionResponse(LocalBackupPresenceManualSession $session, int $status = 200): JsonResponse
    {
        $state = $session->status;
        $result = $session->result;
        $projected = null;
        if (isset(self::REASONS[$state]) && is_array($result) && ($result['status'] ?? null) === $state) {
            $reason = $result['reason'] ?? null;
            $filename = $result['filename'] ?? null;
            $observed = $result['observed_at_utc'] ?? null;
            $validReason = in_array($reason, self::REASONS[$state], true);
            $projected = [
                'reason' => $validReason ? $reason : null,
                'filename' => $validReason && in_array($state, ['pending', 'present'], true)
                    && is_string($filename) && (new ExternalBackupFilenameMatcher)->matches($filename, '*', 'glob') ? $filename : null,
                'observed_at_utc' => is_string($observed) && preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\\z/', $observed) === 1 ? $observed : null,
            ];
        }

        return $this->reply(['data' => [
            'id' => $session->id,
            'rule_id' => $session->rule_id,
            'status' => $state,
            'requested_at_utc' => $this->utc($session->requested_at),
            'expires_at_utc' => $this->utc($session->expires_at),
            'next_observation_at_utc' => $this->utc($session->next_observation_at),
            'result' => $projected,
            'evidence_type' => 'metadata_presence',
            'persistent_check_run' => false,
            'verified_success' => false,
        ]], $status);
    }

    private function utc(?int $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d\\TH:i:s\\Z');
    }
}
