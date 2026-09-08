<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Clients\Models\Client;
use App\Dossiers\Data\ArchivedDossierRowData;
use App\Dossiers\Models\Dossier;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;
use Spatie\QueryBuilder\AllowedSort;

/**
 * @extends PaginatedIndexQuery<Dossier>
 */
final class FetchArchivedDossiersQuery extends PaginatedIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     title: string,
     *     reference: string|null,
     *     status: string,
     *     client_name: string,
     *     client_archived: bool,
     *     archived_at: string
     * }>
     */
    public function handle(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, title: string, reference: string|null, status: string, client_name: string, client_archived: bool, archived_at: string}> */
        return $this->paginate();
    }

    public function toolbarMetadata(): array
    {
        return [
            'filters' => [
                ['key' => 'q', 'type' => 'search', 'label' => __('Search archive')],
            ],
            'sorts' => [
                ['key' => '-deleted_at', 'label' => __('Recently archived')],
                ['key' => 'deleted_at', 'label' => __('Oldest archived')],
                ['key' => 'title', 'label' => __('Title (A–Z)')],
                ['key' => '-title', 'label' => __('Title (Z–A)')],
            ],
            'defaults' => [
                'sort' => '-deleted_at',
            ],
        ];
    }

    /** @return Builder<Dossier> */
    protected function subject(): Builder
    {
        $query = Dossier::onlyTrashed();
        $query->getQuery()->select(['id', 'client_id', 'title', 'reference', 'status', 'deleted_at']);

        return $query->with([
            'client' => fn ($query) => $query
                ->withTrashed()
                ->select(['id', 'name', 'deleted_at']),
        ]);
    }

    protected function allowedFilters(): array
    {
        return [
            ScoutSearchFilter::make(Dossier::class, onlyTrashed: true),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('deleted_at'),
            AllowedSort::field('title'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-deleted_at';
    }

    /**
     * Row shape is owned by ArchivedDossierRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var Dossier $model */
        $client = $model->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Archived dossier client is missing.');

        return new ArchivedDossierRowData(
            id: $model->id,
            title: $model->title,
            reference: $model->reference,
            status: $model->status,
            clientName: $client->name,
            clientArchived: $client->trashed(),
            archivedAt: $model->deleted_at?->toIso8601String() ?? '',
        )->toArray();
    }
}
