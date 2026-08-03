<?php

declare(strict_types=1);

namespace App\AudioProcessing\Contracts;

use App\Dossiers\Models\AudioRecording;

/**
 * Transcribes an audio recording that is already claimed as processing.
 * Always synchronous — the caller persists the returned text.
 */
interface TranscribesAudio
{
    public function transcribe(AudioRecording $recording): string;
}
