<?php

use App\Services\Notifications\SecureBackupEmailTransport;
use Illuminate\Contracts\Console\Kernel;

if (getenv('APP_ENV') !== 'testing' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('F03_SYNTHETIC_SMTP_TLS') !== '1' || $argc !== 2) {
    exit(2);
}

$port = filter_var($argv[1], FILTER_VALIDATE_INT);
if ($port === false || $port < 1024 || $port > 65535) {
    exit(2);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    exit(2);
}

config()->set('rconfig-backup-email', [
    'enabled' => true,
    'host' => '127.0.0.1',
    'port' => $port,
    'mode' => 'smtps',
    'username' => 'synthetic-user',
    'password' => 'synthetic-password',
    'from' => 'sender@example.test',
    'recipient' => 'recipient@example.test',
]);

try {
    $prepared = app(SecureBackupEmailTransport::class)->prepare('Synthetic SMTP test', 'Synthetic body only');
    $prepared['send']();
    echo "ACCEPTED\n";
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Backup email transport outcome is uncertain.') {
        exit(3);
    }

    echo "UNCERTAIN\n";
}
