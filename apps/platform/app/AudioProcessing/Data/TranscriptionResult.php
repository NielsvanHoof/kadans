<?php

declare(strict_types=1);

namespace App\AudioProcessing\Data;

/**
 * Transcription driver output: flat text plus its diarized segments (if any).
 * Internal service-boundary value object — not exposed to Inertia directly.
 */
final readonly class TranscriptionResult
{
    /**
     * @param  list<array{text: string, speaker: string, start_seconds: float, end_seconds: float}>  $segments
     */
    public function __construct(
        public string $text,
        public array $segments,
    ) {}
}
