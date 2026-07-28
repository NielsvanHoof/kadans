<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Enums;

enum OcrDriver: string
{
    case Mock = 'mock';
    case Textract = 'textract';
}
