<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services;

use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\AudioProcessing\Enums\TranscriptionDriver;
use App\AudioProcessing\Services\Drivers\AmazonTranscribeAudioTranscriber;
use App\AudioProcessing\Services\Drivers\MockAudioTranscriber;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use LogicException;

final readonly class TranscriptionDriverFactory
{
    public function __construct(
        private Application $application,
        private MockAudioTranscriber $mockAudioTranscriber,
        private AmazonTranscribeAudioTranscriber $amazonTranscribeAudioTranscriber,
    ) {}

    public function make(string $driver): StartsAudioTranscription
    {
        $transcriptionDriver = TranscriptionDriver::tryFrom($driver);

        throw_unless($transcriptionDriver instanceof TranscriptionDriver, InvalidArgumentException::class, "Unsupported transcription driver [{$driver}].");

        throw_if($transcriptionDriver === TranscriptionDriver::Mock && $this->application->isProduction(), LogicException::class, 'Mock transcription driver cannot be used in production.');

        return match ($transcriptionDriver) {
            TranscriptionDriver::Mock => $this->mockAudioTranscriber,
            TranscriptionDriver::Transcribe => $this->amazonTranscribeAudioTranscriber,
        };
    }
}
