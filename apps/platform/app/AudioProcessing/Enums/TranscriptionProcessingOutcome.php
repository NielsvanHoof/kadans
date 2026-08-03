<?php

declare(strict_types=1);

namespace App\AudioProcessing\Enums;

/**
 * Whether transcription finishes inside start() or arrives later (Amazon Transcribe via SQS).
 */
enum TranscriptionProcessingOutcome: string
{
    case Immediate = 'immediate';
    case Deferred = 'deferred';
}
