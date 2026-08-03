<?php

declare(strict_types=1);

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Services\Drivers\LaravelAiAudioTranscriber;
use App\AudioProcessing\Services\Drivers\MockAudioTranscriber;

test('configured transcription drivers resolve through the factory', function (string $driver, string $expectedClass): void {
    config(['transcription.driver' => $driver]);

    expect(resolve(TranscribesAudio::class))->toBeInstanceOf($expectedClass);
})->with([
    'mock' => ['mock', MockAudioTranscriber::class],
    'ai' => ['ai', LaravelAiAudioTranscriber::class],
]);

test('unsupported transcription drivers fail closed', function (): void {
    config(['transcription.driver' => 'unsupported']);

    expect(fn (): TranscribesAudio => resolve(TranscribesAudio::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported transcription driver [unsupported].');
});

test('mock transcription cannot be resolved in production', function (): void {
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', 'production');
    config(['transcription.driver' => 'mock']);

    try {
        expect(fn (): TranscribesAudio => resolve(TranscribesAudio::class))
            ->toThrow(LogicException::class, 'Mock transcription driver cannot be used in production.');
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }
});
