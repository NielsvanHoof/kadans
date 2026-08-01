<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Lean dossier payload for the questionnaire builder page.
 */
final readonly class DossierBuilderData
{
    /**
     * @param  list<StaffDocumentRequestData>  $documentRequests
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public array $documentRequests,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     status: string,
     *     document_requests: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'document_requests' => array_map(
                static fn (StaffDocumentRequestData $request): array => $request->toArray(),
                $this->documentRequests,
            ),
        ];
    }
}
