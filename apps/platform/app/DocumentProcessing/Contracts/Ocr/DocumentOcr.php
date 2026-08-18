<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Contracts\Ocr;

use App\Dossiers\Models\UploadedDocument;

/**
 * Starts OCR for an uploaded document that is already claimed as processing.
 * Mock drivers complete synchronously; Textract leaves the row in processing.
 */
interface DocumentOcr
{
    public function start(UploadedDocument $document): void;
}
