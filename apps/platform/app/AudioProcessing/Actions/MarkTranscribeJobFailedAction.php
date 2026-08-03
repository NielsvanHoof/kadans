<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\Log;

/**
 * Persist a terminal Amazon Transcribe failure and audit it.
 */
final readonly class MarkTranscribeJobFailedAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(
        AudioRecording $recording,
        string $jobId,
        string $error,
        string $status,
    ): void {
        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcribe_job_id', $jobId)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcription_status' => TranscriptionStatus::Failed->value,
                'transcription_error' => $error,
                'transcription_finished_at' => now(),
            ]);

        if ($updated !== 1) {
            Log::warning('Transcription: discarded stale Transcribe failure.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobId,
                'expected_path' => $recording->path,
                'status' => $status,
            ]);

            return;
        }

        $recording->refresh();

        $this->logAuditActivity->handle(
            AuditEvent::AudioTranscriptionFailed,
            $recording,
            [
                'original_filename' => $recording->original_filename,
                'error' => $error,
                'transcribe_job_id' => $jobId,
                'transcribe_status' => $status,
            ],
            includeRequestContext: false,
        );

        Log::error('Transcription: Amazon Transcribe processing failed.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'transcribe_job_id' => $jobId,
            'status' => $status,
            'error' => $error,
        ]);
    }
}
