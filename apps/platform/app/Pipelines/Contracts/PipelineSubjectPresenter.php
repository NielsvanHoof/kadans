<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

/**
 * Owned by the domain that runs a given pipeline type. Lets Pipelines and
 * Reporting describe a run's subject — its name, where it sits, how to reach
 * it — without importing that domain's models.
 *
 * The sibling of PipelineRetryRunner: same registration story, same reason.
 */
interface PipelineSubjectPresenter
{
    public function type(): PipelineType;

    /**
     * @return class-string<Model>
     */
    public function subjectClass(): string;

    /**
     * Relations the subject needs loaded before label(), context() or href()
     * can answer without extra queries.
     *
     * @return list<string>
     */
    public function eagerLoads(): array;

    /**
     * What to call this run's subject — a filename, an export number.
     */
    public function label(Model $subject): ?string;

    /**
     * Where the subject sits, usually the dossier it belongs to.
     */
    public function context(Model $subject): ?string;

    /**
     * The dossier a broadcast should be tagged with, when there is one.
     */
    public function dossierId(Model $subject): ?int;

    /**
     * A workspace URL for the subject, or null when it has no page of its own.
     */
    public function href(Model $subject, Pipeline $pipeline): ?string;
}
