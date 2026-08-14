<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services;

use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\AudioProcessing\Enums\TranscriptionProcessingOutcome;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates transcription start and audit logging.
 * Deferred adapters leave the recording processing until async completion.
 */
final readonly class TranscriptionOrchestrator
{
    public function __construct(
        private StartsAudioTranscription $transcriber,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function startProcessing(AudioRecording $recording): TranscriptionProcessingOutcome
    {
        Log::info('Transcription: orchestrator starting audio processing.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'transcription_status' => $recording->transcription_status->value,
        ]);

        $this->logAuditActivity->handle(
            AuditEvent::AudioTranscriptionStarted,
            $recording,
            ['original_filename' => $recording->original_filename],
            includeRequestContext: false,
        );

        $this->transcriber->start($recording);
        $recording->refresh();

        if ($recording->transcription_status === TranscriptionStatus::Completed) {
            $this->logAuditActivity->handle(
                AuditEvent::AudioTranscriptionCompleted,
                $recording,
                [
                    'original_filename' => $recording->original_filename,
                    'transcript_length' => mb_strlen((string) $recording->transcript),
                ],
                includeRequestContext: false,
            );

            Log::info('Transcription: orchestrator completed immediately (sync driver).', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'outcome' => TranscriptionProcessingOutcome::Immediate->value,
                'transcript_length' => mb_strlen((string) $recording->transcript),
            ]);

            return TranscriptionProcessingOutcome::Immediate;
        }

        Log::info('Transcription: orchestrator deferred — waiting for async provider completion.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'outcome' => TranscriptionProcessingOutcome::Deferred->value,
            'transcribe_job_id' => $recording->transcribe_job_id,
            'transcription_status' => $recording->transcription_status->value,
        ]);

        return TranscriptionProcessingOutcome::Deferred;
    }
}
