<?php

use Illuminate\Support\Str;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

// F02 has no operational job or dispatch path yet. Explicitly opt in only
// during authorized staging. These limits are candidates, not approved values.
$f02Supervisors = env('RCONFIG_F02_QUEUE_ENABLED', false) === true
    ? [
        'HorizonExternalBackups' => [
            'connection' => 'redis_external_backups',
            'queue' => ['ExternalBackupChecks'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'tries' => 3,
            'backoff' => [10, 30, 60],
            'timeout' => 90,
        ],
    ]
    : [];

$notificationSupervisors = env('RCONFIG_CUSTOM_BACKUP_NOTIFICATIONS_ENABLED', false) === true
    ? [
        'HorizonBackupNotifications' => [
            'connection' => 'redis_backup_notifications',
            'queue' => ['BackupNotifications'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'tries' => 1,
            'timeout' => 60,
        ],
    ]
    : [];

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
     */

    'domain' => env('HORIZON_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
     */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
     */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
     */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
     */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
     */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
     */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 1440,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        MakeSearchable::class,
        RemoveFromSearch::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
     */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
     */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
     */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    / If i change this to auto, policy jobs don't work
    / 'balance' => 'simple',
     */

    'environments' => [
        'production' => [
            'HorizonOne' => [
                'connection' => 'redis',
                'queue' => ['PolicyCompliance', 'rConfigDefault'],
                'balance' => 'auto',
                'minProcesses' => (int) env('HORIZON_MIN_PROCESS', 1),
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESS', 5),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'autoScalingStrategy' => 'time',
                'tries' => 1,
                'timeout' => (int) env('HORIZON_PROD_TIMEOUT', 60),
            ],
            'HorizonTwo' => [
                'connection' => 'redis',
                'queue' => ['TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'],
                'balance' => 'auto',
                'minProcesses' => (int) env('HORIZON_MIN_PROCESS', 1),
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESS', 5),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 1,
                'timeout' => 86400, // 1 day
            ],
            ...$f02Supervisors,
            ...$notificationSupervisors,
        ],

        'local' => [
            'HorizonOne' => [
                'connection' => 'redis',
                // 'queue' => ['default', 'policy-compliance', 'lastqueue'],
                'queue' => ['PolicyCompliance', 'rConfigDefault'],
                'balance' => 'auto',
                'minProcesses' => (int) env('HORIZON_MIN_PROCESS', 1),
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESS', 5),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'autoScalingStrategy' => 'time',
                'tries' => 1,
                'timeout' => (int) env('HORIZON_PROD_TIMEOUT', 60),
            ],
            'HorizonTwo' => [
                'connection' => 'redis',
                // 'queue' => ['downloadqueue'],
                'queue' => ['TaskDownloadQueue', 'TaskSnippetQueue', 'ManualDownloadQueue'],
                'balance' => 'auto',
                'minProcesses' => (int) env('HORIZON_MIN_PROCESS', 1),
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESS', 5),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 1,
                'timeout' => 86400, // 1 day
            ],
            ...$f02Supervisors,
            ...$notificationSupervisors,
        ],
    ],
];
