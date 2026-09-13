<?php

declare(strict_types=1);

namespace App\Dossiers\Queries\UploadedDocuments;

use App\Clients\Models\Client;
use App\DocumentProcessing\Services\Ocr\FlattenExtractedTextForSearch;
use App\Dossiers\Data\UploadedDocuments\UploadedDocumentSearchHitData;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\URL;
use RuntimeException;

use function array_flip;
use function array_values;
use function is_int;

/**
 * Tenant-scoped hybrid (or collection) search over uploaded documents.
 *
 * @phpstan-type SearchUploadedDocumentsResult array{
 *     hits: list<UploadedDocumentSearchHitData>,
 *     truncated: bool
 * }
 */
final readonly class SearchUploadedDocumentsQuery
{
    public function __construct(private FlattenExtractedTextForSearch $flatten) {}

    /**
     * @return SearchUploadedDocumentsResult
     */
    public function handle(string $term): array
    {
        $term = mb_trim($term);

        if ($term === '') {
            return [
                'hits' => [],
                'truncated' => false,
            ];
        }

        $ids = ScoutSearchFilter::keys(UploadedDocument::class, $term, hybrid: true);
        $truncated = request()->attributes->get(ScoutSearchFilter::REQUEST_ATTRIBUTE_TRUNCATED) === true;

        if ($ids === []) {
            return [
                'hits' => [],
                'truncated' => $truncated,
            ];
        }

        $order = array_flip(array_map(
            static fn (int|string $id): int => is_int($id) ? $id : (int) $id,
            $ids,
        ));

        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Document search requires a current tenant.');

        $hits = array_values(UploadedDocument::query()
            ->whereKey($ids)
            ->with([
                'documentRequest:id,dossier_id,title',
                'documentRequest.dossier:id,client_id,title',
                'documentRequest.dossier.client:id,name',
            ])
            ->get()
            ->sortBy(fn (UploadedDocument $document): int => $order[$document->id] ?? PHP_INT_MAX)
            ->values()
            ->map(fn (UploadedDocument $document): ?UploadedDocumentSearchHitData => $this->mapHit($document, $tenant))
            ->filter()
            ->values()
            ->all());

        return [
            'hits' => $hits,
            'truncated' => $truncated,
        ];
    }

    private function mapHit(UploadedDocument $document, Tenant $tenant): ?UploadedDocumentSearchHitData
    {
        $overview = $this->flatten->overview($document->extracted_text);
        $dossier = $document->documentRequest->dossier;
        $client = $dossier instanceof Dossier ? $dossier->client : null;

        if (! $dossier instanceof Dossier || ! $client instanceof Client) {
            return null;
        }

        return new UploadedDocumentSearchHitData(
            id: $document->id,
            originalFilename: $document->original_filename,
            documentType: $overview['document_type'],
            summary: $overview['summary'],
            requestTitle: $document->documentRequest->title,
            dossierId: $dossier->id,
            dossierTitle: $dossier->title,
            clientName: $client->name,
            showUrl: URL::route('workspaces.uploaded-documents.show', [
                'tenant' => $tenant,
                'uploadedDocument' => $document,
            ]),
        );
    }
}
