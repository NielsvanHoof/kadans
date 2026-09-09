<?php

declare(strict_types=1);

use App\Platform\Support\Observability\WorkerQueues;
use App\Shared\Http\Middleware\AddHorizonCspNonce;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

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

    'domain' => env('HORIZON_DOMAIN'),

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
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
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

    'middleware' => [
        'web',
        AddHorizonCspNonce::class,
    ],

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
        'redis:ocr' => 120,
        'redis:transcription' => 120,
        'redis:dossier-export' => 180,
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
        'recent_failed' => 10080,
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
    | Spatie Health also appends HealthQueueJob when silence_health_queue_job
    | is enabled — do not fight that from here.
    |
    */

    'silenced' => [
        //
    ],

    'silenced_tags' => [
        // 'notifications',
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

    'fast_termination' => true,

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
    | Dashboard Authorization Emails
    |--------------------------------------------------------------------------
    |
    | Comma-separated emails allowed to open /horizon outside local. Empty
    | means nobody — same posture as Telescope's empty allow-list.
    |
    */

    'allowed_emails' => array_values(array_filter(array_map(
        mb_trim(...),
        explode(',', (string) env('HORIZON_ALLOWED_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Between them the supervisors drain WorkerQueues::NAMES exactly once —
    | WorkerQueueCoverageTest enforces the partition, because a queue in neither
    | list fails nothing, it just piles up unconsumed.
    |
    | Laravel Cloud runs a single custom background process:
    | `php artisan horizon`. Do not also run queue:work against Redis.
    |
    | Two supervisors rather than one, and `maxProcesses` above each one's queue
    | count, for reasons that are easy to undo by accident (TASK-45):
    |
    | `balance => 'auto'` builds one process pool per queue and `minProcesses`
    | is a per-pool floor, so a single supervisor over all five queues held five
    | workers resident while idle. `maxProcesses` was 4 — below that floor — and
    | `AutoScaler::scalePool` derives `maxUpShift` from
    | `max(0, maxProcesses - totalProcessCount)`, which was therefore always 0:
    | the pool could never grow under load, and `balance`, `balanceMaxShift` and
    | `balanceCooldown` were decoration. Keep every `maxProcesses` strictly
    | above its own queue count or that returns silently.
    |
    | `memory` is a recycle threshold, not a cap: Worker::stopIfNecessary checks
    | it *between* jobs, so a job that needs more is bounded by PHP's own
    | memory_limit and finishes regardless. Lowering it cannot fail a job — it
    | only restarts the worker sooner. With `maxJobs`/`maxTime` at 0 it was also
    | the *only* recycle trigger, which made 256 MB per worker a target rather
    | than a limit; `maxJobs` now keeps the plateau well underneath it.
    |
    | Resident floor 896 MB, ceiling 1152 MB, against 1344 MB fixed before —
    | on a 2 GiB single-vCPU cluster shared with Octane, the scheduler and the
    | two SQS daemons.
    |
    */

    'defaults' => [
        /*
         * Small payloads a person is waiting on. Broadcast floods (one bulk
         * upload emits several hundred pipeline.updated events) get their own
         * pool so a queued notification is never stuck behind them.
         */
        'supervisor-interactive' => [
            'connection' => 'redis',
            'queue' => WorkerQueues::INTERACTIVE,
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // > count(INTERACTIVE), so the pool can actually grow.
            'maxProcesses' => 3,
            'minProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 3,
            // Nothing here declares its own timeout; the slowest observed job
            // on these queues is a queued notification at ~200ms.
            'timeout' => 120,
            'backoff' => 30,
            'sleep' => 3,
            'rest' => 0,
            'force' => true,
            'nice' => 0,
        ],

        /*
         * Provider-bound work holding whole documents, transcripts or archives
         * in memory. Still one pool per queue rather than a single ordered
         * pool: a single pool would drain `ocr` first, and a bulk upload of 100
         * documents would starve a dossier export a person is waiting to
         * download for the better part of an hour.
         */
        'supervisor-heavy' => [
            'connection' => 'redis',
            'queue' => WorkerQueues::HEAVY,
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // > count(HEAVY), so the pool can actually grow.
            'maxProcesses' => 4,
            'minProcesses' => 1,
            'maxTime' => 0,
            // Low, because each of these jobs holds a large payload: recycling
            // often is how the plateau stays under `memory`.
            'maxJobs' => 50,
            'memory' => 192,
            'tries' => 3,
            // > DSR export 600s, < redis retry_after 660.
            'timeout' => 630,
            'backoff' => 30,
            'sleep' => 3,
            'rest' => 0,
            'force' => true,
            // Yields the single vCPU to Octane: an OCR worker holding the core
            // for a Bedrock round trip is a web request waiting behind it, and
            // these jobs have nobody watching a spinner.
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-interactive' => [
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-heavy' => [
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        // Staging ships the same worker topology as production.
        'staging' => [
            'supervisor-interactive' => [
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-heavy' => [
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        /*
         * Same topology, smaller thresholds: local jobs are tiny, and a laptop
         * running Sail, Vite and the whole Docker stack has less to spare than
         * the cluster does. maxProcesses stays above each queue count so the
         * autoscaling bug above cannot reappear only in development.
         */
        'local' => [
            'supervisor-interactive' => [
                'memory' => 96,
            ],
            'supervisor-heavy' => [
                'memory' => 128,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
