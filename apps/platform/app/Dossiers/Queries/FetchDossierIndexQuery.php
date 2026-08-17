<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Clients\Models\Client;
use App\Dossiers\Data\DossierIndexRowData;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Shared\Data\PersonOptionData;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Shared\Queries\PaginatedIndexQuery;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

use function array_map;
use function in_array;
use function is_string;

/**
 * @extends PaginatedIndexQuery<Dossier>
 */
final class FetchDossierIndexQuery extends PaginatedIndexQuery
{
    public function __construct(
        private readonly FetchResponsibleStaffOptionsQuery $fetchResponsibleStaffOptions,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     client_name: string,
     *     title: string,
     *     reference: string|null,
     *     status: string,
     *     due_date: string|null,
     *     responsible_name: string|null
     * }>
     */
    public function handle(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, client_name: string, title: string, reference: string|null, status: string, due_date: string|null, responsible_name: string|null}> */
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
                        ['value' => 'draft', 'label' => __('Draft')],
                        ['value' => 'awaiting_client', 'label' => __('Awaiting client')],
                        ['value' => 'in_review', 'label' => __('In review')],
                        ['value' => 'completed', 'label' => __('Completed')],
                    ],
                ],
                ['key' => 'client', 'type' => 'search', 'label' => __('Client')],
                [
                    'key' => 'queue',
                    'type' => 'select',
                    'label' => __('Workflow queue'),
                    'options' => [
                        ['value' => 'needs_review', 'label' => __('Needs review')],
                        ['value' => 'awaiting_client', 'label' => __('Awaiting client')],
                        ['value' => 'overdue', 'label' => __('Overdue')],
                    ],
                ],
                [
                    'key' => 'responsible',
                    'type' => 'select',
                    'label' => __('Responsible'),
                    'options' => $this->responsibleOptions(),
                ],
            ],
            'sorts' => [
                ['key' => '-updated_at', 'label' => __('Recently updated')],
                ['key' => 'updated_at', 'label' => __('Oldest updated')],
                ['key' => 'title', 'label' => __('Title (A–Z)')],
                ['key' => '-title', 'label' => __('Title (Z–A)')],
                ['key' => 'status', 'label' => __('Status (A–Z)')],
                ['key' => '-created_at', 'label' => __('Newest first')],
                ['key' => 'created_at', 'label' => __('Oldest first')],
                ['key' => 'due_date', 'label' => __('Due date (soonest)')],
                ['key' => '-due_date', 'label' => __('Due date (latest)')],
            ],
            'defaults' => [
                'sort' => '-updated_at',
            ],
        ];
    }

    /**
     * @return Builder<Dossier>
     */
    protected function subject(): Builder
    {
        return Dossier::select([
            'id',
            'client_id',
            'responsible_user_id',
            'title',
            'reference',
            'status',
            'due_date',
            'created_at',
            'updated_at',
        ])->with(['client:id,name', 'responsibleUser:id,name']);
    }

    protected function allowedFilters(): array
    {
        return [
            ScoutSearchFilter::make(Dossier::class),
            AllowedFilter::exact('status'),
            AllowedFilter::partial('client', 'client.name'),
            AllowedFilter::exact('responsible', 'responsible_user_id'),
            AllowedFilter::callback('queue', function (Builder $query, mixed $value): void {
                if (! is_string($value)) {
                    return;
                }

                // Spatie's callback types the builder as Model; narrow for our Dossier query.
                /** @var Builder<Dossier> $dossierQuery */
                $dossierQuery = $query;
                $this->applyWorkflowQueue($dossierQuery, $value);
            }),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('title'),
            AllowedSort::field('status'),
            AllowedSort::field('created_at'),
            AllowedSort::field('updated_at'),
            AllowedSort::field('due_date'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-updated_at';
    }

    /**
     * Row shape is owned by DossierIndexRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var Dossier $model */
        $client = $model->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return new DossierIndexRowData(
            id: $model->id,
            clientName: $client->name,
            title: $model->title,
            reference: $model->reference,
            status: $model->status,
            dueDate: $model->due_date?->toDateString(),
            responsibleName: $model->responsibleUser?->name,
        )->toArray();
    }

    /**
     * Staff who can be set as responsible, as toolbar select options.
     *
     * Empty outside a tenant context — toolbarMetadata() is only reached from
     * tenant routes, but the query itself carries no tenant argument.
     *
     * @return list<array{value: string, label: string}>
     */
    private function responsibleOptions(): array
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        return array_map(
            static fn (PersonOptionData $staff): array => [
                'value' => (string) $staff->id,
                'label' => $staff->name,
            ],
            $this->fetchResponsibleStaffOptions->handle($tenant),
        );
    }

    /** @param Builder<Dossier> $query */
    private function applyWorkflowQueue(Builder $query, string $queue): void
    {
        if ($queue === 'needs_review') {
            $query->whereHas('documentRequests', fn (\Illuminate\Contracts\Database\Query\Builder $documentRequestQuery) => $documentRequestQuery
                ->getQuery()
                ->where('status', DocumentRequestStatus::Submitted->value));
            $query->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

            return;
        }

        if (! in_array($queue, ['awaiting_client', 'overdue'], true)) {
            return;
        }

        $query->whereHas('documentRequests', function (\Illuminate\Contracts\Database\Query\Builder $documentRequestQuery): void {
            $documentRequestQuery->getQuery()->whereIn(
                'status',
                DocumentRequestStatus::awaitingClientValues(),
            );
        });
        $query->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

        if ($queue === 'overdue') {
            $query->getQuery()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', today());
        }
    }
}
