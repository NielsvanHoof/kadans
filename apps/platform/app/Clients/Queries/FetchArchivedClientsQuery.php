<?php

declare(strict_types=1);

namespace App\Clients\Queries;

use App\Clients\Data\ArchivedClientRowData;
use App\Clients\Models\Client;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedSort;

/**
 * @extends PaginatedIndexQuery<Client>
 */
final class FetchArchivedClientsQuery extends PaginatedIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     dossiers_count: int,
     *     archived_at: string
     * }>
     */
    public function handle(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, name: string, email: string, dossiers_count: int, archived_at: string}> */
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
                ['key' => 'name', 'label' => __('Name (A–Z)')],
                ['key' => '-name', 'label' => __('Name (Z–A)')],
                ['key' => '-dossiers_count', 'label' => __('Most dossiers')],
            ],
            'defaults' => [
                'sort' => '-deleted_at',
            ],
        ];
    }

    /** @return Builder<Client> */
    protected function subject(): Builder
    {
        $query = Client::onlyTrashed();
        $query->getQuery()->select(['id', 'name', 'email', 'deleted_at']);

        return $query->withCount([
            'dossiers as dossiers_count' => fn ($query) => $query->withTrashed(),
        ]);
    }

    protected function allowedFilters(): array
    {
        return [
            ScoutSearchFilter::make(Client::class, onlyTrashed: true),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('deleted_at'),
            AllowedSort::field('name'),
            AllowedSort::field('dossiers_count'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-deleted_at';
    }

    /**
     * Row shape is owned by ArchivedClientRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var Client $model */
        return new ArchivedClientRowData(
            id: $model->id,
            name: $model->name,
            email: $model->email,
            dossiersCount: (int) ($model->dossiers_count ?? 0),
            archivedAt: $model->deleted_at?->toIso8601String() ?? '',
        )->toArray();
    }
}
