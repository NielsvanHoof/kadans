<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Enums;

enum PipelineType: string
{
    case OcrDocument = 'ocr_document';
    case DossierExport = 'dossier_export';
    case AudioTranscription = 'audio_transcription';

    /**
     * @return list<string>
     */
    public function stepKeys(): array
    {
        return match ($this) {
            self::OcrDocument => ['submit', 'provider_processing', 'process_results'],
            self::DossierExport => ['build_archive', 'store_archive'],
            self::AudioTranscription => ['submit', 'transcribe', 'process_results'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OcrDocument => __('OCR document'),
            self::DossierExport => __('Dossier export'),
            self::AudioTranscription => __('Audio transcription'),
        };
    }
}
