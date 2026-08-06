<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Retry;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class OcrPipelineRetryRunner implements PipelineRetryRunner
{
    public function type(): PipelineType
    {
        return PipelineType::OcrDocument;
    }

    public function subjectClass(): string
    {
        return UploadedDocument::class;
    }

    public function resetSubject(Model $subject): void
    {
        $subject->forceFill([
            'processing_status' => DocumentProcessingStatus::Pending,
            'processing_error' => null,
            'extracted_text' => null,
            'textract_job_id' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
        ])->saveQuietly();
    }

    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        dispatch(new ProcessUploadedDocumentJob(
            $subject->getKey(),
            $subject->getAttribute('path'),
            $pipeline->id,
        ))->afterCommit();
    }
}
