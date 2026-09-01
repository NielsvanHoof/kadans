<?php

declare(strict_types=1);

namespace App\Pipelines\Enums;

enum PipelineType: string
{
    case OcrDocument = 'ocr_document';
    case DossierExport = 'dossier_export';
    case AudioTranscription = 'audio_transcription';

    /**
     * @return list<PipelineStepKey>
     */
    public function stepKeys(): array
    {
        return match ($this) {
            self::OcrDocument => [
                PipelineStepKey::Submit,
                PipelineStepKey::ProviderProcessing,
                PipelineStepKey::ProcessResults,
            ],
            self::DossierExport => [
                PipelineStepKey::BuildArchive,
                PipelineStepKey::StoreArchive,
            ],
            self::AudioTranscription => [
                PipelineStepKey::Submit,
                PipelineStepKey::Transcribe,
                PipelineStepKey::ProcessResults,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OcrDocument => __('Document scan'),
            self::DossierExport => __('Dossier export'),
            self::AudioTranscription => __('Audio transcription'),
        };
    }
}
