<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Platform\Models\DataSubjectRequest;
use App\Shared\Data\PersonOptionData;
use App\Shared\Queries\Filters\ScoutSearchFilter;
use App\Shared\Queries\PaginatedIndexQuery;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Throwable;

use function is_string;

/**
 * @extends PaginatedIndexQuery<Activity>
 */
final class FetchActivityLogIndexQuery extends PaginatedIndexQuery
{
    private Tenant $tenant;

    public function __construct(
        private readonly ActivityLogRowMapper $rowMapper,
        private readonly FetchResponsibleStaffOptionsQuery $fetchResponsibleStaffOptions,
    ) {}

    /**
     * @return Paginator<int, array{
     *     id: int,
     *     event: string|null,
     *     label: string,
     *     description: string,
     *     causer_name: string|null,
     *     subject: array{type: string, id: int, name: string|null}|null,
     *     created_at: string|null,
     *     properties: array{ip: string|null, route: string|null},
     *     attribute_changes: array{attributes: array<string, mixed>, old: array<string, mixed>}|null
     * }>
     */
    public function handle(Tenant $tenant): Paginator
    {
        $this->tenant = $tenant;

        /** @var Paginator<int, array{id: int, event: string|null, label: string, description: string, causer_name: string|null, subject: array{type: string, id: int, name: string|null}|null, created_at: string|null, properties: array{ip: string|null, route: string|null}, attribute_changes: array{attributes: array<string, mixed>, old: array<string, mixed>}|null}> */
        return $this->simplePaginate();
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
                ['key' => 'description', 'type' => 'search', 'label' => __('Search')],
                [
                    'key' => 'event',
                    'type' => 'select',
                    'label' => __('Event'),
                    'options' => collect(AuditEvent::cases())
                        ->map(fn (AuditEvent $event): array => [
                            'value' => $event->value,
                            'label' => $event->label(),
                            'group' => $event->group(),
                        ])
                        ->sortBy([
                            ['group', 'asc'],
                            ['label', 'asc'],
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'key' => 'causer_id',
                    'type' => 'select',
                    'label' => __('Actor'),
                    'options' => $this->actorFilterOptions(),
                ],
                [
                    'key' => 'created_from',
                    'type' => 'search',
                    'label' => __('From date'),
                ],
                [
                    'key' => 'created_to',
                    'type' => 'search',
                    'label' => __('To date'),
                ],
            ],
            'sorts' => [
                ['key' => '-created_at', 'label' => __('Newest first')],
                ['key' => 'created_at', 'label' => __('Oldest first')],
                ['key' => 'event', 'label' => __('Event (A–Z)')],
                ['key' => '-event', 'label' => __('Event (Z–A)')],
            ],
            'defaults' => [
                'sort' => '-created_at',
            ],
        ];
    }

    /**
     * @return Builder<Activity>
     */
    protected function subject(): Builder
    {
        return Activity::queryForTenant($this->tenant)
            ->with([
                'causer',
                'subject' => function ($morphTo): void {
                    if (! $morphTo instanceof MorphTo) {
                        return;
                    }

                    $morphTo->morphWith([
                        ClientAccessGrant::class => ['dossier:id,title'],
                        Client::class => [],
                        Dossier::class => [],
                        DocumentRequest::class => ['dossier:id,title'],
                        UploadedDocument::class => [],
                        DataSubjectRequest::class => ['client:id,name'],
                    ]);
                },
            ]);
    }

    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('event'),
            AllowedFilter::exact('causer_id'),
            ScoutSearchFilter::make(Activity::class, 'description', wheres: [
                'tenant_id' => $this->tenant->id,
            ]),
            AllowedFilter::callback('created_from', function (Builder $query, mixed $value): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    $date = Date::parse($value)->startOfDay();
                } catch (Throwable) {
                    // Surface a validation error so the UI does not keep a
                    // filter chip while returning an unfiltered result set.
                    throw ValidationException::withMessages([
                        'filter.created_from' => __('Enter a valid from date.'),
                    ]);
                }

                $query->getQuery()->where('created_at', '>=', $date);
            }),
            AllowedFilter::callback('created_to', function (Builder $query, mixed $value): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    $date = Date::parse($value)->endOfDay();
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'filter.created_to' => __('Enter a valid to date.'),
                    ]);
                }

                $query->getQuery()->where('created_at', '<=', $date);
            }),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('created_at'),
            AllowedSort::field('event'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /**
     * Row shape is owned by ActivityLogRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var Activity $model */
        return $this->rowMapper->map($model)->toArray();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function actorFilterOptions(): array
    {
        if (! isset($this->tenant)) {
            return [];
        }

        return array_map(
            static fn (PersonOptionData $staff): array => [
                'value' => (string) $staff->id,
                'label' => $staff->name,
            ],
            $this->fetchResponsibleStaffOptions->handle($this->tenant),
        );
    }
}
