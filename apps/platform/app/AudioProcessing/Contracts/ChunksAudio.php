<?php

declare(strict_types=1);

namespace App\AudioProcessing\Contracts;

use App\AudioProcessing\Data\AudioChunk;

/**
 * Splits a local audio file into STT-sized chunks (or a single chunk when short).
 */
interface ChunksAudio
{
    /**
     * @return list<AudioChunk>
     */
    public function chunk(string $localPath, string $workDirectory): array;
}
