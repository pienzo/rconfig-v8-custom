<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalBackupRule;
use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only metadata preview of a configured per-device intake folder.
 * No file contents, verified receipts, check runs or backup success claims.
 */
final class ExternalBackupFilePreviewController extends Controller
{
    public function preview(ExternalBackupRule $externalBackupRule, ExternalBackupDirectoryScanner $scanner): JsonResponse
    {
        $root = (string) config('external-backups.intake_root', '');

        if ($root === '') {
            return response()->json(['message' => 'External backup intake root is not configured.'], 503);
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
            return response()->json(['message' => 'Could not safely inspect the configured backup folder.'], 422);
        }

        $files = array_map(static fn (array $file): array => [
            'filename' => $file['filename'],
            'size_bytes' => $file['size'],
            'mtime_utc' => gmdate('Y-m-d\TH:i:s\Z', $file['mtime']),
        ], $scan['files']);

        return response()->json([
            'status' => 'metadata_only',
            'directory_present' => $scan['directory_present'],
            'scanned_entries' => $scan['scanned_entries'],
            'files' => $files,
        ]);
    }
}
