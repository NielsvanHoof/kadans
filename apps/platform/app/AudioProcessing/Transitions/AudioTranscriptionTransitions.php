<?php

declare(strict_types=1);

namespace App\AudioProcessing\Transitions;

use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\DB;

/**
 * Guarded transcription_status transitions for audio recordings.
 *
 * Flow: pending|failed → processing (claim) → completed|failed.
 * Retryable job failures reset to pending so the next attempt can reclaim.
 */
final class AudioTranscriptionTransitions
{
    /**
     * Atomically move pending/failed/processing → processing for this path revision.
     * Returns null when the recording cannot be claimed.
     */
    public function claim(AudioRecording $recording, string $expectedPath): ?AudioRecording
    {
        return DB::transaction(function () use ($recording, $expectedPath): ?AudioRecording {
            $locked = $recording->refreshForUpdate();

            // Stale job for a replaced upload revision — skip without failing the queue.
            if ($locked->path !== $expectedPath) {
                return null;
            }

            // Another worker finished, or an external Transcribe job is already in flight.
            if (
                $locked->transcription_status === TranscriptionStatus::Completed
                || (
                    $locked->transcription_status === TranscriptionStatus::Processing
                    && $locked->transcribe_job_id !== null
                )
            ) {
                return null;
            }

            $locked->forceFill([
                'transcription_status' => TranscriptionStatus::Processing,
                'transcription_error' => null,
                'transcript' => null,
                'segments' => null,
                'transcribe_job_id' => null,
                'transcription_started_at' => $locked->transcription_started_at ?? now(),
                'transcription_finished_at' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * Complete a processing recording for a known Transcribe job + path revision.
     *
     * @param  string  $segmentsJson  JSON-encoded speaker segments
     * @return bool True when exactly one row was updated
     */
    public function complete(
        AudioRecording $recording,
        string $jobId,
        string $transcript,
        string $segmentsJson,
    ): bool {
        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcribe_job_id', $jobId)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcription_status' => TranscriptionStatus::Completed->value,
                'transcript' => $transcript,
                'segments' => $segmentsJson,
                'transcription_error' => null,
                'transcription_finished_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Mark a processing recording failed for a known Transcribe job + path revision.
     *
     * @return bool True when exactly one row was updated
     */
    public function failWhileProcessing(
        AudioRecording $recording,
        string $jobId,
        string $error,
    ): bool {
        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcribe_job_id', $jobId)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcription_status' => TranscriptionStatus::Failed->value,
                'transcription_error' => mb_substr($error, 0, 500),
                'transcription_finished_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Permanent failure after job exhaustion — keyed by path only.
     *
     * @return bool True when exactly one row was updated
     */
    public function failForPath(
        AudioRecording $recording,
        string $expectedPath,
        string $error,
    ): bool {
        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $expectedPath)
            ->update([
                'transcription_status' => TranscriptionStatus::Failed->value,
                'transcription_error' => mb_substr($error, 0, 500),
                'transcription_finished_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Reset to pending after a retryable job failure so the next attempt can reclaim.
     */
    public function resetForRetry(AudioRecording $recording, string $expectedPath): void
    {
        AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $expectedPath)
            ->update([
                'transcription_status' => TranscriptionStatus::Pending->value,
                'transcribe_job_id' => null,
            ]);
    }
}
