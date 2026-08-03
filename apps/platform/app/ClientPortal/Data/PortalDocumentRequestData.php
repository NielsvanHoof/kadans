<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

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
    public function __construct(
        public readonly int $id,
        public readonly QuestionnaireItemType $type,
        public readonly string $fieldKey,
        public readonly string $title,
        public readonly ?string $instructions,
        public readonly DocumentRequestStatus $status,
        public readonly ?string $answerText,
        public readonly ?bool $answerBoolean,
        /** @var list<string>|null */
        public readonly ?array $answerOptionIds,
        /** @var array<string, mixed>|null */
        public readonly ?array $configuration,
        public readonly ?string $rejectionReason,
        public readonly bool $canRespond,
        public readonly ?PortalUploadedDocumentData $uploadedDocument,
    ) {}
}
