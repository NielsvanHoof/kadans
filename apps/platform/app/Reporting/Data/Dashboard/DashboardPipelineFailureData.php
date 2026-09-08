<?php

declare(strict_types=1);

namespace App\Reporting\Data\Dashboard;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A single failed or stuck background run, linkable to its pipeline page.
 */
#[TypeScript]
final class DashboardPipelineFailureData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly PipelineType $type,
        public readonly PipelineStatus $status,
        public readonly ?string $errorMessage,
        public readonly ?string $lastActivityAt,
        /** Whether this run can be re-queued, per PipelineStatus::isRetryable(). */
        public readonly bool $isRetryable,
    ) {}
}
