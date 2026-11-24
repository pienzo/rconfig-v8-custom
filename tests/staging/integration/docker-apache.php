<?php

/**
 * Hosted disposable role integration only. Never mount real credentials or
 * invoke this driver on a persistent VM. The installed Core entrypoint is not used.
 */

use App\Services\Staging\LaravelStagingBootstrapStages;
use App\Services\Staging\SealDefaultAdminService;
use App\Services\Staging\StagingBootstrapContainerGate;
use App\Services\Staging\StagingBootstrapCoordinator;
use App\Services\Staging\StagingBootstrapStateService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function apacheCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('Staging Apache integration failed: ' . $label);
    }
}

$root = '/var/www/html/rconfig';
$phase = getenv('RCONFIG_ROLE_PROBE_PHASE');
apacheCheck(getenv('GITHUB_ACTIONS') === 'true' && getenv('IS_DOCKER') === 'true'
    && in_array($phase, ['bootstrap', 'http'], true)
    && posix_geteuid() === 1000 && posix_getegid() === 33
    && getcwd() === $root, 'disposable identity');

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

apacheCheck(config('app.env') === 'production' && config('app.debug') === false
    && config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === 'db'
    && config('database.connections.mysql.database') === 'rconfig_staging'
    && config('database.connections.mysql.username') === 'rconfig_staging'
    && config('database.redis.default.host') === 'redis'
    && config('mail.default') === 'log', 'effective isolated config');

foreach ([
    'external-backups.runtime_enabled', 'external-backups.queue_enabled',
    'external-backups.receipt_acceptance_enabled', 'external-backups.finalization_approved',
    'custom-backup-notifications.enabled', 'rconfig-native-backup-notifications.enabled',
    'rconfig-telegram.enabled', 'rconfig-backup-email.enabled',
    'rconfig-whatsapp.enabled', 'rconfig-whatsapp.policy_approved',
] as $flag) {
    apacheCheck(config($flag) === false, 'effective disabled feature ' . $flag);
}

$state = new StagingBootstrapStateService;
$secret = '/run/secrets/staging_admin_password';
apacheCheck(is_file($secret) && ! is_link($secret)
    && fileowner($secret) === 0 && filegroup($secret) === 33
    && (fileperms($secret) & 0777) === 0640, 'protected synthetic admin secret');
$password = file_get_contents($secret);
apacheCheck(is_string($password) && preg_match('/\A[a-f0-9]{64}\n\z/', $password) === 1, 'synthetic secret format');
$password = substr($password, 0, 64);

if ($phase === 'bootstrap') {
    $gate = new StagingBootstrapContainerGate;
    $stages = new LaravelStagingBootstrapStages($state, new SealDefaultAdminService, $gate);
    $coordinator = new StagingBootstrapCoordinator;
    apacheCheck($gate->permitted() && $state->isFresh(), 'actual strict runtime gate and fresh state');
    apacheCheck($coordinator->run(storage_path(), $stages), 'actual strict Core bootstrap');
    apacheCheck($state->isSealed($password), 'administrator sealed');
    apacheCheck(! $coordinator->run(storage_path(), $stages), 'bootstrap replay refused');
    echo "STAGING_STRICT_DOCKER_BOOTSTRAP_OK (SYNTHETIC_ONLY)\n";

    exit(0);
}

apacheCheck($state->isSealed($password), 'persisted seal visible to independent client');
apacheCheck(file_get_contents(storage_path('.staging-bootstrap-complete')) === 'staging-bootstrap-v1', 'persistent completion marker');

$client = new Client([
    'base_uri' => 'http://127.0.0.1',
    'cookies' => true,
    'http_errors' => false,
    'allow_redirects' => false,
    'connect_timeout' => 2,
    'timeout' => 10,
]);
$login = null;
for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        $login = $client->get('/login');
        break;
    } catch (ConnectException) {
        sleep(1);
    }
}
apacheCheck($login !== null && $login->getStatusCode() === 200, 'actual Apache login HTML');
$html = (string) $login->getBody();
apacheCheck(preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $matches) === 1, 'CSRF token');
apacheCheck(str_contains($html, 'login-component') && str_contains($html, '/build/'), 'compiled login HTML');
apacheCheck(preg_match('~(?:src|href)="([^"]*/build/[^"]+\.js)"~', $html, $asset) === 1, 'compiled asset URL');
$assetPath = parse_url($asset[1], PHP_URL_PATH);
apacheCheck(is_string($assetPath) && str_starts_with($assetPath, '/build/'), 'local asset path');
$assetResponse = $client->get($assetPath);
apacheCheck($assetResponse->getStatusCode() === 200 && $assetResponse->getBody()->getSize() > 0, 'Apache compiled asset served');

$headers = ['Accept' => 'application/json', 'Referer' => 'http://127.0.0.1/login'];
$api = '/api/settings/whatsapp-template-drafts';
apacheCheck($client->get($api, ['headers' => $headers])->getStatusCode() === 401, 'anonymous API denied');
$response = $client->post('/login', [
    'form_params' => [
        '_token' => $matches[1],
        'username' => 'staging-admin@example.invalid',
        'password' => $password,
    ],
]);
apacheCheck($response->getStatusCode() === 302
    && str_ends_with($response->getHeaderLine('Location'), '/dashboard'), 'actual Apache administrator login');
apacheCheck($client->get('/dashboard')->getStatusCode() === 200, 'actual Apache dashboard');

foreach ([
    $api => 'drafts',
    '/api/settings/notification-templates' => 'templates',
    '/api/tasks/external-backup-rules' => 'data',
] as $endpoint => $field) {
    $response = $client->get($endpoint, ['headers' => $headers]);
    apacheCheck($response->getStatusCode() === 200, 'authenticated API ' . $field);
    $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    apacheCheck(is_array($data[$field] ?? null), 'API shape ' . $field);
}
apacheCheck(DB::table('external_backup_check_runs')->count() === 0, 'no F02 runs');
apacheCheck(DB::table('custom_backup_notification_deliveries')->count() === 0, 'no notification delivery');
echo "STAGING_APACHE_AUTHENTICATED_HTTP_OK (SYNTHETIC_ONLY)\n";
