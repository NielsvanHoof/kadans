<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Reporting\Data\MediaLibraryDocumentRowData;
use App\Reporting\Data\MediaLibraryDossierSummaryData;
use App\Reporting\Data\MediaLibraryUploadedDocumentData;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * @extends PaginatedIndexQuery<DocumentRequest>
 */
final class FetchMediaLibraryDocumentsQuery extends PaginatedIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     title: string,
     *     status: string,
     *     updated_at: string|null,
     *     dossier: array{id: int, title: string, reference: string|null},
     *     client_name: string,
     *     uploaded_documents: list<array{
     *         id: int,
     *         original_filename: string,
     *         size_bytes: int,
     *         uploaded_at: string
     *     }>
     * }>
     */
    public function handle(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, title: string, status: string, updated_at: string|null, dossier: array{id: int, title: string, reference: string|null}, client_name: string, uploaded_documents: list<array{id: int, original_filename: string, size_bytes: int, uploaded_at: string}>}> */
        return $this->paginate();
    }

    /**
     * @return array{
     *     filters: list<array<string, mixed>>,
     *     sorts: list<array{key: string, label: string}>,
     *     defaults: array{sort: string}
     * }
     */
    public function toolbarMetadata(): array
    {
        return [
            'filters' => [
                ['key' => 'q', 'type' => 'search', 'label' => __('Search')],
                [
                    'key' => 'status',
                    'type' => 'select',
                    'label' => __('Status'),
                    'options' => [
                        ['value' => 'pending', 'label' => __('Pending')],
                        ['value' => 'submitted', 'label' => __('Submitted')],
                        ['value' => 'accepted', 'label' => __('Approved')],
                        ['value' => 'rejected', 'label' => __('Rejected')],
                    ],
                ],
            ],
            'sorts' => [
                ['key' => '-updated_at', 'label' => __('Recently updated')],
                ['key' => 'updated_at', 'label' => __('Oldest updated')],
                ['key' => 'title', 'label' => __('Title (A–Z)')],
                ['key' => '-title', 'label' => __('Title (Z–A)')],
                ['key' => 'status', 'label' => __('Status (A–Z)')],
            ],
            'defaults' => [
                'sort' => '-updated_at',
            ],
        ];
    }

    /**
     * @return Builder<DocumentRequest>
     */
    protected function subject(): Builder
    {
        return DocumentRequest::query()
            ->where('type', QuestionnaireItemType::File)
            ->whereHas('dossier')
            ->with([
                'uploadedDocuments',
                'dossier:id,client_id,title,reference',
                'dossier.client:id,name',
            ]);
    }

    protected function allowedFilters(): array
    {
        return [
            ScoutSearchFilter::make(DocumentRequest::class),
            AllowedFilter::exact('status'),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('title'),
            AllowedSort::field('status'),
            AllowedSort::field('updated_at'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-updated_at';
    }

    /**
     * Row shape is owned by MediaLibraryDocumentRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var DocumentRequest $model */
        $dossier = $this->resolveDossier($model);
        $client = $this->resolveClient($dossier);

        return new MediaLibraryDocumentRowData(
            id: $model->id,
            title: $model->title,
            status: $model->status,
            updatedAt: $model->updated_at?->toIso8601String(),
            dossier: new MediaLibraryDossierSummaryData(
                id: $dossier->id,
                title: $dossier->title,
                reference: $dossier->reference,
            ),
            clientName: $client->name,
            uploadedDocuments: array_values($model->uploadedDocuments
                ->map(fn (UploadedDocument $uploaded): MediaLibraryUploadedDocumentData => new MediaLibraryUploadedDocumentData(
                    id: $uploaded->id,
                    originalFilename: $uploaded->original_filename,
                    sizeBytes: $uploaded->size_bytes,
                    uploadedAt: $uploaded->uploaded_at->toIso8601String(),
                ))
                ->all()),
        )->toArray();
    }

    private function resolveDossier(DocumentRequest $documentRequest): Dossier
    {
        $dossier = $documentRequest->dossier;

        throw_unless($dossier instanceof Dossier, RuntimeException::class, 'Document request dossier is missing.');

        return $dossier;
    }

    private function resolveClient(Dossier $dossier): Client
    {
        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return $client;
    }
}
