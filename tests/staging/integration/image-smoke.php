<?php

/**
 * Executed through stdin only in the offline, unprivileged hosted-CI image.
 * No Laravel bootstrap, real database, actual provider or service startup.
 */

use App\Http\Controllers\Api\WhatsAppTemplateDraftController;
use App\Services\ExternalBackups\ExternalBackupRuleActivation;
use App\Services\Notifications\ExternalBackupWhatsAppDispatcher;
use App\Services\Notifications\NativeBackupNotificationDispatcher;
use Symfony\Component\Process\Process;

function imageCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('Image smoke refused: ' . $label);
    }
}

$root = '/var/www/html/rconfig';
imageCheck(getenv('IS_DOCKER') === 'true' && getcwd() === $root, 'image identity');
imageCheck(posix_geteuid() === 1000 && posix_getegid() === 33, 'unprivileged identity');
imageCheck(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4, 'PHP version');
imageCheck(! is_file($root . '/.env') && ! is_file($root . '/.env.testing'), 'no packaged runtime configuration');
imageCheck(! is_writable($root . '/app'), 'immutable application files');
require $root . '/vendor/autoload.php';

foreach ([
    'pdo_mysql', 'gd', 'ldap', 'pcntl', 'redis', 'snmp', 'gmp', 'intl', 'openssl',
] as $extension) {
    imageCheck(extension_loaded($extension), 'PHP extension ' . $extension);
}
foreach ([
    ExternalBackupRuleActivation::class,
    ExternalBackupWhatsAppDispatcher::class,
    NativeBackupNotificationDispatcher::class,
    WhatsAppTemplateDraftController::class,
] as $class) {
    imageCheck(class_exists($class), 'production autoload ' . $class);
}

foreach ([
    'external-backups' => ['runtime_enabled', 'queue_enabled', 'receipt_acceptance_enabled', 'finalization_approved'],
    'custom-backup-notifications' => ['enabled'],
    'rconfig-native-backup-notifications' => ['enabled'],
    'rconfig-telegram' => ['enabled'],
    'rconfig-backup-email' => ['enabled'],
    'rconfig-whatsapp' => ['enabled', 'policy_approved'],
] as $name => $flags) {
    $settings = require $root . '/config/' . $name . '.php';
    foreach ($flags as $flag) {
        imageCheck(($settings[$flag] ?? null) === false, 'disabled default ' . $name . '.' . $flag);
    }
}

$build = $root . '/public/build';
$manifest = json_decode((string) file_get_contents($build . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
imageCheck(is_array($manifest) && $manifest !== [], 'Vite manifest');
$entries = 0;
foreach ($manifest as $chunk) {
    imageCheck(is_array($chunk), 'manifest entry');
    if (($chunk['isEntry'] ?? false) === true) {
        $entries++;
    }
    $assets = array_merge([$chunk['file'] ?? ''], $chunk['css'] ?? [], $chunk['assets'] ?? []);
    foreach ($assets as $asset) {
        $resolved = is_string($asset) ? realpath($build . '/' . $asset) : false;
        imageCheck($resolved !== false && str_starts_with($resolved, $build . '/')
            && is_file($resolved) && filesize($resolved) > 0, 'packaged Vite asset');
    }
}
imageCheck($entries > 0, 'Vite entrypoints');

foreach ([$root . '/storage', '/usr/local/share/rconfig/storage-skeleton'] as $storage) {
    imageCheck((fileperms($storage . '/app/rconfig/data') & 0777) === 0750, 'private configuration directory');
    foreach (['default.yml', 'ios-ssh-enable.yml', 'ios-ssh-noenable.yml', 'ios-telnet-enable.yml', 'ios-telnet-noenable.yml'] as $template) {
        $path = $storage . '/app/rconfig/templates/' . $template;
        imageCheck(is_file($path) && filesize($path) > 0, 'bundled Core template');
    }
}

$helper = '/usr/local/libexec/rconfig-f02-safe-digest';
imageCheck(is_file($helper) && ! is_link($helper) && is_executable($helper)
    && fileowner($helper) === 0 && filegroup($helper) === 0
    && (fileperms($helper) & 07777) === 0755 && ! is_writable($helper), 'native helper permissions');

$folder = '/tmp/rconfig-image-smoke-' . bin2hex(random_bytes(8));
imageCheck(mkdir($folder, 0700) && mkdir($folder . '/1', 0700), 'private synthetic inbox');
$file = $folder . '/1/synthetic.cfg';
$bytes = "synthetic backup fixture\n";
try {
    imageCheck(file_put_contents($file, $bytes) === strlen($bytes), 'fixture write');
    $stat = lstat($file);
    imageCheck(is_array($stat), 'fixture metadata');
    $args = [$helper, $folder, '1', 'synthetic.cfg', (string) $stat['dev'], (string) $stat['ino'],
        (string) $stat['size'], (string) $stat['mtime'], (string) $stat['ctime'], '1', '4096'];
    $process = new Process($args, null, null, null, 12);
    $process->mustRun();
    $evidence = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
    imageCheck(($evidence['state'] ?? null) === 'descriptor_checked_only'
        && ($evidence['sha256'] ?? null) === hash('sha256', $bytes), 'real packaged descriptor digest');

    imageCheck(unlink($file) && symlink('/etc/passwd', $file), 'synthetic substitution');
    $process = new Process($args, null, null, null, 12);
    $process->run();
    imageCheck(! $process->isSuccessful() && $process->getOutput() === '', 'symlink rejection without evidence');
} finally {
    if (is_link($file) || is_file($file)) {
        unlink($file);
    }
    rmdir($folder . '/1');
    rmdir($folder);
}

echo "CUSTOM_DOCKER_OFFLINE_SMOKE_OK (NO_SERVICES_NO_PROVIDER_CALLS)\n";
