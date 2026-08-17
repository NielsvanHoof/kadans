<?php

declare(strict_types=1);

use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Services\Ocr\Drivers\MockOcrExtractor;
use App\DocumentProcessing\Services\Ocr\Drivers\TextractDocumentOcr;

test('configured OCR drivers resolve through the factory', function (string $driver, string $expectedClass): void {
    config(['ocr.driver' => $driver]);

    expect(resolve(DocumentOcr::class))->toBeInstanceOf($expectedClass);
})->with([
    'mock' => ['mock', MockOcrExtractor::class],
    'textract' => ['textract', TextractDocumentOcr::class],
]);

test('unsupported OCR drivers fail closed', function (): void {
    config(['ocr.driver' => 'unsupported']);

    expect(fn (): DocumentOcr => resolve(DocumentOcr::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported OCR driver [unsupported].');
});

test('mock OCR cannot be resolved in production', function (): void {
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', 'production');
    config(['ocr.driver' => 'mock']);

    try {
        expect(fn (): DocumentOcr => resolve(DocumentOcr::class))
            ->toThrow(LogicException::class, 'Mock OCR driver cannot be used in production.');
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }
});
