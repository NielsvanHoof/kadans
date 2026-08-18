<?php

declare(strict_types=1);

namespace App\AudioProcessing\Enums;

enum TranscriptionDriver: string
{
    case Mock = 'mock';
    case Transcribe = 'transcribe';
}
