<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\AudioProcessing\Services\Drivers\LaravelAiAudioTranscriber;
use App\AudioProcessing\Services\Drivers\MockAudioTranscriber;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use LogicException;

final readonly class TranscriptionDriverFactory
{
    public function __construct(
        private Application $application,
        private MockAudioTranscriber $mockAudioTranscriber,
        private LaravelAiAudioTranscriber $laravelAiAudioTranscriber,
    ) {}

    public function make(string $driver): TranscribesAudio
    {
        $transcriptionDriver = TranscriptionDriver::tryFrom($driver);

        throw_unless($transcriptionDriver instanceof TranscriptionDriver, InvalidArgumentException::class, "Unsupported transcription driver [{$driver}].");

        throw_if($transcriptionDriver === TranscriptionDriver::Mock && $this->application->isProduction(), LogicException::class, 'Mock transcription driver cannot be used in production.');

        return match ($transcriptionDriver) {
            TranscriptionDriver::Mock => $this->mockAudioTranscriber,
            TranscriptionDriver::Ai => $this->laravelAiAudioTranscriber,
        };
    }
}
