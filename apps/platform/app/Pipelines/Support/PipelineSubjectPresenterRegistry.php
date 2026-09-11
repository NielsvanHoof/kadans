<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Enums\PipelineType;
use Illuminate\Database\Eloquent\Model;

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

    /** @return array<class-string<Model>, list<string>> */
    public function eagerLoads(): array
    {
        $relations = [];

        foreach ($this->presenters as $presenter) {
            $subjectClass = $presenter->subjectClass();
            $relations[$subjectClass] = array_values(array_unique([
                ...($relations[$subjectClass] ?? []),
                ...$presenter->eagerLoads(),
            ]));
        }

        return $relations;
    }

    public function for(PipelineType $type): ?PipelineSubjectPresenter
    {
        return $this->presenters[$type->value] ?? null;
    }
}
