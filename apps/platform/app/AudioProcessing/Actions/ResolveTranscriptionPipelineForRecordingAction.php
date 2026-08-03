<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\AudioRecording;

use function is_numeric;
use function is_string;

/**
 * Find the audio transcription pipeline during Amazon Transcribe completion/failure.
 */
final class ResolveTranscriptionPipelineForRecordingAction
{
    public function handle(AudioRecording $recording, ?string $providerJobId = null): ?Pipeline
    {
        $recordingId = $recording->getKey();

        if (! is_numeric($recordingId)) {
            return null;
        }

        $query = Pipeline::query()
            ->where('pipelineable_type', $recording->getMorphClass())
            ->where('pipelineable_id', (int) $recordingId)
            ->where('type', PipelineType::AudioTranscription->value);
        $query->getQuery()->orderByDesc('id');

        if (is_string($providerJobId) && $providerJobId !== '') {
            $matchedQuery = clone $query;
            $matchedQuery->getQuery()->where('provider_job_id', $providerJobId);
            $matched = $matchedQuery->first();

            if ($matched instanceof Pipeline) {
                return $matched;
            }
        }

        $openQuery = clone $query;
        $openQuery->getQuery()->whereNotIn('status', [
            PipelineStatus::Completed->value,
            PipelineStatus::Cancelled->value,
        ]);

        return $openQuery->first();
    }
}
