<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Pipelines\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeletePipelineAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
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
