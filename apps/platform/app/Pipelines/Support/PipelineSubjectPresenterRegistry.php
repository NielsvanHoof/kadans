<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Enums\PipelineType;

/**
 * Owning domains register their subject presenter here (from their own
 * ServiceProvider::boot()) instead of Pipelines and Reporting branching on
 * subject class.
 *
 * Lookups return null rather than throwing: a run whose subject has been
 * deleted, or a type whose domain has not booted, must still broadcast and
 * still render on the pipeline pages.
 */
final class PipelineSubjectPresenterRegistry
{
    /** @var array<string, PipelineSubjectPresenter> */
    private array $presenters = [];

    public function register(PipelineSubjectPresenter $presenter): void
    {
        $this->presenters[$presenter->type()->value] = $presenter;
    }

    public function for(PipelineType $type): ?PipelineSubjectPresenter
    {
        return $this->presenters[$type->value] ?? null;
    }
}
