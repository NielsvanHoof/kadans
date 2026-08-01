<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Staff-facing document request row on dossier show.
 */
#[TypeScript]
final class StaffDocumentRequestData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly QuestionnaireItemType $type,
        public readonly string $fieldKey,
        public readonly string $title,
        public readonly ?string $instructions,
        /** @var array<string, mixed>|null */
        public readonly ?array $configuration,
        public readonly DocumentRequestStatus $status,
        public readonly ?string $answerText,
        public readonly ?bool $answerBoolean,
        /** @var list<string>|null */
        public readonly ?array $answerOptionIds,
        public readonly ?string $answeredAt,
        public readonly ?string $reviewedAt,
        public readonly ?string $reviewedByName,
        public readonly ?string $rejectionReason,
        public readonly int $sortOrder,
        /** Whether this field is currently shown to the client. */
        public readonly bool $isVisible,
        /** Whether this field counts toward progress, review, and completion. */
        public readonly bool $isApplicable,
        public readonly ?StaffUploadedDocumentData $uploadedDocument,
    ) {}

    /**
     * Builder-island payload for a single questionnaire item (no dossier graph).
     * Fresh creates are visible/applicable until the next full show reload.
     */
    public static function fromModel(
        DocumentRequest $documentRequest,
        bool $isVisible = true,
        bool $isApplicable = true,
        ?StaffUploadedDocumentData $uploadedDocument = null,
    ): self {
        $reviewer = $documentRequest->relationLoaded('reviewedBy')
            ? $documentRequest->reviewedBy
            : null;

        return new self(
            id: $documentRequest->id,
            type: $documentRequest->type,
            fieldKey: $documentRequest->field_key,
            title: $documentRequest->title,
            instructions: $documentRequest->instructions,
            configuration: $documentRequest->configuration,
            status: $documentRequest->status,
            answerText: $documentRequest->answer_text,
            answerBoolean: $documentRequest->answer_boolean,
            answerOptionIds: $documentRequest->answer_option_ids,
            answeredAt: $documentRequest->answered_at?->toIso8601String(),
            reviewedAt: $documentRequest->reviewed_at?->toIso8601String(),
            reviewedByName: $reviewer instanceof User ? $reviewer->name : null,
            rejectionReason: $documentRequest->rejection_reason,
            sortOrder: $documentRequest->sort_order,
            isVisible: $isVisible,
            isApplicable: $isApplicable,
            uploadedDocument: $uploadedDocument,
        );
    }
}
