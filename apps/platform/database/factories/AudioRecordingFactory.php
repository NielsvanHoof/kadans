<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<AudioRecording>
 */
final class AudioRecordingFactory extends Factory
{
    #[Override]
    protected $model = AudioRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'disk' => 'local',
            'path' => 'audio/'.fake()->uuid().'.mp3',
            'original_filename' => fake()->word().'.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'uploaded_at' => now(),
            'uploaded_by' => null,
            // New uploads start waiting for the mock transcription job.
            'transcription_status' => TranscriptionStatus::Pending,
            'transcript' => null,
            'transcription_error' => null,
            'transcription_started_at' => null,
            'transcription_finished_at' => null,
        ];
    }

    /**
     * Mark the recording as transcription-complete with optional transcript text.
     */
    public function transcribed(?string $transcript = 'Mock transcript.'): static
    {
        return $this->state(fn (): array => [
            'transcription_status' => TranscriptionStatus::Completed,
            'transcript' => $transcript,
            'transcription_error' => null,
            'transcription_started_at' => now()->subMinute(),
            'transcription_finished_at' => now(),
        ]);
    }

    /**
     * Mark the recording as currently being transcribed.
     */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'transcription_status' => TranscriptionStatus::Processing,
            'transcript' => null,
            'transcription_error' => null,
            'transcription_started_at' => now(),
            'transcription_finished_at' => null,
        ]);
    }

    /**
     * Mark the recording as failed transcription with an error message.
     */
    public function failed(string $error = 'Mock transcription failed.'): static
    {
        return $this->state(fn (): array => [
            'transcription_status' => TranscriptionStatus::Failed,
            'transcript' => null,
            'transcription_error' => $error,
            'transcription_started_at' => now()->subMinute(),
            'transcription_finished_at' => now(),
        ]);
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (AudioRecording $audioRecording): void {
            if ($audioRecording->tenant_id !== null) {
                return;
            }

            $dossier = Dossier::queryWithoutTenantScope()
                ->find($audioRecording->dossier_id);

            if ($dossier instanceof Dossier) {
                $audioRecording->tenant_id = $dossier->tenant_id;
            }
        });
    }
}
