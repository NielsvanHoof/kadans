<?php

declare(strict_types=1);

namespace App\Platform\Support;

/**
 * Queues Horizon (and local Sail via `php artisan horizon`) must drain.
 *
 * Split by cost profile, because `balance => 'auto'` gives every queue its own
 * process pool and `minProcesses` is a per-pool floor: one supervisor over all
 * five queues held five workers resident even while idle, each instructed to
 * climb to its 256 MB limit before recycling. That is ~1.34 GB of floor on a
 * cluster that also runs Octane, the scheduler and two SQS daemons. Worse,
 * `maxProcesses` sat *below* that floor, so `AutoScaler::scalePool` computed a
 * `maxUpShift` of zero and the supervisor could never add a worker under load —
 * `balance => 'auto'` was decoration. Two supervisors let each group carry the
 * memory ceiling its jobs actually need. TASK-45.
 *
 * `config/horizon.php` supervisors use {@see INTERACTIVE} and {@see HEAVY};
 * {@see NAMES} is their union and stays the single list everything else checks
 * against. Laravel Cloud runs a single custom background process
 * `php artisan horizon` — not queue:work, and not Managed Queues, whose
 * comma-separated queue field this class used to exist to produce.
 */
final class WorkerQueues
{
    /**
     * Small payloads a person is waiting on: queued notifications, and the
     * broadcasts that drive the live UI.
     *
     * `broadcasts` is separate from `default` because live-UI events arrive in
     * bursts that no other queue should have to wait behind: one bulk upload of
     * 100 documents emits several hundred pipeline.updated events, and on
     * `default` those sat directly in front of queued notifications and the
     * health heartbeat. Each one is tiny, so the queue is cheap to drain — it
     * just must not be drained in sequence with work a person is waiting on.
     *
     * Order matters: default first, so it is the pool Horizon favours when both
     * are idle.
     *
     * @var list<string>
     */
    public const array INTERACTIVE = [
        'default',
        'broadcasts',
    ];

    /**
     * Provider-bound work measured in minutes, holding whole documents,
     * transcripts or archives in memory.
     *
     * `dossier-export` carries every long-running archive build — dossier
     * exports and data-subject exports alike — and is why this group keeps a
     * high memory ceiling: GenerateDataSubjectExportJob stages an entire
     * client archive before streaming it to S3.
     *
     * @var list<string>
     */
    public const array HEAVY = [
        'ocr',
        'transcription',
        'dossier-export',
    ];

    /**
     * Every queue the application dispatches to, interactive first.
     *
     * @var list<string>
     */
    public const array NAMES = [
        ...self::INTERACTIVE,
        ...self::HEAVY,
    ];
}
