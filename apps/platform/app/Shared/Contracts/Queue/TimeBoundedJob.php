<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Queue;

/**
 * A queued job that can state its own timing envelope without being dispatched.
 *
 * Two guards need this and both used to hand-maintain their own list of job
 * classes, which is the failure mode the contract exists to remove:
 *
 * - `ProductionConfigurationValidator` refuses to boot production when
 *   `queue.connections.redis.retry_after` is at or below the longest job
 *   timeout. Redis re-reserves a job when retry_after elapses and
 *   `ShouldBeUnique` does not stop that, so getting this wrong runs a GDPR
 *   erasure twice, concurrently. Its list named four of the seven jobs.
 * - `UniqueJobLockExpiryTest` asserts `uniqueFor()` is derived from the job's
 *   own worst case rather than picked. Its list named three of the seven.
 *
 * Static rather than instance accessors so a caller can ask the question of a
 * class-string. Jobs take scalar IDs, so constructing one purely to read a
 * constant would mean inventing fixture arguments in production code.
 *
 * Why not read `#[Timeout]` by reflection instead: `ProcessAudioRecordingJob`
 * deliberately has no such attribute. It reads its timeout from config in the
 * constructor so an Octane worker cannot cache a stale value on the class, and
 * an attribute-only reader would silently score it as zero — exactly the quiet
 * under-count this contract is meant to prevent.
 *
 * Implementers are registered in {@see \App\Platform\Support\Observability\WorkerJobs}.
 */
interface TimeBoundedJob
{
    /**
     * Seconds a single attempt may run before the worker kills it.
     *
     * Must stay below `queue.connections.redis.retry_after`, which is per
     * connection rather than per queue.
     */
    public static function timeoutSeconds(): int;

    /**
     * Worst-case wall time from first dispatch to permanent failure:
     * `(timeout × tries) + sum(backoff)`.
     *
     * This is what `uniqueFor()` returns, and what any lock or claim sized on
     * "how long before a worker holding this is certainly dead" should use.
     */
    public static function lifecycleSeconds(): int;
}
