<?php

declare(strict_types=1);

namespace App\AudioProcessing\Contracts;

use App\AudioProcessing\Data\TranscriptionResult;
use App\Dossiers\Models\AudioRecording;

/**
 * Transcribes an audio recording that is already claimed as processing.
 * Always synchronous — the caller persists the returned result.
 */
interface TranscribesAudio
{
    public function transcribe(AudioRecording $recording): TranscriptionResult;
}
