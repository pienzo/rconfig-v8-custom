<?php

namespace App\Services\ExternalBackups;

use InvalidArgumentException;
use RuntimeException;

/**
 * Opaque per-device receipt identity derived from a native descriptor digest.
 * This is NOT proof of complete upload or trustworthy device provenance.
 *
 * A dedicated HMAC key survives APP_KEY rotation; a run identifier is never
 * included, so an unchanged file cannot be reused for another occurrence.
 */
final class ExternalBackupReceiptTokenFactory
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function fromDescriptorEvidence(int $deviceId, string $filename, array $evidence): string
    {
        if ($deviceId < 1 || $filename === '' || $filename === '.' || $filename === '..'
            || strlen($filename) > 255 || str_contains($filename, '/') || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) !== 0 || preg_match('//u', $filename) !== 1) {
            throw new InvalidArgumentException('Invalid F02 device or basename.');
        }

        if (($evidence['state'] ?? null) !== 'descriptor_checked_only'
            || ! is_string($evidence['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $evidence['sha256']) !== 1) {
            throw new InvalidArgumentException('Missing valid descriptor hash evidence.');
        }

        foreach (['size', 'device', 'inode', 'mtime', 'ctime', 'mtime_ns', 'ctime_ns'] as $field) {
            if (! is_int($evidence[$field] ?? null) || $evidence[$field] < 0) {
                throw new InvalidArgumentException('Invalid descriptor identity metadata.');
            }
        }

        if ($evidence['size'] < 1 || $evidence['inode'] < 1
            || $evidence['mtime_ns'] >= 1000000000 || $evidence['ctime_ns'] >= 1000000000) {
            throw new InvalidArgumentException('Unsafe descriptor identity metadata.');
        }

        $encodedKey = config('external-backups.receipt_hmac_key', '');
        if (! is_string($encodedKey) || ! str_starts_with($encodedKey, 'base64:')) {
            throw new RuntimeException('F02 receipt key is not configured.');
        }

        $key = base64_decode(substr($encodedKey, 7), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid F02 receipt key length.');
        }

        $identity = json_encode([
            'version' => 1,
            'device_id' => $deviceId,
            'filename' => $filename,
            'fs_device' => $evidence['device'],
            'inode' => $evidence['inode'],
            'size' => $evidence['size'],
            'mtime' => $evidence['mtime'],
            'mtime_ns' => $evidence['mtime_ns'],
            'ctime' => $evidence['ctime'],
            'ctime_ns' => $evidence['ctime_ns'],
            'sha256' => $evidence['sha256'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', "rconfig-f02-file-receipt-v1\0" . $identity, $key);
    }
}
