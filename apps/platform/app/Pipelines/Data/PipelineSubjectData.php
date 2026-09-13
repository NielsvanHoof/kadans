<?php

declare(strict_types=1);

namespace App\Pipelines\Data;

use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Models\Pipeline;
use App\Shared\Database\MorphType;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineSubjectData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?string $label,
        public readonly ?string $context,
        public readonly ?string $href,
    ) {}

    public static function fromModel(Pipeline $pipeline, ?PipelineSubjectPresenter $presenter): self
    {
        $subject = $pipeline->pipelineable;
        $describable = $presenter instanceof PipelineSubjectPresenter && $subject instanceof Model;

        return new self(
            type: MorphType::classBasename($pipeline->pipelineable_type),
            id: $pipeline->pipelineable_id,
            label: $describable ? $presenter->label($subject) : null,
            context: $describable ? $presenter->context($subject) : null,
            href: $describable ? $presenter->href($subject, $pipeline) : null,
        );
    }
}
