<?php

declare(strict_types=1);

namespace App\Actions\Pipelines;

use App\Actions\Audit\LogAuditActivityAction;
use App\Enums\AuditEvent;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeletePipelineAction
{
    public function __construct(
        private readonly LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(Pipeline $pipeline, User $user): void
    {
        if (! $pipeline->status->isTerminal()) {
            throw ValidationException::withMessages([
                'pipeline' => 'Only a completed, failed, stuck, or cancelled pipeline can be deleted.',
            ]);
        }

        DB::transaction(function () use ($pipeline, $user): void {
            $this->logAuditActivity->handle(
                AuditEvent::PipelineDeleted,
                $pipeline,
                [
                    'pipeline_type' => $pipeline->type->value,
                    'pipeline_status' => $pipeline->status->value,
                ],
                causer: $user,
            );

            $pipeline->delete();
        });
    }
}
