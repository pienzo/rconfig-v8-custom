<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Preview synthetic basenames only: never enumerate or read a server directory.
 * The surrounding api.php auth:api middleware protects this endpoint.
 */
final class ExternalBackupPatternPreviewController extends Controller
{
    public function preview(Request $request, ExternalBackupFilenameMatcher $matcher): JsonResponse
    {
        $validated = $request->validate([
            'pattern_mode' => ['required', 'in:glob,regex'],
            'filename_pattern' => ['required', 'string', 'max:255'],
            'filenames' => ['required', 'array', 'min:1', 'max:50'],
            'filenames.*' => ['required', 'string', 'max:255'],
        ]);

        try {
            $matcher->validatePattern($validated['filename_pattern'], $validated['pattern_mode']);

            $results = [];
            foreach ($validated['filenames'] as $filename) {
                $results[] = [
                    'filename' => $filename,
                    'matches' => $matcher->matches($filename, $validated['filename_pattern'], $validated['pattern_mode']),
                ];
            }
        } catch (InvalidArgumentException|RuntimeException) {
            throw ValidationException::withMessages([
                'filename_pattern' => 'Invalid or unsafe external backup filename pattern.',
            ]);
        }

        return response()->json(['results' => $results]);
    }
}
