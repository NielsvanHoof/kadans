<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Data\TranscriptionResult;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates transcription start and audit logging.
 * Always synchronous — no deferred/async provider outcome to track.
 */
final readonly class TranscriptionOrchestrator
{
    public function __construct(
        private TranscribesAudio $transcriber,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function transcribe(AudioRecording $recording): TranscriptionResult
    {
        Log::info('Transcription: orchestrator starting audio processing.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'original_filename' => $recording->original_filename,
        ]);

        $this->logAuditActivity->handle(
            AuditEvent::AudioTranscriptionStarted,
            $recording,
            ['original_filename' => $recording->original_filename],
            includeRequestContext: false,
        );

        $result = $this->transcriber->transcribe($recording);

        $this->logAuditActivity->handle(
            AuditEvent::AudioTranscriptionCompleted,
            $recording,
            [
                'original_filename' => $recording->original_filename,
                'transcript_length' => mb_strlen($result->text),
            ],
            includeRequestContext: false,
        );

        Log::info('Transcription: orchestrator completed.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'transcript_length' => mb_strlen($result->text),
        ]);

        return $result;
    }
}
