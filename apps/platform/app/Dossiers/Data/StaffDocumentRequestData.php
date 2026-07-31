<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Accounts\Models\User;
use App\Dossiers\Models\DocumentRequest;

/**
 * Staff-facing document request row on dossier show.
 */
final readonly class StaffDocumentRequestData
{
    public function __construct(
        public int $id,
        public string $type,
        public string $fieldKey,
        public string $title,
        public ?string $instructions,
        /** @var array<string, mixed>|null */
        public ?array $configuration,
        public string $status,
        public ?string $answerText,
        public ?bool $answerBoolean,
        /** @var list<string>|null */
        public ?array $answerOptionIds,
        public ?string $answeredAt,
        public ?string $reviewedAt,
        public ?string $reviewedByName,
        public ?string $rejectionReason,
        public int $sortOrder,
        /** Whether this field is currently shown to the client. */
        public bool $isVisible,
        /** Whether this field counts toward progress, review, and completion. */
        public bool $isApplicable,
        public ?StaffUploadedDocumentData $uploadedDocument,
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
            type: $documentRequest->type->value,
            fieldKey: $documentRequest->field_key,
            title: $documentRequest->title,
            instructions: $documentRequest->instructions,
            configuration: $documentRequest->configuration,
            status: $documentRequest->status->value,
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

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     field_key: string,
     *     title: string,
     *     instructions: string|null,
     *     configuration: array<string, mixed>|null,
     *     status: string,
     *     answer_text: string|null,
     *     answer_boolean: bool|null,
     *     answer_option_ids: list<string>|null,
     *     answered_at: string|null,
     *     reviewed_at: string|null,
     *     reviewed_by_name: string|null,
     *     rejection_reason: string|null,
     *     sort_order: int,
     *     is_visible: bool,
     *     is_applicable: bool,
     *     uploaded_document: array{
     *         id: int,
     *         original_filename: string,
     *         size_bytes: int,
     *         uploaded_at: string,
     *         processing_status: string,
     *         processing_error: string|null
     *     }|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'field_key' => $this->fieldKey,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'configuration' => $this->configuration,
            'status' => $this->status,
            'answer_text' => $this->answerText,
            'answer_boolean' => $this->answerBoolean,
            'answer_option_ids' => $this->answerOptionIds,
            'answered_at' => $this->answeredAt,
            'reviewed_at' => $this->reviewedAt,
            'reviewed_by_name' => $this->reviewedByName,
            'rejection_reason' => $this->rejectionReason,
            'sort_order' => $this->sortOrder,
            'is_visible' => $this->isVisible,
            'is_applicable' => $this->isApplicable,
            'uploaded_document' => $this->uploadedDocument?->toArray(),
        ];
    }
}
