<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

/**
 * Client-facing document request row on the portal.
 */
final readonly class PortalDocumentRequestData
{
    public function __construct(
        public int $id,
        public string $type,
        public string $fieldKey,
        public string $title,
        public ?string $instructions,
        public string $status,
        public ?string $answerText,
        public ?bool $answerBoolean,
        /** @var list<string>|null */
        public ?array $answerOptionIds,
        /** @var array<string, mixed>|null */
        public ?array $configuration,
        public ?string $rejectionReason,
        public bool $canRespond,
        public ?PortalUploadedDocumentData $uploadedDocument,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     field_key: string,
     *     title: string,
     *     instructions: string|null,
     *     status: string,
     *     answer_text: string|null,
     *     answer_boolean: bool|null,
     *     answer_option_ids: list<string>|null,
     *     configuration: array<string, mixed>|null,
     *     rejection_reason: string|null,
     *     can_respond: bool,
     *     uploaded_document: array{
     *         original_filename: string,
     *         size_bytes: int,
     *         uploaded_at: string
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
            'status' => $this->status,
            'answer_text' => $this->answerText,
            'answer_boolean' => $this->answerBoolean,
            'answer_option_ids' => $this->answerOptionIds,
            'configuration' => $this->configuration,
            'rejection_reason' => $this->rejectionReason,
            'can_respond' => $this->canRespond,
            'uploaded_document' => $this->uploadedDocument?->toArray(),
        ];
    }
}
