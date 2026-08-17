<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr\Drivers;

use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Enums\OcrDriver;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use LogicException;

final readonly class OcrDriverFactory
{
    public function __construct(
        private Application $application,
        private MockOcrExtractor $mockOcrExtractor,
        private TextractDocumentOcr $textractDocumentOcr,
    ) {}

    public function make(string $driver): DocumentOcr
    {
        $ocrDriver = OcrDriver::tryFrom($driver);

        throw_unless($ocrDriver instanceof OcrDriver, InvalidArgumentException::class, "Unsupported OCR driver [{$driver}].");

        throw_if($ocrDriver === OcrDriver::Mock && $this->application->isProduction(), LogicException::class, 'Mock OCR driver cannot be used in production.');

        return match ($ocrDriver) {
            OcrDriver::Mock => $this->mockOcrExtractor,
            OcrDriver::Textract => $this->textractDocumentOcr,
        };
    }
}
