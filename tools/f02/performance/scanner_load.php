<?php

/**
 * Hosted-only metadata scanner load probe, no Laravel bootstrap, DB, queue,
 * native digest, real backup, or operating-system changes outside fixtures.
 */

use App\Services\ExternalBackups\ExternalBackupDirectoryScanner;
use App\Services\ExternalBackups\ExternalBackupFilenameMatcher;

function scannerLoadCheck(bool $ok, string $stage): void
{
    if ($ok === false) {
        throw new RuntimeException('F02 scanner load probe failed: ' . $stage);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('APP_ENV') !== 'testing' || getenv('F02_SYNTHETIC_SCANNER_LOAD') !== '1'
    || PHP_OS_FAMILY !== 'Linux' || $argc !== 1) {
    exit(2);
}

$parent = getenv('RUNNER_TEMP');
if (is_string($parent) === false || $parent === '' || $parent === '/'
    || realpath($parent) !== $parent || is_dir($parent) === false || is_writable($parent) === false) {
    exit(2);
}

$project = dirname(__DIR__, 3);
require $project . '/app/Services/ExternalBackups/ExternalBackupDirectoryIdentity.php';
require $project . '/app/Services/ExternalBackups/ExternalBackupFilenameMatcher.php';
require $project . '/app/Services/ExternalBackups/ExternalBackupDirectoryScanner.php';

umask(0077);
$root = $parent . '/rconfig-f02-scan-' . bin2hex(random_bytes(12));
scannerLoadCheck(mkdir($root, 0700), 'exclusive synthetic fixture root');
$directories = [];
$failed = false;

try {
    $scanner = new ExternalBackupDirectoryScanner(new ExternalBackupFilenameMatcher);
    $makeFixture = static function (int $device, int $entries, int $matches) use ($root, &$directories): array {
        $directory = $root . '/' . $device;
        scannerLoadCheck(mkdir($directory, 0700), 'device fixture directory');
        $directories[] = $directory;
        $expected = [];
        for ($index = 0; $index < $entries; $index++) {
            $name = sprintf($index < $matches ? 'backup_%d_%05d.cfg' : 'noise_%d_%05d.tmp', $device, $index);
            $path = $directory . '/' . $name;
            $file = fopen($path, 'xb');
            scannerLoadCheck(is_resource($file), 'exclusive fixture file');
            try {
                scannerLoadCheck(fwrite($file, 'synthetic') === 9, 'fixture bytes');
            } finally {
                fclose($file);
            }
            if ($index < $matches) {
                clearstatcache(true, $path);
                $st = lstat($path);
                scannerLoadCheck(is_array($st), 'fixture metadata');
                $expected[] = [
                    'filename' => $name,
                    'size' => 9,
                    'mtime' => $st['mtime'],
                    'ctime' => $st['ctime'],
                    'device' => $st['dev'],
                    'inode' => $st['ino'],
                ];
            }
        }

        return $expected;
    };

    $expectedByDevice = [];
    for ($device = 1; $device <= 32; $device++) {
        $expectedByDevice[$device] = $makeFixture($device, 512, 16);
    }
    $large = $makeFixture(10001, 10000, 1000);

    // Exclude fixture creation from timing and peak memory. Retain the
    // expected metadata oracle in the baseline, not in the measured delta.
    memory_reset_peak_usage();
    $baseline = memory_get_usage(false);
    $times = [];
    $started = hrtime(true);
    foreach ($expectedByDevice as $device => $expected) {
        foreach (['glob' => 'backup_*.cfg', 'regex' => 'backup_\\d+_\\d{5}\\.cfg'] as $mode => $pattern) {
            $before = hrtime(true);
            $result = $scanner->scan($root, $device, $pattern, $mode);
            $times[] = (hrtime(true) - $before) / 1000000;
            scannerLoadCheck($result === ['directory_present' => true, 'scanned_entries' => 512, 'files' => $expected], 'exact per-device metadata and isolation');
        }
    }
    // Exact documented upper entry/match bounds; no runtime cap changes.
    foreach (['glob' => 'backup_*.cfg', 'regex' => 'backup_\\d+_\\d{5}\\.cfg'] as $mode => $pattern) {
        $before = hrtime(true);
        $result = $scanner->scan($root, 10001, $pattern, $mode, 10000, 1000, 2000);
        $times[] = (hrtime(true) - $before) / 1000000;
        scannerLoadCheck($result === ['directory_present' => true, 'scanned_entries' => 10000, 'files' => $large], 'exact upper bounds metadata');
    }
    $elapsed = (hrtime(true) - $started) / 1000000;
    $peak = max(0, memory_get_peak_usage(false) - $baseline);
    sort($times);
    $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
    printf("F02_SCANNER_LOAD_METRICS devices=33 fixture_entries=26384 scans=%d scanned_entries=52768 elapsed_ms=%.2f p95_ms=%.2f max_ms=%.2f php_peak_delta_bytes=%d\n",
        count($times), $elapsed, $p95, max($times), $peak);
    echo "F02_SCANNER_LOAD_EXACT_RESULTS_OK (SYNTHETIC_ONLY)\n";

    $refuse = static function (Closure $scan, string $message): void {
        try {
            $scan();
        } catch (RuntimeException $exception) {
            scannerLoadCheck($exception->getMessage() === $message, 'exact limit refusal');

            return;
        }
        throw new RuntimeException('F02 scanner load probe failed: limit accepted partial results');
    };
    $refuse(static fn () => $scanner->scan($root, 10001, 'backup_*.cfg', 'glob', 10000, 999, 2000),
        'Too many matching external backup files.');
    $extra = $root . '/10001/extra.tmp';
    $handle = fopen($extra, 'xb');
    scannerLoadCheck(is_resource($handle), 'one extra entry');
    fclose($handle);
    $refuse(static fn () => $scanner->scan($root, 10001, 'backup_*.cfg', 'glob', 10000, 1000, 2000),
        'External backup directory scan exceeded configured limits.');
    echo "F02_SCANNER_LOAD_LIMITS_FAIL_CLOSED_OK (SYNTHETIC_ONLY)\n";
} catch (Throwable $exception) {
    $failed = true;
    $message = $exception->getMessage();
    file_put_contents('php://stderr', (str_starts_with($message, 'F02 scanner load probe failed: ')
        ? $message : 'F02 scanner load probe failed: ' . get_class($exception)) . PHP_EOL);
} finally {
    // Only the exclusive private root and directories created by this probe.
    foreach ($directories as $directory) {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isLink() || $file->isFile() === false) {
                $failed = true;

                continue;
            }
            $failed = unlink($file->getPathname()) === false || $failed;
        }
        $failed = rmdir($directory) === false || $failed;
    }
    $failed = rmdir($root) === false || $failed;
}

exit($failed ? 1 : 0);
