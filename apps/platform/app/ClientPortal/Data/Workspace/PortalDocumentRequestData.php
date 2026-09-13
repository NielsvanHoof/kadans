<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Client-facing document request row on the portal.
 */
#[TypeScript]
final class PortalDocumentRequestData extends Data
{
    /**
     * @param  list<string>|null  $answerOptionIds
     * @param  array<string, mixed>|null  $configuration
     * @param  list<PortalUploadedDocumentData>  $uploadedDocuments
     */
    public function __construct(
        public readonly int $id,
        public readonly QuestionnaireItemType $type,
        public readonly string $fieldKey,
        public readonly string $title,
        public readonly ?string $instructions,
        public readonly DocumentRequestStatus $status,
        public readonly ?string $answerText,
        public readonly ?bool $answerBoolean,
        public readonly ?array $answerOptionIds,
        public readonly ?array $configuration,
        public readonly ?string $rejectionReason,
        public readonly bool $canRespond,
        public readonly bool $isCarriedForward,
        public readonly array $uploadedDocuments,
    ) {}
}
