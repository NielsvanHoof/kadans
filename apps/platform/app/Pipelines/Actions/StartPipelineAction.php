<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Accounts\Models\User;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRunnerRegistry;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

use function sprintf;

/**
 * Open a run and queue its first attempt.
 */
final readonly class StartPipelineAction
{
    public function __construct(
        private CreatePipelineAction $createPipeline,
        private PipelineRunnerRegistry $runners,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Model $subject,
        PipelineType $type,
        ?User $initiatedBy = null,
        array $metadata = [],
    ): Pipeline {
        $runner = $this->runners->for($type);
        $expected = $runner->subjectClass();

        throw_unless($subject instanceof $expected, RuntimeException::class, sprintf(
            'Pipeline type [%s] runs on [%s], but got [%s].',
            $type->value,
            $expected,
            $subject::class,
        ));

        $pipeline = $this->createPipeline->handle($subject, $type, $initiatedBy, $metadata);

        $runner->dispatch($pipeline, $subject);

        return $pipeline;
    }
}
