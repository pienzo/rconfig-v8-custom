<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalBackupRule;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupObservationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Two-request F02 verification of filesystem metadata only.
 * Never writes a run, claims a file receipt or declares backup SUCCESS.
 */
final class ExternalBackupManualProbeController extends Controller
{
    public function check(
        ExternalBackupRule $externalBackupRule,
        Request $request,
        ExternalBackupDirectoryScanner $scanner,
        ExternalBackupObservationPolicy $policy
    ): JsonResponse {
        $input = $request->validate(['checkpoint' => ['nullable', 'string', 'max:8192']]);

        $root = (string) config('external-backups.intake_root', '');
        if ($root === '') {
            return response()->json(['message' => 'External backup intake root is not configured.'], 503);
        }

        $quiet = (int) $externalBackupRule->quiet_seconds;
        $window = (int) $externalBackupRule->deadline_seconds;
        if ($quiet < 1 || $quiet > 3600 || $window <= $quiet || $window > 31 * 86400) {
            return response()->json(['message' => 'Invalid F02 observation window.'], 422);
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond()->getTimestamp();
        $fingerprint = $this->fingerprint($externalBackupRule);
        $previous = null;

        if (isset($input['checkpoint']) && $input['checkpoint'] !== '') {
            try {
                $previous = json_decode(Crypt::decryptString($input['checkpoint']), true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                throw ValidationException::withMessages(['checkpoint' => 'Invalid verification checkpoint.']);
            }

            if (is_array($previous) === false || ($previous['v'] ?? null) !== 1
                || ($previous['rule_id'] ?? null) !== $externalBackupRule->id
                || is_string($previous['fingerprint'] ?? null) === false
                || hash_equals($fingerprint, $previous['fingerprint']) === false
                || is_int($previous['observed_at'] ?? null) === false
                || is_int($previous['scheduled_at'] ?? null) === false
                || is_int($previous['deadline_at'] ?? null) === false
                || is_array($previous['scan'] ?? null) === false
                || $previous['observed_at'] < $previous['scheduled_at']
                || $previous['observed_at'] >= $previous['deadline_at']
                || $now < $previous['observed_at']
                || $window !== $previous['deadline_at'] - $previous['scheduled_at']) {
                throw ValidationException::withMessages(['checkpoint' => 'Outdated or invalid verification checkpoint.']);
            }

            $wait = $quiet - ($now - $previous['observed_at']);
            if ($wait > 0) {
                return $this->answer('pending', 'quiet_interval', $input['checkpoint'], $wait, $previous['scheduled_at'], $previous['deadline_at']);
            }
        }

        try {
            $scan = $scanner->scan(
                $root,
                (int) $externalBackupRule->device_id,
                $externalBackupRule->filename_pattern,
                $externalBackupRule->pattern_mode,
                (int) $externalBackupRule->max_entries,
                (int) $externalBackupRule->max_matches,
                (int) $externalBackupRule->max_scan_millis
            );
        } catch (InvalidArgumentException|RuntimeException) {
            return response()->json(['message' => 'Could not safely inspect the backup folder.'], 422);
        }

        $snapshot = $this->compactSnapshot($scan);

        if ($previous === null) {
            // Manual window: look back for existing uploads while retaining
            // more than quiet_seconds for the second observation.
            $scheduled = $now - intdiv($window - $quiet, 2);
            $deadline = $scheduled + $window;
            $token = $this->seal($externalBackupRule->id, $fingerprint, $scheduled, $deadline, $now, $snapshot);

            return $this->answer('pending', 'first_observation', $token, $quiet, $scheduled, $deadline);
        }

        try {
            $result = $policy->evaluate(
                $previous['scan'],
                $scan,
                $previous['scheduled_at'],
                $previous['deadline_at'],
                $previous['observed_at'],
                $now,
                $quiet,
                (int) $externalBackupRule->minimum_bytes,
                (int) $externalBackupRule->clock_skew_seconds
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['checkpoint' => 'Invalid saved observation.']);
        }

        $pending = $result['status'] === ExternalBackupObservationPolicy::STATUS_PENDING;
        $token = $pending
            ? $this->seal($externalBackupRule->id, $fingerprint, $previous['scheduled_at'], $previous['deadline_at'], $now, $snapshot)
            : null;

        return $this->answer(
            $result['status'], $result['reason'], $token, $pending ? $quiet : 0,
            $previous['scheduled_at'], $previous['deadline_at'], $result['filename']
        );
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function compactSnapshot(array $scan): array
    {
        $files = $scan['files'];
        usort($files, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['filename'], $b['filename']));

        return [
            'directory_present' => $scan['directory_present'],
            'scanned_entries' => $scan['scanned_entries'],
            'files' => array_slice($files, 0, 1),
        ];
    }

    private function fingerprint(ExternalBackupRule $rule): string
    {
        return hash('sha256', json_encode([
            $rule->id, $rule->device_id, $rule->pattern_mode, $rule->filename_pattern,
            $rule->minimum_bytes, $rule->quiet_seconds, $rule->deadline_seconds,
            $rule->clock_skew_seconds, $rule->max_entries, $rule->max_matches, $rule->max_scan_millis,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $snapshot */
    private function seal(int $ruleId, string $fingerprint, int $scheduled, int $deadline, int $observed, array $snapshot): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'rule_id' => $ruleId,
            'fingerprint' => $fingerprint,
            'scheduled_at' => $scheduled,
            'deadline_at' => $deadline,
            'observed_at' => $observed,
            'scan' => $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function answer(string $status, string $reason, ?string $token, int $retrySeconds, int $scheduled, int $deadline, ?string $filename = null): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'reason' => $reason,
            'filename' => $filename,
            'checkpoint' => $token,
            'retry_after_seconds' => $retrySeconds,
            'window_start_utc' => gmdate(DATE_ATOM, $scheduled),
            'window_end_utc' => gmdate(DATE_ATOM, $deadline),
            'verified_success' => false,
            'persistent_check_run' => false,
        ]);
    }
}
