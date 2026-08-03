<?php

declare(strict_types=1);

namespace App\AudioProcessing\Data;

/**
 * One local audio slice ready for the STT provider.
 * startSeconds is the offset of this slice in the original recording.
 */
final readonly class AudioChunk
{
    public function __construct(
        public string $path,
        public float $startSeconds,
        public float $durationSeconds,
        public int $index,
    ) {}
}
