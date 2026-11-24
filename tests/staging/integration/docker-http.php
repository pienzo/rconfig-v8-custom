<?php

/**
 * Hosted disposable Docker integration only; never use on an existing VM.
 * No upstream entrypoint, published HTTP port, workers or provider calls.
 */

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapRuntimeGate;
use App\Services\Staging\StagingBootstrapStateService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function dockerHttpCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('Docker HTTP integration failed: ' . $label);
    }
}

$root = '/var/www/html/rconfig';
dockerHttpCheck(getenv('RCONFIG_DOCKER_HTTP_CI') === 'disposable-v1'
    && getenv('IS_DOCKER') === 'true'
    && posix_geteuid() === 1000 && posix_getegid() === 33
    && getenv('DB_HOST') === 'db' && getenv('DB_DATABASE') === 'rconfig_ci_http'
    && getenv('DB_USERNAME') === 'rconfig_ci_http' && getenv('REDIS_HOST') === 'redis'
    && getenv('MAIL_MAILER') === 'log' && getcwd() === $root, 'disposable container identity');

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

final class SyntheticDockerHttpRuntimeGate implements StagingBootstrapRuntimeGate
{
    public function __construct(
        private readonly string $storage,
        private readonly string $password,
        private readonly string $key,
    ) {}

    public function permitted(): bool
    {
        if (getenv('RCONFIG_DOCKER_HTTP_CI') !== 'disposable-v1'
            || config('app.env') !== 'production' || config('app.debug') !== false
            || config('app.key') !== $this->key || ! str_starts_with($this->key, 'base64:')
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== 'db'
            || config('database.connections.mysql.database') !== 'rconfig_ci_http'
            || config('database.connections.mysql.username') !== 'rconfig_ci_http'
            || config('database.redis.default.host') !== 'redis'
            || config('mail.default') !== 'log') {
            return false;
        }

        foreach ([
            'external-backups.runtime_enabled', 'external-backups.queue_enabled',
            'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved',
            'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
            'rconfig-telegram.enabled', 'rconfig-backup-email.enabled',
            'rconfig-whatsapp.enabled', 'rconfig-whatsapp.policy_approved',
        ] as $flag) {
            if (config($flag) !== false) {
                return false;
            }
        }

        return true;
    }

    public function attemptClaimed(): bool
    {
        $path = $this->storage . '/.staging-bootstrap-attempt';
        clearstatcache(true, $path);

        return is_dir($path) && ! is_link($path) && realpath($path) === $path
            && (fileperms($path) & 0777) === 0700;
    }

    public function replacementPassword(): ?string
    {
        return $this->permitted() && $this->attemptClaimed() ? $this->password : null;
    }

    public function postflightReady(): bool
    {
        $marker = $this->storage . '/.staging-bootstrap-complete';

        return $this->permitted() && $this->attemptClaimed()
            && ! file_exists($marker) && ! is_link($marker);
    }
}

$password = bin2hex(random_bytes(32));
$key = config('app.key');
$storage = storage_path();
$gate = new SyntheticDockerHttpRuntimeGate($storage, $password, $key);

$state = new StagingBootstrapStateService;
$stages = new LaravelStagingBootstrapStages($state, new SealDefaultAdminService, $gate);
$coordinator = new StagingBootstrapCoordinator;
dockerHttpCheck($gate->permitted() && $state->isFresh(), 'empty disposable services and disabled gates');
dockerHttpCheck($coordinator->run($storage, $stages), 'actual Core migration and seal');
dockerHttpCheck($state->isSealed($password) && $state->hasCompleteMigrationLedger(), 'sealed administrator and exact migration ledger');
dockerHttpCheck(file_get_contents($storage . '/.staging-bootstrap-complete') === 'staging-bootstrap-v1'
    && ! $coordinator->run($storage, $stages), 'completion marker and repeat refusal');

$server = new Process([
    PHP_BINARY, '-S', '127.0.0.1:8080',
    $root . '/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
], $root . '/public', null, null, 90);
$server->disableOutput();

try {
    $server->start();
    $client = new Client([
        'base_uri' => 'http://127.0.0.1:8080',
        'cookies' => true,
        'http_errors' => false,
        'allow_redirects' => false,
        'connect_timeout' => 2,
        'timeout' => 10,
    ]);
    $login = null;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        dockerHttpCheck($server->isRunning(), 'loopback server running');
        try {
            $login = $client->get('/login');
            break;
        } catch (ConnectException) {
            usleep(250000);
        }
    }
    dockerHttpCheck($login !== null && $login->getStatusCode() === 200, 'login HTML');
    $html = (string) $login->getBody();
    dockerHttpCheck(preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $matches) === 1, 'CSRF meta token');
    dockerHttpCheck(str_contains($html, 'login-component') && str_contains($html, '/build/'), 'compiled login assets');
    $headers = ['Accept' => 'application/json', 'Referer' => 'http://127.0.0.1:8080/login'];
    $api = '/api/settings/whatsapp-template-drafts';
    dockerHttpCheck($client->get($api, ['headers' => $headers])->getStatusCode() === 401, 'anonymous API denied');

    $response = $client->post('/login', [
        'form_params' => [
            '_token' => $matches[1],
            'username' => 'staging-admin@example.invalid',
            'password' => $password,
        ],
    ]);
    dockerHttpCheck($response->getStatusCode() === 302
        && str_ends_with($response->getHeaderLine('Location'), '/dashboard'), 'real local administrator login');
    dockerHttpCheck($client->get('/dashboard')->getStatusCode() === 200, 'authenticated dashboard HTML');

    foreach ([
        $api => 'drafts',
        '/api/settings/notification-templates' => 'templates',
        '/api/tasks/external-backup-rules' => 'data',
    ] as $endpoint => $field) {
        $response = $client->get($endpoint, ['headers' => $headers]);
        dockerHttpCheck($response->getStatusCode() === 200, 'authenticated custom API ' . $field);
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        dockerHttpCheck(is_array($data[$field] ?? null), 'custom API shape ' . $field);
    }

    dockerHttpCheck(DB::table('custom_backup_notification_deliveries')->count() === 0, 'no notification delivery');
    echo "CUSTOM_DOCKER_BOOTSTRAP_HTTP_AUTH_OK (SYNTHETIC_ONLY)\n";
} finally {
    if ($server->isRunning()) {
        $server->stop(2);
    }
}
