<?php

declare(strict_types=1);

namespace App\Pipelines\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire payload of the workspace `pipeline.updated` broadcast.
 *
 * Modelled as a Data object so the frontend type is generated rather than
 * hand-mirrored — this shape used to be duplicated in a TypeScript hook.
 *
 * Values stay plain (no enums, no models) so the queued broadcast job does not
 * need Eloquent rehydration.
 */
#[TypeScript]
final class PipelineUpdatedPayloadData extends Data
{
    public function __construct(
        public readonly int $pipelineId,
        public readonly string $type,
        public readonly string $status,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?int $dossierId,
    ) {}
}
