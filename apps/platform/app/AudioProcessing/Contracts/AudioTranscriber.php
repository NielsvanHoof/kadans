<?php

declare(strict_types=1);

namespace App\AudioProcessing\Contracts;

use App\Dossiers\Models\AudioRecording;

/**
 * Starts transcription for a recording that is already claimed as processing.
 * Mock/AI drivers complete synchronously; Amazon Transcribe leaves the row processing.
 */
interface AudioTranscriber
{
    public function start(AudioRecording $recording): void;
}
