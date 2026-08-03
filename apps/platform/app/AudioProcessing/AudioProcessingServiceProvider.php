<?php

declare(strict_types=1);

namespace App\AudioProcessing;

use App\AudioProcessing\Contracts\ChunksAudio;
use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\AudioProcessing\Services\Audio\FfmpegAudioChunker;
use App\AudioProcessing\Services\Configuration\TranscriptionConfigurationValidator;
use App\AudioProcessing\Services\TranscriptionDriverFactory;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Transcription container bindings and config validation.
 */
final class AudioProcessingServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(ChunksAudio::class, FfmpegAudioChunker::class);

        $this->app->bind(
            TranscribesAudio::class,
            fn ($app): TranscribesAudio => $app->make(TranscriptionDriverFactory::class)->make(
                (string) config('transcription.driver', TranscriptionDriver::Mock->value),
            ),
        );
    }

    public function boot(TranscriptionConfigurationValidator $transcriptionConfigurationValidator): void
    {
        $transcriptionConfigurationValidator->validate();
    }
}
