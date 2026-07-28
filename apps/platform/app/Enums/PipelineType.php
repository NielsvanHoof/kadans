<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineType: string
{
    case OcrDocument = 'ocr_document';
    case DossierExport = 'dossier_export';

    /**
     * @return list<string>
     */
    public function stepKeys(): array
    {
        return match ($this) {
            self::OcrDocument => ['submit', 'provider_processing', 'process_results'],
            self::DossierExport => ['build_archive', 'store_archive'],
        };
    }
}
