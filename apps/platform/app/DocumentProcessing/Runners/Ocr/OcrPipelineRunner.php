<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Runners\Ocr;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\Ocr\ProcessUploadedDocumentJob;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Contracts\PipelineRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class OcrPipelineRunner implements PipelineRunner
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
        throw_unless($subject instanceof UploadedDocument, RuntimeException::class, 'The OCR pipeline runner received an unexpected subject.');

        $subject->forceFill([
            'processing_status' => DocumentProcessingStatus::Pending,
            'processing_error' => null,
            'extracted_text' => null,
            'searchable_text' => null,
            'embedding' => null,
            'textract_job_id' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
        ])->saveQuietly();
        $subject->unsearchable();
    }

    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        throw_unless($subject instanceof UploadedDocument, RuntimeException::class, 'The OCR pipeline runner received an unexpected subject.');

        dispatch(new ProcessUploadedDocumentJob(
            $subject->id,
            $subject->path,
            $pipeline->id,
        ))->afterCommit();
    }
}
